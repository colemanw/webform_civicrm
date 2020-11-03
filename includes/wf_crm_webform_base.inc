<?php

/**
 * @file
 * Front-end form handler base class.
 */

module_load_include('inc', 'webform_civicrm', 'includes/utils');

/**
 * Class wf_crm_webform_base
 *
 * @property array $payment_processor
 * @property array $contribution_page
 * @property number $tax_rate
 * @property number $civicrm_version
 */
abstract class wf_crm_webform_base {
  protected $node;
  protected $settings = [];
  protected $enabled = [];
  protected $data = [];
  protected $ent = [];
  protected $events = [];
  protected $line_items = [];
  protected $membership_types = [];
  protected $loadedContacts = [];

  // No direct access - storage for variables fetched via __get
  private $_payment_processor;
  private $_contribution_page;
  // tax integration
  private $_tax_rate;

  const
    MULTIVALUE_FIELDSET_MODE_CREATE_OR_EDIT = 0,
    MULTIVALUE_FIELDSET_MODE_CREATE_ONLY = 1;

  /**
   * Magic method to retrieve otherwise inaccessible properties
   * @param $name
   * @throws Exception
   * @return mixed
   */
  function __get($name) {
    switch ($name) {
      case 'payment_processor':
        $this->fixPaymentProcessorID();
        $payment_processor_id = wf_crm_aval($this->data, 'contribution:1:contribution:1:payment_processor_id');
        if ($payment_processor_id && !$this->_payment_processor) {
          $this->_payment_processor = wf_civicrm_api('payment_processor', 'getsingle', ['id' => $payment_processor_id]);
        }
        return $this->_payment_processor;

      case 'contribution_page':
        $contribution_page_id = wf_crm_aval($this->data, 'contribution:1:contribution:1:contribution_page_id');
        if ($contribution_page_id && !$this->_contribution_page) {
          $this->_contribution_page = wf_civicrm_api('contribution_page', 'getsingle', ['id' => $contribution_page_id]);
        }
        return $this->_contribution_page;

      case 'tax_rate':
        $taxSettings = wf_crm_get_civi_setting('contribution_invoice_settings');
        if (is_array($taxSettings) && !empty($taxSettings['invoicing'])) {
          if ($this->contribution_page) {
            // tax integration
            $taxRates = CRM_Core_PseudoConstant::getTaxRates();
            $this->_tax_rate = isset($taxRates[$this->_contribution_page['financial_type_id']]) ? $taxRates[$this->_contribution_page['financial_type_id']] : NULL;
          }
          return $this->_tax_rate;
        }
        return NULL;

      case 'civicrm_version':
        return CRM_Utils_System::version();

      default:
        throw new Exception('Unknown property');
    }
  }

