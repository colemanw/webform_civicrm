<?php

/**
 * @file
 * Front-end form ajax handler.
 */

use Drupal\webform_civicrm\Utils;
use Drupal\Component\Utility\Xss;
use Drupal\node\Entity\Node;
use Drupal\webform\Entity\Webform;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

module_load_include('inc', 'webform_civicrm', 'includes/wf_crm_webform_base');

class wf_crm_webform_ajax extends wf_crm_webform_base {

  private $requestStack;

  function __construct(RequestStack $requestStack) {
    $this->requestStack = $requestStack;
  }

  /**
   * Replacement of the reading of $_GET
   * @param $name
   *
   * @return mixed
   */
  private function getParameter($name){
    return $this->requestStack->getCurrentRequest()->query->get($name);
  }

  /**
   * Load one or more contacts via ajax
   * @param $webformId
   * @param $fid
   */
  function contactAjax($webformId, $fid) {

    module_load_include('inc', 'webform_civicrm', 'includes/contact_component');
    if (empty($this->getParameter('str')) && (empty($this->getParameter('load')) || empty($this->getParameter('cid')))) {
      throw new AccessDeniedHttpException('Invalid parameters.');
    }
    $webform = Webform::load($webformId);
    if (!$webform instanceof \Drupal\webform\WebformInterface) {
      throw new AccessDeniedHttpException('Invalid form.');
    }
    $this->node = $webform;
    $this->settings = $webform->getHandler('webform_civicrm')->getConfiguration()['settings'];
    $this->data = $this->settings['data'];
    $element = $webform->getElement($fid);
    if (!self::autocompleteAccess($webform, $fid)) {
     throw new AccessDeniedHttpException('Access not allowed');
    }

    $filters = wf_crm_search_filters($webform, $element);
    // Populate other contact ids for related data
    $this->ent += ['contact' => []];
    $query_params = $this->requestStack->getCurrentRequest()->query->all();
    foreach ($query_params as $k => $v) {
      if (substr($k, 0, 3) == 'cid' && $v && is_numeric($v)) {
        $this->ent['contact'][substr($k, 3)]['id'] = (int) $v;
      }
    }
    // Bypass filters when choosing contact on component edit form
    // TODO do something about the undefined function wf_crm_admin_access
    if (!empty($this->getParameter('admin')) && wf_crm_admin_access($this->node)) {
      $filters = [
        'check_permissions' => 1,
        'is_deleted' => 0,
        'contact_type' => $filters['contact_type'],
      ];
      $component['extra']['allow_create'] = 0;
    }
    // Autocomplete contact names
    if (!empty($this->getParameter('str'))) {
      if ($str = trim($this->getParameter('str'))) {
        return wf_crm_contact_search($webform, $element, $filters, $this->ent['contact'], $str);
      }
      exit();
    }
    // Load contact by id
    $data = [] ;
    if ($name = wf_crm_contact_access($element, $filters, $this->getParameter('cid'))) {
      if ($this->getParameter('load') == 'name') {
        if ($this->getParameter('cid')[0] === '-') {
          // HTML hack to get prompt to show up different than search results
          $data = '<em><i>' . Xss::filter($element['#none_prompt']) . '</i></em>';
        }
        else {
          $data = $name;
        }
      }
      // Fetch entire contact to populate form via ajax
      if ($this->getParameter('load') == 'full') {
        $sp = CRM_Core_DAO::VALUE_SEPARATOR;
        $this->enabled = wf_crm_enabled_fields($webform);
        list(, $c, ) = explode('_', $element['#form_key'], 3);
        $this->ent['contact'][$c]['id'] = (int) $_GET['cid'];
        // Redact fields if they are to be hidden unconditionally, otherwise they are needed on the client side
        $to_hide = (wf_crm_aval($element, '#hide_method', 'hide') == 'hide' && !wf_crm_aval($element, '#no_hide_blank')) ? $component['extra']['hide_fields'] : array();
        $contact = $this->loadContact($c, $to_hide);
        $states = $countries = [];
        // Format as json array
        foreach ($this->enabled as $fid => $f) {
          list(, $i, $ent, $n, $table, $field) = explode('_', $fid, 6);
          if ($i == $c && $ent == 'contact' && isset($contact[$table][$n][$field])) {
            $type = ($table == 'contact' && strpos($field, 'name')) ? 'name' : $table;
            // Exclude blank and hidden fields
            if ($contact[$table][$n][$field] !== '' && $contact[$table][$n][$field] !== array() && !in_array($type, $to_hide)) {
              $dataType = wf_crm_aval(wf_crm_get_field("{$table}_$field"), 'data_type');
              $val = ['val' => $contact[$table][$n][$field]];
              // Retrieve file info
              if ($dataType === 'File') {
                $val = $this->getFileInfo($field, $val['val'], $ent, $n);
              }
              // Explode multivalue strings
              elseif (is_string($val['val']) && strpos($val['val'], $sp) !== FALSE) {
                $val['val'] = wf_crm_explode_multivalue_str($val['val']);
              }
              $val['fid'] = $fid;
              if ($dataType) {
                $val['data_type'] = $dataType;
              }
              if ($field == 'state_province_id') {
                $states[] = $val;
              }
              elseif ($field == 'country_id') {
                $countries[] = $val;
              }
              else {
                $data[] = $val;
              }
            }
          }
          // Populate related contacts
          elseif ($c == 1 && $i > $c && $field == 'existing') {
            $related_component = $this->getComponent($fid);
            if (wf_crm_aval($related_component['extra'], 'default') == 'relationship') {
              $old_related_cid = wf_crm_aval($this->ent, "contact:$i:id");
              // Don't be fooled by old data
              $related_component['extra']['allow_url_autofill'] = FALSE;
              unset($this->ent['contact'][$i]);
              $this->findContact($related_component);
              $related_cid = wf_crm_aval($this->ent, "contact:$i:id");
              if ($related_cid && $related_cid != $old_related_cid) {
                $data[] = [
                  'fid' => $fid,
                  'val' => $related_cid,
                  'display' => wf_crm_contact_access($related_component, wf_crm_search_filters($this->node, $related_component), $related_cid),
                ];
              }
            }
          }
        }
        // We want counties, states and countries in that order to avoid race-conditions client-side
        $data = array_merge($data, $states, $countries);
      }
    }
    return $data;
  }

