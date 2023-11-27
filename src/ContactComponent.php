<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;

/**
 * Class ContactComponent
 *
 * CiviCRM contact webform component.
 */
class ContactComponent implements ContactComponentInterface {

  /**
   * UtilsInterface object
   */
  protected $utils;

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
    $fieldMappings = [
      'current_employer' => ['employer_id', 'display_name'],
      'email' => ['email', 'email'],
      'phone' => ['phone', 'phone'],
      'city' => ['address', 'city'],
      'state_province' => ['address', 'state_province_id:label'],
      'country' => ['address', 'country_id:label'],
      'county' => ['address', 'county_id:label'],
      'postal_code' => ['address', 'postal_code'],
    ];
    $joinedTables = [];
    foreach ($fieldMappings as $field => $type) {
      if ($key = array_search($field, $display_fields)) {
        unset($display_fields[$key]);
        [$table, $fieldName]= $type;
        $display_fields[] = "{$table}.{$fieldName}";
        if (empty($joinedTables[$table]) && in_array($table, ['email', 'phone', 'address'])) {
          $joinedTables[$table] = TRUE;
          $params['join'][] = [ucfirst($table) . " AS {$table}", 'LEFT', ["{$table}.is_primary", '=', 1]];
        }
      }
    }
    $sort_field = 'sort_name';
    // Search and sort based on the selected display field
    if (!in_array('display_name', $display_fields)) {
      $sort_field = $display_fields[0];
    }
    $params += [
      'limit' => $limit,
      'orderBy' => [
        $sort_field => 'ASC',
      ],
      'select' => $display_fields,
    ];
    if (!empty($params['relationship']['contact'])) {
      $c = $params['relationship']['contact'];
      $relations = NULL;
      if (!empty($contacts[$c]['id'])) {
        $relations = $this->wf_crm_find_relations($contacts[$c]['id'], wf_crm_aval($params['relationship'], 'types'));
        $params['where'][] = ['id', 'IN', $relations];
      }
      if (!$relations) {
        return $ret;
      }
    }
    unset($params['relationship']);
    if ($str) {
      $searchFields = [];
      foreach ($display_fields as $fld) {
        $searchFields[] = [$fld, 'CONTAINS', $str];
      }
      $params['where'][] = ['OR', $searchFields];
    }
    $result = $this->utils->wf_civicrm_api4('Contact', 'get', $params);
    // Autocomplete results
    if ($str) {
      foreach ($result as $contact) {
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
      foreach ($result as $contact) {
        // Select lists will be escaped by FAPI
        if ($name = $this->wf_crm_format_contact($contact, $display_fields, FALSE)) {
          $ret[$contact['id']] = $name;
        }
      }
      // If we get exactly $limit results, there are probably more - warn that the list is truncated
      if (wf_crm_aval($result, 'rowCount') >= $limit) {
        \Drupal::logger('webform_civicrm')->warning(
          'Maximum contacts exceeded, list truncated on the webform "@title". The webform_civicrm "@field" field cannot display more than @limit contacts because it is a select list. Recommend switching to autocomplete widget in element settings.',
          ['@limit' => $limit, '@field' => $element['#title'], '@title' => $node->label()]);
        if ($node->access('update') && \Drupal::currentUser()->hasPermission('access CiviCRM')) {
          $warning_message = Markup::create('<strong>' . t('Maximum contacts exceeded, list truncated.') .'</strong><br>' .
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
    // Remove unnecessary param as api v4 does not accept them.
    unset($filters['relationship']);
    $filters['where'][] = ['id', '=', $cid];
    $filters['where'][] = ['is_deleted', '=', 0];
    // A contact always has permission to view self
    if ($cid == $this->utils->wf_crm_user_cid()) {
      $filters['checkPermissions'] = FALSE;
    }
    // If checksum is included in the URL, bypass the permission.
    $checksumValid = $this->utils->checksumUserAccess($c, $cid);
    if (!empty($filters['checkPermissions']) && $checksumValid) {
      $filters['checkPermissions'] = FALSE;
    }
    // Fetch contact name with filters applied
    $result = $this->utils->wf_civicrm_api4('Contact', 'get', $filters)[0] ?? [];
    return $this->wf_crm_format_contact($result, /*$component['#extra']['results_display']*/ ['display_name']);
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
          $employer = $this->utils->wf_civicrm_api4('Contact', 'get', [
            'where' => [
              [$search_key, '=', $cid],
            ],
          ])->first()[$a == 'b' ? 'employer_id' : 'id'] ?? NULL;
          if ($employer) {
            $found[$employer] = $employer;
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
    $contactFilters = [
      'contact_type',
      'contact_sub_type',
      'tag',
      'group',
      'relationship' => [
        'contact',
        'types',
      ],
    ];
    $params = [
      'checkPermissions' => $contact_element->getElementProperty($component, 'check_permissions')
    ];
    $params['where'][] = ['is_deleted', '=', 0];
    foreach ($contactFilters as $key => $filter) {
      // Add Relationship filter
      if ($key === 'relationship') {
        foreach ($filter as $val) {
          $filterVal = $contact_element->getElementProperty($component, "filter_relationship_{$val}");
          $this->wf_crm_search_filterArray($filterVal);
          if ($filterVal) {
            $params['relationship'][$val] = $filterVal;
          }
        }
      }
      else {
        $filterVal = $contact_element->getElementProperty($component, $filter);
        $this->wf_crm_search_filterArray($filterVal);
        if ($filterVal) {
          switch ($filter) {
            case 'group':
            case 'tag':
              $filter .= 's';
              $op = 'IN';
              break;

            case 'contact_sub_type':
              $op = 'CONTAINS';
              break;

            default:
              $op = '=';
          }
          $params['where'][] = [$filter, $op, $filterVal];
        }
      }
    }
    return $params;
  }

  /**
   * Remove blank values in the array.
   */
  function wf_crm_search_filterArray(&$filterVal) {
    if (is_array($filterVal)) {
      $filterVal = array_filter($filterVal);
    }
  }

}