  /**
   * Fetch all relevant data for a given contact
   * Used to load contacts for pre-filling a webform, and also to fill in a contact via ajax
   *
   * @param int $c
   *   Contact #
   * @param array $exclude
   *   Fields to ignore
   *
   * @return array
   *   Contact data
   */
  protected function loadContact($c, $exclude = []) {
    if (!empty($this->loadedContacts[$c])) {
      return $this->loadedContacts[$c];
    }
    $info = [];
    $cid = $this->ent['contact'][$c]['id'];
    if (!$cid) {
      return $info;
    }
    $contact = $this->data['contact'][$c];
    $prefix = 'civicrm_' . $c . '_contact_1_';
    $existing_contact_field = $this->getComponent($prefix . 'contact_existing');
    $exclude = array_merge($exclude, wf_crm_aval($existing_contact_field['extra'], 'no_autofill', []));
    foreach (array_merge(['contact'], wf_crm_location_fields()) as $ent) {
      if ((!empty($contact['number_of_' . $ent]) && !in_array($ent, $exclude)) || $ent == 'contact') {
        $params = ['contact_id' => $cid];
        if ($ent != 'contact' && $ent != 'website') {
          $params['options']['sort'] = 'is_primary DESC';
        }
        $result = wf_civicrm_api($ent, 'get', $params);
        // Handle location field sorting
        if (in_array($ent, wf_crm_location_fields())) {
          $result['values'] = $this->reorderByLocationType($c, $ent, $result['values']);
        }
        if (!empty($result['values'])) {
          // Index array from 1 instead of 0
          $result = array_merge([0], array_values($result['values']));
          unset($result[0]);
          if ($ent == 'contact') {
            // Exclude name fields
            if (in_array('name', $exclude)) {
              unset($result[1]['first_name'], $result[1]['middle_name'], $result[1]['last_name'],
                $result[1]['formal_title'], $result[1]['prefix_id'], $result[1]['suffix_id'],
                $result[1]['nick_name'], $result[1]['organization_name'], $result[1]['household_name']
              );
            }
            // Privacy fields
            if (isset($this->enabled[$prefix . 'contact_privacy'])) {
              foreach (array_keys(wf_crm_get_privacy_options()) as $key) {
                if (!empty($result[1][$key])) {
                  $result[1]['privacy'][] = $key;
                }
              }
            }
            // User id
            if (isset($this->enabled[$prefix . 'contact_user_id'])) {
              $result[1]['user_id'] = wf_crm_user_cid($cid, 'contact');
            }
            // Hack for gender as textfield. More general solution needed for all pseudoconsant fields
            $gender_field = $this->getComponent("civicrm_{$c}_contact_1_contact_gender_id");
            if ($gender_field && $gender_field['type'] == 'textfield') {
              $result[1]['gender_id'] = wf_crm_aval($result[1], 'gender');
            }
          }
          // Extra processing for addresses
          if ($ent == 'address') {
            foreach ($result as &$address) {
              // Translate to abbr
              if (!empty($address['state_province_id'])) {
                $address['state_province_id'] = wf_crm_state_abbr($address['state_province_id']);
              }
              // Load custom data
              if (isset($address['id'])){
                $custom = $this->getCustomData($address['id'], 'address');
                if (!empty($custom['address'])) {
                  $address += $custom['address'][1];
                }
              }
            }
          }
          $info[$ent] = $result;
        }
      }
    }
    // Get custom contact data if needed
    foreach ($contact as $k => $v) {
      if (substr($k, 0, 12) == 'number_of_cg' && !empty($v)) {
        if (!in_array(substr($k, 10), $exclude)) {
          $info += array_diff_key($this->getCustomData($cid), array_flip($exclude));
          break;
        }
      }
    }
    // Retrieve group and tag data
    if (!in_array('other', $exclude)) {
      $api = ['tag' => 'entity_tag', 'group' => 'group_contact'];
      foreach (array_keys($this->enabled) as $fid) {
        // This way we support multiple tag fields (for tagsets)
        if (strpos($fid, $prefix . 'other') !== FALSE) {
          list(, , , , , $ent) = explode('_', $fid);
          list(, , , , , $field) = explode('_', $fid, 6);
          // Cheap way to avoid fetching the same data twice from the api
          if (!is_array($api[$ent])) {
            $api[$ent] = wf_civicrm_api($api[$ent], 'get', ['contact_id' => $cid]);
          }
          foreach (wf_crm_aval($api[$ent], 'values') as $val) {
            $info['other'][1][$field][] = $val[$ent . '_id'];
          }
        }
      }
    }
    // Retrieve relationship data
    if (!in_array('relationship', $exclude) && !empty($contact['number_of_relationship'])) {
      $this->enabled = wf_crm_enabled_fields($this->node);
      for ($r = 1; $r <= $contact['number_of_relationship']; ++$r) {
        $types = [];
        $prefix = "civicrm_{$c}_contact_{$r}_relationship_";
        if (!empty($this->ent['contact'][$r]['id'])) {
          if (!empty($contact['relationship'][$r]['relationship_type_id']) && $contact['relationship'][$r]['relationship_type_id'] != 'create_civicrm_webform_element') {
            $types = (array) $contact['relationship'][$r]['relationship_type_id'];
          }
          if (!empty($this->enabled[$prefix . 'relationship_type_id'])) {
            $types += array_keys($this->getExposedOptions($prefix . 'relationship_type_id'));
          }
        }
        $rel = $this->getRelationship($types, $cid, wf_crm_aval($this->ent['contact'], "$r:id"));
        if ($rel) {
          $info['relationship'][$r] = $rel;
        }
      }
    }
    $this->loadedContacts[$c] = $info;
    return $info;
  }

