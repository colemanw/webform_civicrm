<?php

namespace Drupal\webform_civicrm;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;

/**
 * Class ContactComponent
 *
 * CiviCRM contact webform component.
 */
class ContactComponent implements ContactComponentInterface {

  public function __construct(UtilsInterface $utils) {
    $this->utils = $utils;
  }

  /**
   * Implements _webform_display_component().
   */
  function _webform_display_civicrm_contact($component, $value, $format = 'html') {
    $display = empty($value[0]) ? '' : $this->utils->wf_crm_display_name($value[0]);
    if ($format == 'html' && $display && user_access('access CiviCRM')) {
      $display = l($display, 'civicrm/contact/view', [
        'alias' => TRUE,
        'query' => [
          'reset' => 1,
          'cid' => $value[0],
        ],
      ]);
    }
    return [
      '#title' => $component['name'],
      '#weight' => $component['weight'],
      '#theme' => 'display_civicrm_contact',
      '#theme_wrappers' => $format == 'html' ? ['webform_element'] : ['webform_element_text'],
      '#field_prefix' => '',
      '#field_suffix' => '',
      '#format' => $format,
      '#value' => $display,
      '#translatable' => ['title'],
    ];
  }

  /**
   * Implements _webform_table_component().
   */
  function _webform_table_civicrm_contact($component, $value) {
    return empty($value[0]) ? '' : Html::escape($this->utils->wf_crm_display_name($value[0]));
  }

  /**
   * Implements _webform_csv_headers_component().
   */
  function _webform_csv_headers_civicrm_contact($component, $export_options) {
    $header = [];
    $header[0] = '';
    $header[1] = '';
    $header[2] = $component['name'];
    return $header;
  }

  /**
   * Implements _webform_csv_data_component().
   */
  function _webform_csv_data_civicrm_contact($component, $export_options, $value) {
    return empty($value[0]) ? '' : $this->utils->wf_crm_display_name($value[0]);
  }

  /**
   * Returns a list of contacts based on component settings.
   *
   * @param \Drupal\webform\WebformInterface $node
   *   Node object
   * @param array $element
   *   Webform element
   * @param array $params
   *   Contact get params (filters)
   * @param array $contacts
   *   Existing contact data
   * @param string $str
   *   Search string (used during autocomplete)
   *
   * @return array
   */
  function wf_crm_contact_search($node, $element, $params, $contacts, $str = NULL) {
  //  TODO in Drupal8 now node is a webform - maybe we could check here if it has  CiviCRM handler
  //  if (empty($node->webform_civicrm)) {
  //    return array();
  //  }
    $limit = $str ? 12 : 500;
    $ret = [];
    $display_fields = array_values($element['#results_display']);
    $search_field = 'display_name';
    $sort_field = 'sort_name';
    // Search and sort based on the selected display field
    if (!in_array('display_name', $display_fields)) {
      $search_field = $sort_field = $display_fields[0];
    }
    $params += [
      'rowCount' => $limit,
      'sort' => $sort_field,
      'return' => $display_fields,
    ];
    if (!empty($params['relationship']['contact'])) {
      $c = $params['relationship']['contact'];
      $relations = NULL;
      if (!empty($contacts[$c]['id'])) {
        $relations = $this->wf_crm_find_relations($contacts[$c]['id'], wf_crm_aval($params['relationship'], 'types'));
        $params['id'] = ['IN' => $relations];
      }
      if (!$relations) {
        return $ret;
      }
    }
    unset($params['relationship']);
    if ($str) {
      $str = str_replace(' ', '%', \CRM_Utils_Type::escape($str, 'String'));
      // The contact api takes a quirky format for display_name and sort_name
      if (in_array($search_field, ['sort_name', 'display_name'])) {
        $params[$search_field] = $str;
      }
      // Others use the standard convention
      else {
        $params[$search_field] = ['LIKE' => "%$str%"];
      }
    }
    $result = $this->utils->wf_civicrm_api('contact', 'get', $params);
    // Autocomplete results
    if ($str) {
      foreach (wf_crm_aval($result, 'values', []) as $contact) {
        if ($name = $this->wf_crm_format_contact($contact, $display_fields)) {
          $ret[] = ['id' => $contact['id'], 'name' => $name];
        }
      }
      if (count($ret) < $limit && $element['#allow_create']) {
        // HTML hack to get prompt to show up different than search results
        $ret[] = ['id' => "-$str", 'name' => Xss::filter($element['#none_prompt'])];
      }
    }
    // Select results
    else {
      if (!empty($element['#allow_create'])) {
        $ret['-'] = Xss::filter($element['#none_prompt']);
      }
      foreach (wf_crm_aval($result, 'values', []) as $contact) {
        // Select lists will be escaped by FAPI
        if ($name = $this->wf_crm_format_contact($contact, $display_fields, FALSE)) {
          $ret[$contact['id']] = $name;
        }
      }
      // If we get exactly $limit results, there are probably more - warn that the list is truncated
      if (wf_crm_aval($result, 'count') >= $limit) {
        \Drupal::logger('webform_civicrm')->warning(
          'Maximum contacts exceeded, list truncated on the webform "@title". The webform_civicrm "@field" field cannot display more than @limit contacts because it is a select list. Recommend switching to autocomplete widget in element settings.',
          ['@limit' => $limit, '@field' => $element['#title'], '@title' => $node->label()]);
        if ($node->access('update') && \Drupal::currentUser()->hasPermission('access CiviCRM')) {
          $warning_message = \Drupal\Core\Render\Markup::create('<strong>' . t('Maximum contacts exceeded, list truncated.') .'</strong><br>' .
          t('The field "@field" cannot show more than @limit contacts because it is a select list. Recommend switching to autocomplete widget.', ['@limit' => $limit, '@field' => $element['#title']]));
          \Drupal::messenger()->addMessage($warning_message);
        }
      }
    }
    return $ret;
  }