  /**
   * Access callback. Check if user has permission to view autocomplete results.
   *
   * @param Webform $webform
   * @param string $fid
   *   Webform component id
   *
   * @return bool
   */
  public static function autocompleteAccess($webform, $fid) {
    $user =  \Drupal::currentUser() ;
    if (!$fid || empty($webform->getHandler('webform_civicrm'))) {
      return FALSE;
    }
    $element = $webform->getElement($fid);
    if (empty($element) || !$webform->access('view')) {
      return FALSE;
    }

    if ($user->id() === 1 || $user->hasPermission('access all webform results') || ($user->hasPermission('access own webform results') && $webform->uuid() == $user->id())) {
      return TRUE;
    };

    /** @var \Drupal\webform\Plugin\WebformElementManagerInterface $element_manager */
    $element_manager = \Drupal::service('plugin.manager.webform.element');
    $element_instance = $element_manager->getElementInstance($component);
    // @todo test after moving to getElementProperty.
    if (!empty($element_instance->getElementProperty($component, 'private'))) {
      return FALSE;
    }
    /* TODO figure out what this means in Drupal 8
    if (variable_get('webform_submission_access_control', 1)) {
      $allowed_roles = array();
      foreach ($node->webform['roles'] as $rid) {
        $allowed_roles[$rid] = isset($user->roles[$rid]) ? TRUE : FALSE;
      }
      if (array_search(TRUE, $allowed_roles) === FALSE) {
        return FALSE;
      }
    }*/
    return TRUE;
  }

}