  /**
   * Find an existing contact based on matching criteria
   * Used to populate a webform existing contact field
   *
   * @param array $component
   *   Webform component of type 'civicrm_contact'
   */
  protected function findContact($component) {
    module_load_include('inc', 'webform_civicrm', 'includes/contact_component');
    list(, $c,) = explode('_', $component['form_key'], 3);
    $filters = wf_crm_search_filters($this->node, $component);
    // Start with the url - that trumps everything.
    if (isset($_GET["cid$c"]) || ($c == 1 && isset($_GET['cid']))) {
      $cid = isset($_GET["cid$c"]) ? $_GET["cid$c"] : $_GET['cid'];
      if (wf_crm_is_positive($cid) || $cid === '0' || $cid === 0) {
        $cid = (int) $cid;
        if ($cid === 0) {
          $this->ent['contact'][$c]['id'] = $cid;
          return;
        }
        // This property may not exist in components created before v4.12 so default to TRUE if not set
        elseif (wf_crm_aval($component['extra'], 'allow_url_autofill', TRUE, TRUE)) {
          if (wf_crm_contact_access($component, $filters, $cid) !== FALSE) {
            $this->ent['contact'][$c]['id'] = $cid;
          }
        }
      }
    }
    if (empty($this->ent['contact'][$c]['id'])) {
      $found = [];
      switch ($component['extra']['default']) {
        case 'user':
          $cid = wf_crm_user_cid();
          $found = ($c == 1 && $cid) ? [$cid] : [];
          break;
        case 'contact_id':
          if ($component['extra']['default_contact_id']) {
            $found = [$component['extra']['default_contact_id']];
          }
          break;
        case 'relationship':
          $to = $component['extra']['default_relationship_to'];
          if (!empty($this->ent['contact'][$to]['id']) && !empty($component['extra']['default_relationship'])) {
            $found = wf_crm_find_relations($this->ent['contact'][$to]['id'], $component['extra']['default_relationship']);
          }
          break;
        case 'custom_ref':
          $to = $component['extra']['default_relationship_to'];
          if (!empty($this->ent['contact'][$to]['id'])) {
            $found = wf_crm_find_custom_refs($this->ent['contact'][$to]['id'], $component['extra']['default_custom_ref']);
          }
          break;
        case 'case_roles':
          $to = $component['extra']['default_relationship_to'];
          $case = $component['extra']['default_for_case'];
          if (!empty($this->ent['contact'][$to]['id']) && !empty($this->ent['case'][$case]['id'])) {
            $found = wf_crm_find_case_roles($this->ent['contact'][$to]['id'], $this->ent['case'][$case]['id'], $component['extra']['default_case_roles']);
            unset($this->ent['contact'][$c]['reload']);
          }
          else {
            $this->ent['contact'][$c]['reload'] = TRUE;
          }
          break;
        case 'auto':
          $component['extra']['allow_create'] = FALSE;
          $found = array_keys(wf_crm_contact_search($this->node, $component, $filters, wf_crm_aval($this->ent, 'contact', [])));
          break;
      }
      if ($component['extra']['randomize']) {
        shuffle($found);
      }
      if (in_array($component['extra']['default'], ['user', 'contact_id'])) {
        $dupes_allowed = TRUE;
      }
      else {
        $dupes_allowed = $component['extra']['dupes_allowed'];
      }
      foreach ($found as $cid) {
        // Don't pick the same contact twice unless explicitly told to do so
        if (!$dupes_allowed) {
          foreach($this->ent['contact'] as $contact) {
            if (!empty($contact['id']) && $cid == $contact['id']) {
              continue 2;
            }
          }
        }
        // Check filters except for 'auto' which already applied them
        if ($component['extra']['default'] == 'auto' || wf_crm_contact_access($component, $filters, $cid) !== FALSE) {
          $this->ent['contact'][$c]['id'] = $cid;
          break;
        }
      }
    }
  }

  /**
   * Reorder returned results according to settings chosen in wf_civicrm backend
   *
   * @param integer $c
   * @param string $ent
   * @param array $values
   * @return array $reorderedArray
   */
  protected function reorderByLocationType($c, $ent, $values = []){
    $reorderedArray = [];

    if (isset($this->settings['data']['contact'][$c][$ent])){
      // First pass
      if ($ent == 'website') {
        $reorderedArray = $this->matchWebsiteTypes($c, $ent, $values);
      }
      else {
        $reorderedArray = $this->matchLocationTypes($c, $ent, $values);
      }
      // Second pass
      $reorderedArray = $this->handleRemainingValues($reorderedArray, $values);

      return $reorderedArray;
    } else {
      return $values;
    }
  }

