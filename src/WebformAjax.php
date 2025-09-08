<?php

/**
 * @file
 * Front-end form ajax handler.
 */

namespace Drupal\webform_civicrm;

use Drupal\webform\WebformInterface;
use Drupal\Component\Utility\Xss;
use Drupal\webform\Entity\Webform;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\webform_civicrm\WebformCivicrmBase;

/**
 * Class WebformAjax
 */
class WebformAjax extends WebformCivicrmBase implements WebformAjaxInterface {

  private $requestStack;

  /**
   * @var \Drupal\webform_civicrm\UtilsInterface
   */
  protected $utils;

  function __construct(RequestStack $requestStack, UtilsInterface $utils) {
    $this->requestStack = $requestStack;
    $this->utils = $utils;
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

    $contactComponent = \Drupal::service('webform_civicrm.contact_component');

    if (empty($this->getParameter('str')) && (empty($this->getParameter('load')) || empty($this->getParameter('cid')))) {
      throw new AccessDeniedHttpException('Invalid parameters.');
    }
    $webform = Webform::load($webformId);
    if (!$webform instanceof WebformInterface) {
      throw new AccessDeniedHttpException('Invalid form.');
    }
    $this->node = $webform;
    $this->settings = $webform->getHandler('webform_civicrm')->getConfiguration()['settings'];
    $this->data = $this->settings['data'];
    $element = $webform->getElement($fid);
    if (!$this->autocompleteAccess($webform, $fid)) {
     throw new AccessDeniedHttpException('Access not allowed');
    }

    $filters = $contactComponent->wf_crm_search_filters($webform, $element);
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
        return $contactComponent->wf_crm_contact_search($webform, $element, $filters, $this->ent['contact'], $str);
      }
      exit();
    }
    // Load contact by id
    $data = [] ;
    if ($name = $contactComponent->wf_crm_contact_access($element, $filters, $this->getParameter('cid'))) {
      if ($this->getParameter('load') == 'name') {
        if ($this->getParameter('cid')[0] === '-') {
          // HTML hack to get prompt to show up different than search results
          $data = Xss::filter($element['#none_prompt']);
        }
        else {
          $data = $name;
        }
      }
      // Fetch entire contact to populate form via ajax
      if ($this->getParameter('load') == 'full') {
        $sp = \CRM_Core_DAO::VALUE_SEPARATOR;
        $utils = \Drupal::service('webform_civicrm.utils');
        $this->enabled = $utils->wf_crm_enabled_fields($webform);
        list(, $c, ) = explode('_', $element['#form_key'], 3);
        $this->ent['contact'][$c]['id'] = (int) $_GET['cid'];
        // Redact fields if they are to be hidden unconditionally, otherwise they are needed on the client side
        $to_hide = [];
        if (!empty($element['#hide_fields']) && (wf_crm_aval($element, '#hide_method', 'hide') == 'hide' && !wf_crm_aval($element, '#no_hide_blank'))) {
          $to_hide = $element['#hide_fields'];
        }
        $contact = $this->loadContact($c, $to_hide);
        $states = $countries = [];
        // Format as json array
        foreach ($this->enabled as $fid => $f) {
          list(, $i, $ent, $n, $table, $field) = explode('_', $fid, 6);
          if ($i == $c && $ent == 'contact' && isset($contact[$table][$n][$field])) {
            $type = ($table == 'contact' && strpos($field, 'name')) ? 'name' : $table;
            // Exclude blank and hidden fields
            if ($contact[$table][$n][$field] !== '' && $contact[$table][$n][$field] !== [] && !in_array($type, $to_hide)) {
              $dataType = wf_crm_aval($utils->wf_crm_get_field("{$table}_$field"), 'data_type');
              $val = ['val' => $contact[$table][$n][$field]];
              // Retrieve file info
              if ($dataType === 'File') {
                $val = $this->getFileInfo($field, $val['val'], $ent, $n);
              }
              // Explode multivalue strings
              elseif (is_string($val['val']) && strpos($val['val'], $sp) !== FALSE) {
                $val['val'] = $utils->wf_crm_explode_multivalue_str($val['val']);
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
          elseif ($i == 1 && strpos($field, 'billing_address_') !== false && isset($contact['contact'][$n]['contact_id'])) {
            $billingAddress = $this->loadBillingAddress($contact['contact'][$n]['contact_id']);
            if (isset($billingAddress[$field])) {
              $data[] = [
                'val' => $billingAddress[$field],
                'fid' => $fid,
              ];
            }
          }
          // Populate related contacts
          elseif ($i > $c && $field == 'existing') {
            $related_component = $this->getComponent($fid);
            if (isset($related_component['#default']) && $related_component['#default'] == 'relationship') {
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
                  'display' => $contactComponent->wf_crm_contact_access($related_component, $contactComponent->wf_crm_search_filters($this->node, $related_component), $related_cid),
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
  public function autocompleteAccess($webform, $fid) {
    $user =  \Drupal::currentUser() ;
    if (!$fid || empty($webform->getHandler('webform_civicrm'))) {
      return FALSE;
    }
    $element = $webform->getElement($fid);
    if (empty($element) || !$webform->access('submission_create')) {
      return FALSE;
    }

    if ($user->id() === 1 || $user->hasPermission('access all webform results') || ($user->hasPermission('access own webform results') && $webform->uuid() == $user->id())) {
      return TRUE;
    };

    /** @var \Drupal\webform\Plugin\WebformElementManagerInterface $element_manager */
    $element_manager = \Drupal::service('plugin.manager.webform.element');
    $element_instance = $element_manager->getElementInstance($element);
    // @todo test after moving to getElementProperty.
    if (!empty($element_instance->getElementProperty($element, 'private'))) {
      return FALSE;
    }
    /* TODO figure out what this means in Drupal 8
    if (\Drupal::state()->get('webform_submission_access_control', 1)) {
      $allowed_roles = array();
      foreach ($node->webform['roles'] as $rid) {
        $allowed_roles[$rid] = isset($user->roles[$rid]) ? TRUE : FALSE;
      }
      if (array_search(TRUE, $allowed_roles) === FALSE) {
        return FALSE;
      }
    }*/
    // ToDo - to be refactored -> it would be safer to return FALSE by default.
    return TRUE;
  }

}