  /**
   * Load contact name if user has permission. Else return FALSE.
   *
   * @param $component
   *   Webform component of type 'civicrm_contact'
   * @param $filters
   *   Contact get params
   * @param $cid
   *   Contact id
   *
   * @return bool|string
   */
  function wf_crm_contact_access($component, $filters, $cid) {
    // Create new contact doesn't require lookup
    $cid = (string) $cid;
    $component['#form_key'] = $component['#form_key'] ?? $component['#webform_key'];
    list(, $c, ) = explode('_', $component['#form_key'], 3);
    if (!empty($component['#none_prompt']) && !empty($component['#allow_create']) && $cid && strpos($cid, '-') === 0) {
      return Html::escape($component['#none_prompt']);
    }
    if (!is_numeric($cid)) {
      return FALSE;
    }
    $filters['id'] = $cid;
    $filters['is_deleted'] = 0;
    // A contact always has permission to view self
    if ($cid == $this->utils->wf_crm_user_cid()) {
      $filters['check_permissions'] = FALSE;
    }
    if (!empty($filters['check_permissions'])) {
      // If we have a valid checksum for this contact, bypass other permission checks
      // For legacy reasons we support "cid" param as an alias of "cid1"
      // ToDo use: \Drupal::request()->query->all();
      if (wf_crm_aval($_GET, "cid$c") == $cid || ($c == 1 && wf_crm_aval($_GET, "cid") == $cid)) {
        // For legacy reasons we support "cs" param as an alias of "cs1"
        if (!empty($_GET['cs']) && $c == 1 && \CRM_Contact_BAO_Contact_Utils::validChecksum($cid, $_GET['cs'])) {
          $filters['check_permissions'] = FALSE;
        }
        elseif (!empty($_GET["cs$c"]) && \CRM_Contact_BAO_Contact_Utils::validChecksum($cid, $_GET["cs$c"])) {
          $filters['check_permissions'] = FALSE;
        }
      }
    }
    // Fetch contact name with filters applied
    $result = $this->utils->wf_civicrm_api('contact', 'get', $filters);
    return $this->wf_crm_format_contact(wf_crm_aval($result, "values:$cid"), /*$component['#extra']['results_display']*/ ['display_name']);
  }