  /**
   * Organize values according to location types
   *
   * @param integer $c
   * @param string $ent
   * @param array $values
   * @return array $reorderedArray
   */
  protected function matchLocationTypes($c, $ent, &$values){
    // create temporary settings array to include 'user-select' fields
    // on the right place in array
    $settingsArray = $this->add_user_select_field_placeholder($ent, $this->settings['data']['contact'][$c]);
    $userSelectIndex = 0;
    // Go through the array and match up locations by type
    // Put placeholder 'user-select' where location_type_id is empty for second pass
    foreach ($settingsArray[$ent] as $setting) {
      $valueFound = false;
      foreach($values as $key => $value){
        if ((in_array($ent, ['address', 'email']) && $value['location_type_id'] == $setting['location_type_id'])
          || (
             $value['location_type_id'] == $setting['location_type_id'] &&
             (!isset($setting[$ent.'_type_id']) || $value[$ent.'_type_id'] == $setting[$ent.'_type_id'])
             )
        ) {
            $reorderedArray[$key] = $value;
            $valueFound = true;
            unset($values[$key]);
            break;
        }
        // For 'user-select' fields
        else if (empty($setting['location_type_id'])) {
          $valueFound = true;
          $reorderedArray['us'.$userSelectIndex] = 'user-select';
          $userSelectIndex ++;
          break;
        }
      }

      // always keep number of returned values equal to chosen settings
      // if value is not found then set an empty array
      if (!$valueFound){
        $reorderedArray[] = [];
      }
    }
    return $reorderedArray;
  }

  /**
   * Organize values according to website types
   *
   * @param integer $c
   * @param string $ent
   * @param array $values
   * @return array $reorderedArray
   */
  protected function matchWebsiteTypes($c, $ent, &$values) {
    // create temporary settings array to include 'user-select' fields
    // on the right place in array
    $settingsArray = $this->add_user_select_field_placeholder($ent, $this->settings['data']['contact'][$c]);
    $userSelectIndex = 0;
    // Go through the array and match up locations by type
    // Put placeholder 'user-select' where location_type_id is empty for second pass
    foreach ($settingsArray[$ent] as $setting) {
      $valueFound = FALSE;
      foreach ($values as $key => $value) {
        if (($value[$ent . '_type_id'] == $setting[$ent . '_type_id'])
        ) {
          $reorderedArray[$key] = $value;
          $valueFound = TRUE;
          unset($values[$key]);
          break;
        }
        else {
          if (empty($setting['website_type_id'])) { // for 'user-select' fields
            $valueFound = TRUE;
            $reorderedArray['us' . $userSelectIndex] = 'user-select';
            $userSelectIndex++;
            break;
          }
        }
      }

      // always keep number of returned values equal to chosen settings
      // if value is not found then set an empty array
      if (!$valueFound){
        $reorderedArray[] = [];
      }
    }
    return $reorderedArray;
  }

  /**
   * Put remaining values in 'user-select' fields
   *
   * @param array $reorderedArray
   * @param array $values
   * @return array $reorderedArray
   */
  protected function handleRemainingValues($reorderedArray, &$values){
    // Put leftover values in fields marked as 'user-select'
    foreach($reorderedArray as $key => $value){
      if ($reorderedArray[$key] == 'user-select'){
        $reorderedArray[$key] = !empty($values) ? array_shift($values) : '';
      }
    }
    return $reorderedArray;
  }

  /**
   * Add location_type_id = NULL for user-select fields for identification later
   *
   * @param string $ent
   * @param array $settings
   * @return array $settings
   */
  protected function add_user_select_field_placeholder($ent, $settings = []){
    if ($settings['number_of_'.$ent] > count($settings[$ent])){
      for($i = 1; $i <= $settings['number_of_'.$ent]; $i++){
        if (!array_key_exists($i, $settings[$ent])){
          $settings[$ent][$i]['location_type_id'] = NULL;
        }
      }
      ksort($settings[$ent]);
    }
    return $settings;
  }

  /**
   * Fetch relationship for a pair of contacts
   *
   * @param $r_types
   *   Array of relationship type ids
   * @param $cid1
   *   Contact id
   * @param $cid2
   *   Contact id
   * @return array
   */
  protected function getRelationship($r_types, $cid1, $cid2) {
    $found = [];
    $active_only = !empty($this->settings['create_new_relationship']);
    if ($r_types && $cid1 && $cid2) {
      $types = [];
      foreach ($r_types as $r_type) {
        list($type, $side) = explode('_', $r_type);
        $types[$type] = $type;
      }
      $params = [
        'contact_id_a' => ['IN' => [$cid1, $cid2]],
        'contact_id_b' => ['IN' => [$cid1, $cid2]],
        'relationship_type_id' => ['IN' => $types],
      ];
      if ($active_only) {
        $params['is_active'] = 1;
        $params['options']['sort'] = 'is_active DESC, end_date ASC';
      }
      foreach (wf_crm_apivalues('relationship', 'get', $params) as $rel) {
        $type = $rel['relationship_type_id'];
        $side = $rel['contact_id_a'] == $cid1 ? 'a' : 'b';
        if (
          // Verify relationship orientation
          (in_array("{$type}_$side", $r_types) || in_array("{$type}_r", $r_types))
          // Verify 2 contacts are different unless we're specifically looking for a self-relationship
          && ($rel['contact_id_a'] != $rel['contact_id_b'] || $cid1 == $cid2)
          // Verify end date is not past when searching for active only
          && (empty($rel['end_date']) || !$active_only || strtotime($rel['end_date']) > time())
        ) {
          // Support multi-valued relationship type fields, fudge the rest
          $found['relationship_type_id'][] = in_array("{$type}_r", $r_types) ? "{$type}_r" : "{$type}_$side";
          $found['relationship_permission'] = (!empty($rel['is_permission_a_b']) ? 1 : 0) + (!empty($rel['is_permission_b_a']) ? 2 : 0);
          $found += $rel;
        }
      }
    }
    return $found;
  }

  /**
   * For a given field, find the options that are exposed to the webform.
   *
   * @param $field_key
   *   Field key
   * @param array $exclude
   *   Options to ignore
   *
   * @return array
   */
  protected function getExposedOptions($field_key, $exclude = []) {
    $field = $this->getComponent($field_key);

    if ($field && $field['type'] == 'hidden') {
      // Fetch live options
      $exposed = wf_crm_field_options($field, 'live_options', $this->data);
      foreach ($exclude as $i) {
        unset($exposed[$i]);
      }
      return $exposed;
    }

    if ($field && $field['type'] == 'select') {
      // Fetch static options
      if (empty($field['extra']['civicrm_live_options'])) {
        $exposed = wf_crm_str2array($field['extra']['items']);
      }
      // Fetch live options
      else {
        $exposed = wf_crm_field_options($field, 'live_options', $this->data);
      }
      foreach ($exclude as $i) {
        unset($exposed[$i]);
      }
      return $exposed;
    }
    return [];
  }

  /**
   * Fetch a webform component given its civicrm field key
   * @param $field_key
   * @return array|null
   */
  protected function getComponent($field_key) {
    if ($field_key && isset($this->enabled[$field_key])) {
      return wf_crm_aval($this->node->webform, 'components:' . $this->enabled[$field_key]);
    }
    return NULL;
  }

  /**
   * Get memberships for a contact
   * @param $cid
   * @return array
   */
  protected function findMemberships($cid) {
    static $status_types;
    static $membership_types;
    if (!isset($membership_types)) {
      $domain = wf_civicrm_api('domain', 'get', ['current_domain' => 1, 'return' => 'id']);
      $domain = wf_crm_aval($domain, 'id', 1);
      $membership_types = array_keys(wf_crm_apivalues('membershipType', 'get', ['is_active' => 1, 'domain_id' => $domain, 'return' => 'id']));
    }
    $existing = wf_crm_apivalues('membership', 'get', [
      'contact_id' => $cid,
      // Limit to only enabled membership types
      'membership_type_id' => ['IN' => $membership_types],
      // skip membership through Inheritance.
      'owner_membership_id' => ['IS NULL' => 1],
    ]);
    if (!$existing) {
      return [];
    }
    if (!$status_types) {
      $status_types = wf_crm_apivalues('membership_status', 'get');
    }
    // Attempt to order memberships by most recent and active
    $active = $expired = [];
    foreach ($existing as $membership) {
      $membership['is_active'] = $status_types[$membership['status_id']]['is_current_member'];
      $membership['status'] = $status_types[$membership['status_id']]['label'];
      $list = $membership['is_active'] ? 'active' : 'expired';
      array_unshift($$list, $membership);
    }
    return array_merge($active, $expired);
  }