  /**
   * Display a contact based on chosen fields
   *
   * @param array $contact
   * @param array $display_fields
   * @param bool $escape
   * @return bool|string
   */
  function wf_crm_format_contact($contact, $display_fields, $escape = TRUE) {
    if (!$contact) {
      return FALSE;
    }
    $display = [];
    foreach ($display_fields as $field) {
      if ($field && !empty($contact[$field])) {
        $display[] = $escape ? Html::escape($contact[$field]) : $contact[$field];
      }
    }
    return implode(' :: ', $display);
  }

  /**
   * Get a contact's relations of certain types
   *
   * @param int cid
   *   Contact id
   * @param array types
   *   Array of relationship_type_ids
   * @param bool $current
   *   Limit to current & enabled relations?
   *
   * @return array
   */
  function wf_crm_find_relations($cid, $types = [], $current = TRUE) {
    $found = $allowed = $type_ids = [];
    $cid = (int) $cid;
    static $employer_type = 0;
    if ($cid) {
      if (!$employer_type && $current) {
        $employer_type = \CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_relationship_type WHERE name_a_b = 'Employee of'");
      }
      foreach ($types as $t) {
        list($type, $a) = explode('_', $t);
        // Put current employer first in the list
        if ($type == $employer_type && $current) {
          $search_key = $a == 'b' ? 'id' : 'employer_id';
          // Note: inconsistency in api3 - search key is "employer_id" but return key is "current_employer_id"
          $employer = $this->utils->wf_crm_apivalues('contact', 'get', [
            $search_key => $cid,
            'sequential' => 1,
          ], $a == 'b' ? 'current_employer_id' : 'id');
          if ($employer) {
            $found[$employer[0]] = $employer[0];
          }
        }
        $type_ids[] = $type;
        if ($a == 'a' || $a == 'r') {
          $allowed[] = $type . '_a';
        }
        if ($a == 'b' || $a == 'r') {
          $allowed[] = $type . '_b';
        }
      }
      $params = [
        'return' => ['contact_id_a', 'contact_id_b', 'relationship_type_id', 'end_date'],
        'contact_id_a' => $cid,
        'contact_id_b' => $cid,
        'options' => ['or' => [['contact_id_a', 'contact_id_b']]],
      ];
      if ($type_ids) {
        $params['relationship_type_id'] = ['IN' => $type_ids];
      }
      if ($current) {
        $params['is_active'] = 1;
      }
      foreach ($this->utils->wf_crm_apivalues('relationship', 'get', $params) as $relationship) {
        $a = $relationship['contact_id_a'] == $cid ? 'b' : 'a';
        if (!$current || empty($relationship['end_date']) || strtotime($relationship['end_date']) > time()) {
          if (!$allowed || in_array($relationship['relationship_type_id'] . '_' . $a, $allowed)) {
            $c = $relationship["contact_id_$a"];
            $found[$c] = $c;
          }
        }
      }
    }
    return $found;
  }

  /**
   * Format filters for the contact get api
   *
   * @param \Drupal\webform\WebformInterface $node
   *   Webform node object
   * @param array $component
   *   Webform component of type 'civicrm_contact'
   *
   * @return array
   *   Api params
   */
  function wf_crm_search_filters($node, array $component) {
    /** @var \Drupal\webform\Plugin\WebformElementManagerInterface $element_manager */
    $element_manager = \Drupal::service('plugin.manager.webform.element');
    $contact_element = $element_manager->getElementInstance($component);
    $params = ['is_deleted' => 0];
    $contactFilters = [
      'contact_type',
      'contact_sub_type',
      'tag',
      'group',
      'check_permissions',
      'relationship' => [
        'contact',
        'types',
      ],
    ];
    foreach ($contactFilters as $key => $filter) {
      // Add Relationship filter
      if ($key === 'relationship') {
        foreach ($filter as $val) {
          $filterVal = $contact_element->getElementProperty($component, "filter_relationship_{$val}");
          if ($filterVal) {
            $params['relationship'][$val] = $filterVal;
          }
        }
      }
      else {
        $params[$filter] = $contact_element->getElementProperty($component, $filter);
      }
    }
    return $params;
  }

}