  /**
   * Fetch info and remaining spaces for events
   */
  protected function loadEvents() {
    if (!empty($this->events)) {
      $now = time();
      $events = wf_crm_apivalues('Event', 'get', [
        'return' => ['title', 'start_date', 'end_date', 'event_type_id', 'max_participants', 'financial_type_id', 'event_full_text', 'is_full'],
        'id' => ['IN' => array_keys($this->events)],
      ]);
      foreach ($events as $id => $event) {
        $this->events[$id] = $event + $this->events[$id] + ['available_places' => 0];
        $this->events[$id]['ended'] = !empty($event['end_date']) && strtotime($event['end_date']) < $now;
      }
    }
  }

  /**
   * Get custom data for an entity
   *
   * @param $entity_id
   *   Numeric id of entity
   * @param $entity_type
   *   Type of crm entity. 'contact' is assumed
   * @param $normalize
   *   Default true: if true shift all arrays to start at index 1
   *
   * @return array
   */
  protected function getCustomData($entity_id, $entity_type = NULL, $normalize = TRUE) {
    static $parents = [];
    if (empty($parents)) {
      // Create matching table to sort fields by group
      foreach (wf_crm_get_fields() as $key => $value) {
        list($group, $field) = explode('_', $key, 2);
        if (substr($field, 0, 7) == 'custom_') {
          $parents[$field] = $group;
        }
      }
    }
    $params = ['entity_id' => $entity_id];
    if ($entity_type) {
      $params['entity_table'] = ucfirst($entity_type);
    }
    $result = wf_crm_apivalues('CustomValue', 'get', $params);
    $values = [];
    foreach ($result as $key => $value) {
      $name = 'custom_' . $key;
      // Sort into groups
      if (isset($parents[$name])) {
        $n = 1;
        foreach ($value as $id => $item) {
          // Non-numeric keys are api extras like "id" and "latest"
          if (is_numeric($id)) {
            $values[$parents[$name]][$normalize ? $n++ : $id][$name] = $item;
          }
        }
      }
    }
    return $values;
  }

  /**
   * @param string $fid
   * @param mixed $default
   * @param bool $strict
   * @return mixed
   */
  protected function getData($fid, $default = NULL, $strict = FALSE) {
    if ($pieces = wf_crm_explode_key($fid)) {
      list( , $c, $ent, $n, $table, $name) = $pieces;
      return wf_crm_aval($this->data, "{$ent}:{$c}:{$table}:{$n}:{$name}", $default, $strict);
    }
  }

  /**
   * Find a case matching criteria
   *
   * Normally we could do this by passing filters into the api, but legacy case api doesn't support them
   * So instead we fetch every case for the contact and loop through them to test against filters.
   *
   * @param array|int $cid
   * @param array $filters
   * @return null|array
   */
  function findCaseForContact($cid, $filters) {
    $case = NULL;
    foreach (wf_crm_apivalues('case', 'get', ['client_id' => $cid]) as $item) {
      if (empty($item['is_deleted'])) {
        $match = TRUE;
        foreach (array_filter($filters) as $filter => $value) {
          if (!array_intersect((array)$item[$filter], (array)$value)) {
            $match = FALSE;
          }
        }
        // Note: this loop has no break on purpose - this way we find the most recent case instead of stopping at the first
        if ($match) {
          $case = $item;
        }
      }
    }
    return $case;
  }

  /**
   * @param $type
   * @param $field
   * @return array|null
   */
  protected function getMembershipTypeField($type, $field) {
    if (!$this->membership_types) {
      $this->membership_types = wf_crm_apivalues('membership_type', 'get');
    }
    return wf_crm_aval($this->membership_types, $type . ':' . $field);
  }

  /**
   * CiviCRM JS can't be attached to a drupal form so have to manually re-add this during validation
   */
  function addPaymentJs() {
    $currentVer = CRM_Core_BAO_Domain::version();
    if (version_compare($currentVer, '5.8') <= 0 && method_exists('CRM_Core_Payment_Form', 'getCreditCardCSSNames')) {
      $credit_card_types = CRM_Core_Payment_Form::getCreditCardCSSNames();
      CRM_Core_Resources::singleton()
        ->addCoreResources()
        ->addSetting(['config' => ['creditCardTypes' => $credit_card_types]])
        ->addScriptFile('civicrm', 'templates/CRM/Core/BillingBlock.js', -10, 'html-header');
    }
    else {
      CRM_Core_Resources::singleton()->addCoreResources();
      CRM_Financial_Form_Payment::addCreditCardJs(NULL, 'html-header');
    }
  }

  /**
   * Copies a drupal file into the Civi file system
   *
   * @param int $id: drupal file id
   * @return int|null Civi file id
   */
  public static function saveDrupalFileToCivi($id) {
    $file = file_load($id);
    if ($file) {
      $config = CRM_Core_Config::singleton();
      $path = file_unmanaged_copy($file->uri, $config->customFileUploadDir);
      if ($path) {
        $result = wf_civicrm_api('file', 'create', [
          'uri' => str_replace($config->customFileUploadDir, '', $path),
          'mime_type' => $file->filemime,
        ]);
        return wf_crm_aval($result, 'id');
      }
    }
    return NULL;
  }

  /**
   * Retrieve info needed for pre-filling a webform file field
   *
   * @param string $fieldName
   * @param string|int $val: url or civi file id
   * @param string|null $entity: entity name
   * @param int|null $n: entity id
   * @return array|null
   */
  function getFileInfo($fieldName, $val, $entity, $n) {
    if (!$val) {
      return NULL;
    }
    if ($fieldName === 'image_URL') {
      return [
        'data_type' => 'File',
        'name' => NULL,
        'icon' => $val,
      ];
    }
    $file = wf_crm_apivalues('Attachment', 'get', $val);
    if (!empty($file[$val])) {
      return [
        'data_type' => 'File',
        'name' => $file[$val]['name'],
        'file_url'=> $file[$val]['url'],
        'icon' => file_icon_url((object) ['filemime' => $file[$val]['mime_type']]),
      ];
    }
    return NULL;
  }

  /**
   * Fetch the public url of a file in the Drupal file system
   *
   * @param int $id Drupal file id
   *
   * @return string|bool: url of file if found
   */
  function getDrupalFileUrl($id) {
    $file = file_load($id);
    return $file ? file_create_url($file->uri) : FALSE;
  }

  /**
   * FIXME: Use the api for this
   * @param string $ent - entity type
   * @param int $id - entity id
   * @return array starting at index 1
   */
  public function getAttachments($ent, $id) {
    $n = 1;
    $attachments = [];
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_entity_file WHERE entity_table = 'civicrm_$ent' AND entity_id = $id");
    while ($dao->fetch()) {
      $attachments[$n++] = ['id' => $dao->id, 'file_id' => $dao->file_id];
    }
    return $attachments;
  }

  /**
   * Generate the quickform key needed to access a contribution form
   * @return string
   */
  public function getQfKey() {
    return CRM_Core_Key::get('CRM_Contribute_Controller_Contribution', TRUE);
  }

  /**
   * Historically webform_civicrm was configured with the live payment processor ID and the is_test flag.
   * But this is not how CiviCRM expects it to work and this can cause problems where payments use the live processor
   *   instead of test.  So we "fix" the processor ID here to be the correct one for live/test.
   * An "improved" fix would involve changing the configuration saved by the user but this avoids that.
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function fixPaymentProcessorID() {
    // Check for is_test and payment_processor_id (pay later = 0 is set to '' here. is_test has no meaning for pay later).
    if (!empty($this->data['contribution'][1]['contribution'][1]['is_test'])
        && !empty($this->data['contribution'][1]['contribution'][1]['payment_processor_id'])) {
      $paymentProcessor = \Civi\Payment\System::singleton()->getById($this->data['contribution'][1]['contribution'][1]['payment_processor_id']);
      $paymentProcessor = \Civi\Payment\System::singleton()->getByName($paymentProcessor->getPaymentProcessor()['name'], TRUE);
      $this->data['contribution'][1]['contribution'][1]['payment_processor_id'] = $paymentProcessor->getPaymentProcessor()['id'];
    }
  }

}
