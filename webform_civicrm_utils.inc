<?php

/**
 * @file
 * Webform CiviCRM module's common utility functions.
 */

/**
 * Get option values from various civicrm tables
 * @Param $option_group: option group name or id
 * @Param $param: optional extra info for the query (passing a param will bypass caching)
 * @Return array
 */
function webform_civicrm_get_options($option_group, $param = NULL) {
  static $cache = array();
  if ($param || empty($cache[$option_group])) {
    $vars = $ret = array();
    if ($option_group == 'privacy') {
      // Privacy options aren't in the database as options; they are separate contact fields.
      return array(
        'do_not_email' => ts('Do not email'),
        'do_not_phone' => ts('Do not phone'),
        'do_not_mail' => ts('Do not mail'),
        'do_not_sms' => ts('Do not sms'),
        'do_not_trade' => ts('Do not trade'),
      );
    }
    if ($option_group == 'yes_no') {
      return array(1 => t('Yes'), 0 => t('No'));
    }
    if ($option_group == 'country') {
      $sql = 'SELECT name AS label, id AS value FROM civicrm_country';
      $config = CRM_Core_Config::singleton();
      if (!empty($config->countryLimit) && is_array($config->countryLimit)) {
        $sql .=  ' WHERE id IN (' . implode(',', $config->countryLimit) . ')';
      }
      $sql .= ' ORDER BY name';
    }
    elseif ($option_group == 'state_province') {
      $value = $param ? 'UPPER(abbreviation)' : 'id';
      if (!$param || $param == 'default') {
        $config = CRM_Core_Config::singleton();
        if (!$param && !empty($config->provinceLimit)) {
          $param = implode(',', $config->provinceLimit);
        }
        else {
          $param = (int) $config->defaultContactCountry;
        }
      }
      else {
        $param = (int) $param;
      }
      $sql = "SELECT name AS label, $value AS value FROM civicrm_state_province WHERE country_id IN ($param) ORDER BY name";
    }
    elseif ($option_group == 'tag') {
      require_once 'CRM/Core/BAO/Tag.php';
      // $param is entity type i.e. contact
      return CRM_Core_BAO_Tag::getTags("civicrm_$param", $ret, NULL, '- ');
    }
    elseif ($option_group == 'group' || $option_group == 'mailing_lists') {
      $sql = 'SELECT id AS value, title AS label FROM civicrm_group WHERE is_active = 1 AND is_hidden = 0';
      if ($option_group == 'mailing_lists') {
        $sql .= " AND group_type LIKE '%2%' AND visibility = 'Public Pages'";
      }
    }
    elseif ($option_group == 'languages') {
      require_once 'CRM/Core/PseudoConstant.php';
      return CRM_Core_PseudoConstant::languages();
    }
    elseif ($option_group == 'location_type') {
      $sql = 'SELECT display_name AS label, id AS value FROM civicrm_location_type WHERE is_active = 1 ORDER BY is_default DESC';
    }
    elseif ($option_group == 'campaign') {
      $sql = 'SELECT title AS label, id AS value FROM civicrm_campaign WHERE is_active = 1 AND (end_date >= NOW() OR end_date IS NULL) ORDER BY start_date DESC';
    }
    elseif ($option_group == 'event') {
      $sql = "SELECT title AS label, CONCAT(id, '-', event_type_id) AS value FROM civicrm_event WHERE is_template = 0";
      if (empty($param['show_past_events'])) {
        $sql .= ' AND (end_date >= NOW() OR end_date IS NULL) AND is_active = 1';
      }
      if (is_numeric($param['event_type'])) {
        $sql .= ' AND event_type_id = ' . $param['event_type'];
      }
      $sql .= ' ORDER BY start_date ' . ($param['context'] == 'config_form' ? 'DESC' : '');
    }
    else {
      $sql = 'SELECT value, label FROM civicrm_option_value WHERE is_active <> 0 AND option_group_id = ';
      if (is_numeric($option_group)) {
        $sql .= '%1';
        $vars[1] = array($option_group, 'Integer');
      }
      else {
        $sql .= '(SELECT id FROM civicrm_option_group WHERE name = %1)';
        $vars[1] = array($option_group, 'String');
      }
      // Exclude reserved activity types
      if ($option_group == 'activity_type') {
        $sql .= ' AND (component_id IS NULL' . ($param ? " OR component_id IN ($param))" : ') ORDER BY label');
      }
      else {
        $sql .= ' ORDER BY weight, label';
      }
    }
    $dao = &CRM_Core_DAO::executeQuery($sql, $vars);
    while ($dao->fetch()) {
      $ret[$dao->value] = $dao->label;
    }
    if ($param) {
      return $ret;
    }
    $cache[$option_group] = $ret;
  }
  return $cache[$option_group];
}

/**
 * Get options for a specific field
 * @Param $field: Webform component array
 * @Param $context: Where is this being called from?
 * @Param $data: CiviCRM entity data
 */
function webform_civicrm_field_options($field, $context, $data) {
  $ret = array();
  $fields = webform_civicrm_get_fields();
  if ($pieces = webform_civicrm_explode_key($field['form_key'])) {
    list( , $c, $ent, $n, $table, $name) = $pieces;
    // Ensure we have complete info for this field
    $field += $fields[$table . '_' . $name];
    if ($name === 'contact_sub_type') {
      list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
      $ret = $sub_types[$data['contact'][$c]['contact'][1]['contact_type']];
    }
    elseif ($name === 'relationship_type_id') {
      $ret = webform_civicrm_get_contact_relationship_types($data['contact'][$c]['contact'][1]['contact_type'], $data['contact'][$n]['contact'][1]['contact_type'], $data['contact'][$c]['contact'][1]['contact_sub_type'], $data['contact'][$n]['contact'][1]['contact_sub_type']);
    }
    elseif ($name === 'relationship_permission') {
      $ret = array(
        1 => t('Contact !a may view and edit contact !b', array('!a' => $c, '!b' => $n)),
        2 => t('Contact !a may view and edit contact !b', array('!a' => $n, '!b' => $c)),
        3 => t('Both contacts may view and edit each other'),
      );
    }
    // If this is a contract reference or shared address field, list webform contacts
    elseif ($name === 'master_id' || webform_civicrm_aval($field, 'data_type') === 'ContactReference') {
      foreach ($data['contact'] as $num => $contact) {
        if ($num != $c || $name != 'master_id') {
          $ret[$num] =  t('Contact !num', array('!num' => $num));
        }
      }
    }
    elseif ($name === 'tag') {
      $ret = webform_civicrm_get_options('tag', $ent);
    }
    elseif ($name === 'status_id' && $table === 'participant') {
      require_once 'CRM/Event/PseudoConstant.php';
      $ret = CRM_Event_PseudoConstant::participantStatus(NULL, NULL, 'label');
    }
    elseif ($list = webform_civicrm_get_list($table, $name)) {
      $param = NULL;
      if ($name === 'event_id') {
        $param = $data['reg_options'] + array('context' => $context);
      }
      $ret = webform_civicrm_get_options($list, $param);
      // Hack to format money data correctly
      if (!empty($field['data_type']) && $field['data_type'] === 'Money') {
        $old = $ret;
        $ret = array();
        foreach ($old as $key => $val) {
          $ret[number_format(str_replace(',', '', $key), 2, '.', '')] = $val;
        }
      }
    }
    // Remove options that were set behind the scenes
    if ($context != 'config_form' && !empty($field['extra']['multiple']) && !empty($field['expose_list'])) {
      foreach (webform_civicrm_aval($data, "$ent:$c:$table:$n:$name", array()) as $key => $val) {
        unset($ret[$key]);
      }
    }
  }
  return $context == 'component_insert' ? webform_civicrm_array2str($ret) : $ret;
}

/**
 * Get id of the option list for a field
 */
function webform_civicrm_get_list($table, $name) {
  $lists = webform_civicrm_get_fields('lists');
  if (isset($lists[$table . '_' . $name])) {
    return $lists[$table . '_' . $name];
  }
  if (isset($lists['*_' . $name])) {
    return $lists['*_' . $name];
  }
}

/**
 * Get contact types and sub-types
 */
function webform_civicrm_get_contact_types() {
  static $contact_types = array();
  static $sub_types = array();
  if (!$contact_types) {
    $sql = '
      SELECT c1.name, c1.label, LOWER(c2.name) AS parent_type
      FROM civicrm_contact_type c1
      LEFT JOIN civicrm_contact_type c2 ON c1.parent_id  = c2.id
      WHERE c1.is_active = 1
      ORDER BY c1.parent_id ASC';
    $dao = &CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if ($dao->parent_type) {
        $sub_types[$dao->parent_type][$dao->name] = $dao->label;
      }
      else {
        $contact_types[strtolower($dao->name)] = $dao->label;
        $sub_types[strtolower($dao->name)] = array();
      }
    }
  }
  return array($contact_types, $sub_types);
}

/**
 * Get relationship type data
 */
function webform_civicrm_get_relationship_types() {
  static $types = array();
  if (!$types) {
    $f = array('id', 'name_a_b', 'name_b_a', 'label_a_b', 'label_b_a', 'type_a', 'type_b', 'sub_type_a', 'sub_type_b');
    $sql = '
      SELECT id, name_a_b, name_b_a, label_a_b, label_b_a, LOWER(contact_type_a) AS type_a, LOWER(contact_type_b) AS type_b, contact_sub_type_a AS sub_type_a, contact_sub_type_b AS sub_type_b
      FROM civicrm_relationship_type
      WHERE is_active <> 0';
    $dao = &CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      foreach ($f as $field) {
        $types[$dao->id][$field] = $dao->{$field};
      }
      // Create a pseudo relationship type for "current employer"
      if ($dao->name_a_b == 'Employee of' && $dao->name_b_a == 'Employer of') {
        $types['ce'] = $types[$dao->id];
        $types['ce']['id'] = 'ce';
        $types['ce']['label_a_b'] = t('Current Employee of');
        $types['ce']['label_b_a'] = t('Current Employer of');
      }
    }
  }
  return $types;
}

/**
 * Get valid relationship types for a given pair of contacts
 */
function webform_civicrm_get_contact_relationship_types($type_a, $type_b, $sub_type_a, $sub_type_b) {
  $ret = array();
  foreach (webform_civicrm_get_relationship_types() as $t) {
    $reciprocal = ($t['label_a_b'] != $t['label_b_a'] && $t['label_b_a'] || $t['type_a'] != $t['type_b']);
    if (($t['type_a'] == $type_a || !$t['type_a'])
    && ($t['type_b'] == $type_b || !$t['type_b'])
    && (in_array($t['sub_type_a'], $sub_type_a) || !$t['sub_type_a'])
    && (in_array($t['sub_type_b'], $sub_type_b) || !$t['sub_type_b'])) {
      $ret[$t['id'] . ($reciprocal ? '_a' : '_r')] = $t['label_a_b'];
    }
    // Reciprocal form - only show if different from above
    if ($reciprocal
    && ($t['type_a'] == $type_b || !$t['type_a'])
    && ($t['type_b'] == $type_a || !$t['type_b'])
    && (in_array($t['sub_type_a'], $sub_type_b) || !$t['sub_type_a'])
    && (in_array($t['sub_type_b'], $sub_type_a) || !$t['sub_type_b'])) {
      $ret[$t['id'] . '_b'] = $t['label_b_a'];
    }
  }
  return $ret;
}

/**
 * Get custom data for an entity
 */
function webform_civicrm_get_custom($entity_id, $entity_type = NULL, $normalize = TRUE) {
  static $parents = array();
  if (empty($parents)) {
    require_once 'CRM/Core/BAO/CustomValueTable.php';
    // Create matching table to sort fields by group
    foreach (webform_civicrm_get_fields() as $key => $value) {
      list($group, $field) = explode('_', $key, 2);
      if (substr($field, 0, 7) == 'custom_') {
        $parents[$field] = $group;
      }
    }
  }
  $params = array('entityID' => $entity_id);
  if ($entity_type) {
    $params['entityType'] = ucfirst($entity_type);
  }
  $result = CRM_Core_BAO_CustomValueTable::getValues($params);
  if (!empty($result['is_error'])) {
    return array();
  }
  unset($result['is_error'], $result['entityID']);
  $values = array();
  // Convert multi-value strings to arrays and sort by group
  foreach ($result as $key => $value) {
    $pieces = explode('_', $key);
    if ($pieces[0] == 'custom') {
      $name = 'custom_' . $pieces[1];
      if (empty($pieces[2])) {
        $pieces[2] = ($normalize ? 1 : 0);
      }
      if (isset($parents[$name])) {
        $values[$parents[$name]][$pieces[2]][$name] = $value;
      }
    }
  }
  if ($normalize) {
    // Normalize array keys
    foreach ($values as $key => &$value) {
      array_unshift($value, 0);
      unset($value[0]);
    }
  }
  return $values;
}

/**
 * Save custom data for an entity
 */
function webform_civicrm_save_custom($entity, $entity_id, $entity_type = NULL) {
  $existing = webform_civicrm_get_custom($entity_id, $entity_type, FALSE);
  $params = array('entityID' => $entity_id);
  foreach ($entity as $table => $values) {
    if (substr($table, 0, 2) == 'cg' && is_array($values)) {
      if (empty($existing[$table])) {
        $existing[$table] = array();
      }
      $insert = 0;
      foreach ($values as $offset => $custom) {
        if ($id = each($existing[$table])) {
          $suf = $id['key'];
        }
        else {
          $suf = --$insert;
        }
        foreach ($custom as $k => $v) {
          $params[$k . '_' . $suf] = $v;
        }
      }
    }
  }
  if (count($params) > 1) {
    $result = CRM_Core_BAO_CustomValueTable::setValues($params);
    // Prevent wholesale failure by saving each param individually if there was an error while trying to save them all at once
    if (!empty($result['is_error'])) {
      $bt = debug_backtrace();
      array_shift($params);
      foreach ($params as $k => $v) {
        $single_param = array('entityID' => $entity_id, $k => $v);
        $result = CRM_Core_BAO_CustomValueTable::setValues($single_param);
        if (!empty($result['is_error'])) {
          watchdog('webform_civicrm', 'The CiviCRM "CustomValueTable::setValues" function returned the error: "%msg" when called by line !line of !file with the following parameters: "!params"', array('%msg' => $result['error_message'], '!line' => $bt[0]['line'], '!file' => array_pop(explode('/', $bt[0]['file'])), '!params' => print_r($single_param, TRUE)), WATCHDOG_ERROR);
        }
      }
    }
  }
}

/**
 * Get ids or values of enabled CiviCRM fields for a webform.
 * @Param $node: node object
 * @Param $submission: if supplied, will match field keys with submitted values
 * @Param $show_all: get every field even if it is not active
 */
function webform_civicrm_enabled_fields($node, $submission = NULL, $show_all = FALSE) {
  $enabled = array();
  if (!empty($node->webform['components']) && (!empty($node->webform_civicrm) || $show_all)) {
    $fields = webform_civicrm_get_fields();
    foreach ($node->webform['components'] as $c) {
      $exp = explode('_', $c['form_key'], 5);
      if (count($exp) == 5) {
        list($lobo, $i, $ent, $n, $id) = $exp;
        if ((isset($fields[$id]) || ($id == 'fieldset_fieldset' && isset($node->webform_civicrm['data']['contact'][$i])))
        && $lobo == 'civicrm' && is_numeric($i) && is_numeric($n)) {
          if (!$show_all && ($ent == 'contact' || $ent == 'participant') && empty($node->webform_civicrm['data']['contact'][$i])) {
            continue;
          }
          if ($submission) {
            $enabled[$c['form_key']] = webform_civicrm_aval($submission, $c['cid'], TRUE);
          }
          else {
            $enabled[$c['form_key']] = $c['cid'];
          }
        }
      }
    }
  }
  return $enabled;
}


/**
 * Match a state/province id to its abbr. and vice-versa
 */
function webform_civicrm_state_abbr($input, $ret = 'abbreviation', $country_id = NULL) {
  $input_type = $ret == 'id' ? 'abbreviation' : 'id';
  $sql = "SELECT $ret FROM civicrm_state_province WHERE $input_type = %1";
  $vars = array(1 => array($input, $ret == 'id' ? 'String' : 'Integer'));
  if ($country_id) {
    $sql .= ' AND country_id = %2';
    $vars[2] = array($country_id, 'Integer');
  }
  $sql .= ' LIMIT 0, 1';
  return CRM_Core_DAO::singleValueQuery($sql, $vars);
}

/**
 * Fetches CiviCRM field data.
 * @Param $var: name of variable to return: fields, tokens, lists, or sets
 * @Return fields: The CiviCRM contact fields this module supports
 * @Return tokens: Available tokens keyed to field ids
 * @Return lists: Option lists keyed to option_group name
 * @Return sets: Info on fieldsets (tables)
 */
function webform_civicrm_get_fields($var = 'fields') {
  static $fields = array();
  static $tokens = array();
  static $lists = array();
  static $sets = array();

  if (!$fields) {
    $config = CRM_Core_Config::singleton();
    $components = $config->enableComponents;

    // Field keys are in the format table_column
    // Use a # sign as a placeholder for field number in the title (or by default it will be appended to the end)
    // Setting 'expose_list' allows the value to be set on the config form
    // Set label for 'empty_option' for exposed lists that do not require input
    $fields['contact_contact_sub_type'] = array(
      'name' => t('Type of'),
      'type' => 'select',
      'extra' => array('multiple' => 1),
      'expose_list' => TRUE,
    );
    $fields['contact_existing'] = array(
      'name' => t('Existing Contact'),
      'type' => 'civicrm_contact',
      'extra' => array(
        'search_prompt' => t('- Choose existing contact -'),
      ),
    );
    foreach (array('organization' => t('Organization Name'), 'legal' => t('Legal Name'), 'household' => t('Household Name')) as $key => $label) {
      $fields['contact_' . $key . '_name'] = array(
        'name' => $label,
        'type' => 'textfield',
        'contact_type' => $key == 'household' ? 'household' : 'organization',
      );
    }
    foreach (array('first_name' => t('First Name'), 'nick_name' => t('Nick Name'), 'middle_name' => t('Middle Name'), 'last_name' => t('Last Name'), 'job_title' => t('Job Title')) as $key => $label) {
      $fields['contact_' . $key] = array(
        'name' => $label,
        'type' => 'textfield',
        'contact_type' => $key == 'nick_name' ? NULL : 'individual',
      );
    }
    foreach (array('prefix' => t('Name Prefix'), 'suffix' => t('Name Suffix'), 'gender' => t('Gender')) as $key => $label) {
      $fields['contact_' . $key . '_id'] = array(
        'name' => $label,
        'type' => 'select',
        'contact_type' => 'individual',
      );
    }
    $fields['contact_birth_date'] = array(
      'name' => t('Birth Date'),
      'type' => 'date',
      'extra' => array(
        'start_date' => '-100 years',
        'end_date' => 'now',
      ),
      'contact_type' => 'individual',
    );
    $fields['contact_preferred_communication_method'] = array(
      'name' => t('Preferred Communication Method(s)'),
      'type' => 'select',
      'extra' => array('multiple' => 1),
    );
    $fields['contact_privacy'] = array(
      'name' => t('Privacy Preferences'),
      'type' => 'select',
      'extra' => array('multiple' => 1),
    );
    $fields['contact_preferred_language'] = array(
      'name' => t('Preferred Language'),
      'type' => 'select',
      'value' => $config->lcMessages,
    );
    $fields['contact_image_URL'] = array(
      'name' => t('Upload Image'),
      'type' => 'file',
      'extra' => array('width' => 40),
    );
    $fields['contact_contact_id'] = array(
      'name' => t('Contact ID'),
      'type' => 'hidden',
      'extra' => array('description' => t('(hidden field, use for post-processing)')),
    );
    $fields['contact_external_identifier'] = array(
      'name' => t('External ID'),
      'type' => 'hidden',
      'extra' => array('description' => t('(hidden field, use for post-processing)')),
    );
    $fields['contact_cs'] = array(
      'name' => t('Generate Checksum'),
      'type' => 'hidden',
      'extra' => array('description' => t('(hidden field, use to create hashed links)')),
    );
    $fields['email_email'] = array(
      'name' => t('Email'),
      'type' => 'email',
    );
    foreach (array('street_address' => t('Street Address'), 'supplemental_address_1' => t('Street Address # Line 2'), 'supplemental_address_2' => t('Street Address # Line 3'), 'city' => t('City')) as $key => $value) {
      $fields['address_' . $key] = array(
        'name' => $value,
        'type' => 'textfield',
        'extra' => array('width' => $key == 'city' ? 20 : 60),
      );
    }
    $fields['address_postal_code'] = array(
      'name' => t('Postal Code'),
      'type' => 'textfield',
      'extra' => array('width' => 7),
    );
    $fields['address_postal_code_suffix'] = array(
      'name' => t('Postal Code Suffix'),
      'type' => 'textfield',
      'extra' => array(
        'width' => 5,
        'description' => t('+4 digits of Zip Code'),
      ),
    );
    $fields['address_country_id'] = array(
      'name' => t('Country'),
      'type' => 'select',
      'value' => $config->defaultContactCountry,
    );
    $fields['address_state_province_id'] = array(
      'name' => t('State/Province'),
      'type' => 'textfield',
      'extra' => array(
        'maxlength' => 5,
        'width' => 4,
      ),
      'data_type' => 'state_province_abbr',
    );
    $fields['address_master_id'] = array(
      'name' => t('Share address of'),
      'type' => 'select',
      'expose_list' => TRUE,
      'extra' => array('description' => t('Will overwrite address fields with those of the other contact.')),
      'empty_option' => t('- Do Not Share -'),
    );
    $fields['phone_phone'] = array(
      'name' => t('Phone Number'),
      'type' => 'textfield',
    );
    $fields['phone_phone_type_id'] = array(
      'name' => t('Phone # Type'),
      'type' => 'select',
      'table' => 'phone',
      'expose_list' => TRUE,
    );
    foreach (array('address' => t('Address # Location'), 'phone' => t('Phone # Location'), 'email' => t('Email # Location')) as $key => $label) {
      $fields[$key . '_location_type_id'] = array(
        'name' => $label,
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => '1',
      );
    }
    $fields['website_url'] = array(
      'name' => t('Website'),
      'type' => 'textfield',
      'data_type' => 'Link',
    );
    $fields['website_website_type_id'] = array(
      'name' => t('Website # Type'),
      'type' => 'select',
      'expose_list' => TRUE,
    );
    $fields['other_group'] = array(
      'name' => t('Groups'),
      'type' => 'select',
      'extra' => array('multiple' => 1, 'civicrm_live_options' => 1),
      'table' => 'group',
      'expose_list' => TRUE,
    );
    $fields['other_tag'] = array(
      'name' => t('Tags'),
      'type' => 'select',
      'extra' => array('multiple' => 1, 'civicrm_live_options' => 1),
      'table' => 'tag',
      'expose_list' => TRUE,
    );
    $fields['activity_target_contact_id'] = array(
      'name' => t('Activity Participants'),
      'type' => 'select',
      'expose_list' => TRUE,
      'extra' => array(
        'multiple' => 1,
        'description' => t('Which contacts should be tagged as part of this activity?'),
      ),
      'data_type' => 'ContactReference',
    );
    $fields['activity_subject'] = array(
      'name' => t('Activity Subject'),
      'type' => 'textfield',
    );
    $fields['activity_details'] = array(
      'name' => t('Activity Details'),
      'type' => module_exists('webform_html_textarea') ? 'html_textarea' : 'textarea',
    );
    $fields['activity_status_id'] = array(
      'name' => t('Activity Status'),
      'type' => 'select',
      'value' => '2',
      'expose_list' => TRUE,
    );
    $fields['activity_assignee_contact_id'] = array(
      'name' => t('Assign Activity to'),
      'type' => 'select',
      'expose_list' => TRUE,
      'empty_option' => t('- No One -'),
      'data_type' => 'ContactReference',
    );
    if (in_array('CiviCampaign', $components)) {
      $fields['activity_campaign_id'] = array(
        'name' => t('Campaign'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('- None -'),
        'extra' => array('civicrm_live_options' => 1),
      );
    }
    if (in_array('CiviCase', $components)) {
      $fields['case_status_id'] = array(
        'name' => t('Case Status'),
        'type' => 'select',
        'expose_list' => TRUE,
      );
      $fields['case_medium_id'] = array(
        'name' => t('Medium'),
        'type' => 'select',
        'extra' => array('description' => t('Medium for activities added to cases from this webform.')),
        'expose_list' => TRUE,
      );
      $fields['case_subject'] = array(
        'name' => t('Case Subject'),
        'type' => 'textfield',
      );
      $fields['case_creator_id'] = array(
        'name' => t('Case Manager'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => array('description' => t('Owner of newly created cases.')),
        'data_type' => 'ContactReference',
      );
    }
    $fields['relationship_relationship_type_id'] = array(
      'name' => t('Relationship to Contact #'),
      'type' => 'select',
      'expose_list' => TRUE,
      'empty_option' => t('- No Relationship -'),
      'extra' => array('civicrm_live_options' => 1),
    );
    $fields['relationship_is_active'] = array(
      'name' => t('Relationship to Contact # Is Active'),
      'type' => 'select',
      'expose_list' => TRUE,
      'value' => '1',
    );
    $fields['relationship_relationship_permission'] = array(
      'name' => t('Relationship to Contact # Permission'),
      'type' => 'select',
      'expose_list' => TRUE,
      'empty_option' => t('- No Permissions -'),
    );
    $fields['relationship_start_date'] = array(
      'name' => t('Relationship to Contact # Start Date'),
      'type' => 'date',
      'extra' => array(
        'start_date' => '-50 years',
        'end_date' => '+50 years',
      ),
    );
    $fields['relationship_end_date'] = array(
      'name' => t('Relationship to Contact # End Date'),
      'type' => 'date',
      'extra' => array(
        'start_date' => '-50 years',
        'end_date' => '+50 years',
      ),
    );
    if (in_array('CiviEvent', $components)) {
      $fields['participant_event_id'] = array(
        'name' => t('Events'),
        'type' => 'select',
        'extra' => array('multiple' => 1, 'civicrm_live_options' => 1),
        'expose_list' => TRUE,
      );
      $fields['participant_role_id'] = array(
        'name' => t('Participant Role'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => '1',
      );
      $fields['participant_status_id'] = array(
        'name' => t('Registration Status'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => '1',
      );
      if (in_array('CiviCampaign', $components)) {
        $fields['participant_campaign_id'] = array(
          'name' => t('Campaign'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => array('civicrm_live_options' => 1),
          'empty_option' => t('- None -'),
        );
      }
    }

    $tokens = array(
      'display_name' => t('display name'),
      'first_name' => t('first name'),
      'nick_name' => t('nick name'),
      'middle_name' => t('middle name'),
      'last_name' => t('last name'),
      'individual_prefix' => t('name prefix'),
      'individual_suffix' => t('name suffix'),
      'gender' => t('gender'),
      'birth_date' => t('birth date'),
      'job_title' => t('job title'),
      'current_employer' => t('current employer'),
      'contact_id' => t('contact id'),
      'street_address' => t('street address'),
      'city' => t('city'),
      'state_province' => t('state/province abbr'),
      'state_province_name' => t('state/province full'),
      'postal_code' => t('postal code'),
      'country' => t('country'),
      'world_region' => t('world region'),
      'phone' => t('phone number'),
      'email' => t('email'),
    );
    // key: table_field_name (table can be a * wildcard)
    // value: name of civicrm_option_group
    $lists = array(
      'contact_contact_sub_type' => 'contact_sub_type',
      'contact_prefix_id' => 'individual_prefix',
      'contact_suffix_id' => 'individual_suffix',
      'contact_gender_id' => 'gender',
      'contact_preferred_communication_method' => 'preferred_communication_method',
      'contact_preferred_language' => 'languages',
      'contact_privacy' => 'privacy',
      '*_country_id' => 'country',
      'phone_phone_type_id' => 'phone_type',
      '*_location_type_id' => 'location_type',
      'website_website_type_id' => 'website_type',
      'address_master_id' => 'contact_address',
      'activity_target_contact_id' => 'contact',
      'activity_assignee_contact_id' => 'contact',
      'relationship_relationship_type_id' => 'relationship_type_id',
      'relationship_is_active' => 'yes_no',
      'relationship_relationship_permission' => 'relationship_permission',
      '*_group' => 'group',
      '*_tag' => 'tag',
      'activity_status_id' => 'activity_status',
      'case_status_id' => 'case_status',
      'case_medium_id' => 'encounter_medium',
      'participant_event_id' => 'event',
      'participant_role_id' => 'participant_role',
      '*_campaign_id' => 'campaign',
    );

    $sets = array(
      'contact' => array('entity_type' => 'contact', 'label' => t('Contact Fields')),
      'other' => array('entity_type' => 'contact', 'label' => t('Tags and Groups'), 'max_instances' => 1),
      'address' => array('entity_type' => 'contact', 'label' => t('Address'), 'max_instances' => 9),
      'phone' => array('entity_type' => 'contact', 'label' => t('Phone'), 'max_instances' => 9),
      'email' => array('entity_type' => 'contact', 'label' => t('Email'), 'max_instances' => 9),
      'website' => array('entity_type' => 'contact', 'label' => t('Website'), 'max_instances' => 9),
      'activity' => array('entity_type' => 'activity', 'label' => t('Activity')),
      'relationship' => array('entity_type' => 'contact', 'label' => t('Relationship to Contact')),
    );
    if (in_array('CiviCase', $components)) {
      $sets['case'] = array('entity_type' => 'case', 'label' => t('Case Settings'));
    }
    if (in_array('CiviEvent', $components)) {
      $sets['participant'] = array('entity_type' => 'participant', 'label' => t('Participant'), 'max_instances' => 9);
    }

    // Pull custom fields and match to Webform element types
    $custom_types = array(
      'Select' => array('type' => 'select'),
      'Multi-Select' => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'AdvMulti-Select' => array('type' => 'select', 'extra' => array('multiple' => 1, 'civicrm_live_options' => 1)),
      'Radio' => array('type' => 'select', 'extra' => array('aslist' => 0)),
      'CheckBox' => array('type' => 'select', 'extra' => array('multiple' => 1, 'civicrm_live_options' => 1)),
      'Text'  => array('type' => 'textfield'),
      'TextArea' => array('type' => 'textarea'),
      'RichTextEditor' => array('type' => module_exists('webform_html_textarea') ? 'html_textarea' : 'textarea'),
      'Select Date' => array('type' => 'date'),
      'Link'  => array('type' => 'textfield'),
      'Select Country' => array('type' => 'select'),
      'Multi-Select Country' => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'Select State/Province' => array('type' => 'select'),
      'Multi-Select State/Province' => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'Autocomplete-Select' => array('type' => 'select'),
    );
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    $custom_extends = "'contact','individual','organization','household','address','activity','relationship'";
    if (in_array('CiviEvent', $components, TRUE)) {
      $custom_extends .= ",'participant'";
    }
    if (in_array('CiviCase', $components, TRUE)) {
      $custom_extends .= ",'case'";
    }
    $sql = "
      SELECT cf.*, cg.title AS custom_group_name, LOWER(cg.extends) AS entity_type, cg.extends_entity_column_id, cg.extends_entity_column_value AS sub_types, cg.is_multiple, cg.max_multiple, cg.id AS custom_group_id
      FROM civicrm_custom_field cf
      INNER JOIN civicrm_custom_group cg ON cg.id = cf.custom_group_id
      WHERE cf.is_active <> 0 AND cg.extends IN ($custom_extends) AND cg.is_active <> 0
      ORDER BY cf.custom_group_id, cf.weight";
    $dao = &CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if (isset($custom_types[$dao->html_type])) {
        $set = 'cg' . $dao->custom_group_id;
        if ($dao->entity_type == 'address' || $dao->entity_type == 'relationship') {
          $set = $dao->entity_type;
        }
        elseif (!isset($sets[$set])) {
          $sets[$set]['label'] = $dao->custom_group_name;
          if ($dao->entity_type != 'activity' && $dao->entity_type != 'participant' && $dao->entity_type != 'case') {
            $sets[$set]['entity_type'] = 'contact';
            if ($dao->entity_type != 'contact') {
              $sets[$set]['contact_type'] = $dao->entity_type;
            }
            if ($dao->is_multiple) {
              $sets[$set]['max_instances'] = ($dao->max_multiple ? $dao->max_multiple : 9);
            }
            else {
              $sets[$set]['max_instances'] = 1;
            }
          }
          else {
            $sets[$set]['entity_type'] = $dao->entity_type;
          }
          if ($dao->sub_types) {
            $sets[$set]['sub_types'] = explode($sp, trim($dao->sub_types, $sp));
          }
          if ($dao->extends_entity_column_id) {
            $sets[$set]['extension_of'] = $dao->extends_entity_column_id;
          }
        }
        $id = $set . '_custom_' . $dao->id;
        $fields[$id] = $custom_types[$dao->html_type];
        $fields[$id]['name'] = $dao->label;
        $fields[$id]['mandatory'] = $dao->is_required;
        $fields[$id]['extra']['description'] = $dao->help_pre;
        $fields[$id]['value'] = str_replace($sp, ',', trim($dao->default_value, $sp));
        $fields[$id]['data_type'] = $dao->data_type;

        if ($dao->entity_type == 'relationship' && $dao->sub_types) {
          $fields[$id]['attributes']['data-relationship-type'] = str_replace($sp, ',', trim($dao->sub_types, $sp));
        }

        if ($fields[$id]['type'] == 'date') {
          $fields[$id]['extra']['start_date'] = ($dao->start_date_years ? '-' . $dao->start_date_years : '-50') . ' years';
          $fields[$id]['extra']['end_date'] = ($dao->end_date_years ? '+' . $dao->end_date_years : '+50') . ' years';
        }
        elseif ($og = $dao->option_group_id) {
          $lists[$set . '_custom_' . $dao->id] = $og;
          $fields[$id]['extra']['civicrm_live_options'] = 1;
        }
        elseif ($dao->html_type == 'Select Country' || $dao->html_type == 'Multi-Select Country') {
          $lists[$set . '_custom_' . $dao->id] = 'country';
          $fields[$id]['extra']['civicrm_live_options'] = 1;
        }
        elseif ($dao->html_type == 'Select State/Province' || $dao->html_type == 'Multi-Select State/Province') {
          $lists[$set . '_custom_' . $dao->id] = 'state_province';
          $fields[$id]['extra']['civicrm_live_options'] = 1;
        }
        elseif ($fields[$id]['data_type'] == 'ContactReference') {
          $lists[$set . '_custom_' . $dao->id] = 'contact';
          $fields[$id]['expose_list'] = TRUE;
          $fields[$id]['empty_option'] = t('- None -');
        }
        elseif ($fields[$id]['type'] == 'select') {
          $lists[$set . '_custom_' . $dao->id] = 'yes_no';
        }
        elseif ($fields[$id]['type'] == 'textarea') {
          $fields[$id]['extra']['cols'] = $dao->note_columns;
          $fields[$id]['extra']['rows'] = $dao->note_rows;
        }
      }
    }
  }
  return $$var;
}

/**
 * Lookup a uf ID from contact ID or vice-versa
 * @param $id: uf or contact ID - defaults to current user
 * @param $type: what type of ID is supplied
 * With no arguments passed in, this function will return the contact_id of the current logged-in user
 */
function webform_civicrm_user_cid($id = NULL, $type = 'uf') {
  if (!$id) {
    global $user;
    $id = $user->uid;
  }
  // Lookup current domain for multisite support
  static $domain = 0;
  if (!$domain) {
    $domain = webform_civicrm_api('domain', 'get', array('current_domain' => 1, 'return.id' => 1));
    $domain = webform_civicrm_aval($domain, 'id', 1);
  }
  $result = webform_civicrm_api('uf_match', 'get', array($type . '_id' => $id));
  if (!empty($result['values'])) {
    foreach ($result['values'] as $val) {
      if ($val['domain_id'] == $domain) {
        break;
      }
    }
    return $type == 'uf' ? $val['contact_id'] : $val['uf_id'];
  }
}

/**
 * Fetch contact display name
 */
function webform_civicrm_display_name($cid) {
  if (!$cid || !is_numeric($cid)) {
    return '';
  }
  civicrm_initialize();
  $result = webform_civicrm_api('contact', 'get', array('id' => $cid, 'return.display_name' => 1, 'is_deleted' => 0));
  return webform_civicrm_aval($result, "values:$cid:display_name", '');
}

/**
 * Explodes form key into an array and verifies that it is in the right format
 */
function webform_civicrm_explode_key($key) {
  $pieces = explode('_', $key, 6);
  if (count($pieces) != 6 || $pieces[0] !== 'civicrm') {
    return FALSE;
  }
  return $pieces;
}

/**
 * Convert a | separated string into an array
 */
function webform_civicrm_str2array($str) {
  $ret = array();
  if ($str) {
    foreach (explode("\n", trim($str)) as $row) {
      list($k, $v) = explode('|', $row);
      $ret[trim($k)] = trim($v);
    }
  }
  return $ret;
}

/**
 * Convert an array into a | separated string
 */
function webform_civicrm_array2str($arr) {
  $str = '';
  foreach ($arr as $k => $v) {
    $str .= ($str ? "\n" : '') . $k . '|' . $v;
  }
  return $str;
}

/**
 * Token replacement for form messages
 */
function webform_civicrm_replace_tokens($str, $contact) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  $tokens = webform_civicrm_get_fields('tokens');
  $values = array();
  foreach ($tokens as $k => &$t) {
    if (empty($contact[$k])) {
      $contact[$k] = '';
    }
    $value = $contact[$k];
    if (is_array($value)) {
      $value = implode(', ', $value);
    }
    $values[] = str_replace($sp, ' &amp; ', trim($value, $sp));
    $t = "[$t]";
  }
  return str_ireplace($tokens, $values, $str);
}

/**
 * Wrapper for all CiviCRM API calls
 * For consistency, future-proofing, and error handling
 */
function webform_civicrm_api($entity, $operation, $params) {
  $params += array(
    'check_permissions' => FALSE,
    'version' => 3
  );
  $result = civicrm_api($entity, $operation, $params);
  if (!empty($result['is_error'])) {
    $bt = debug_backtrace();
    watchdog('webform_civicrm', 'The CiviCRM "%function" API function returned the error: "%msg" when called by line !line of !file with the following parameters: "!params"', array('%function' => $entity . ' ' . $operation, '%msg' => $result['error_message'], '!line' => $bt[0]['line'], '!file' => array_pop(explode('/', $bt[0]['file'])), '!params' => print_r($params, TRUE)), WATCHDOG_ERROR);
  }
  return $result;
}

/**
 * At least one of these fields is required to create a contact
 */
function webform_civicrm_required_contact_fields() {
  $ret = array(array('table' => 'email', 'name' => 'email'));
  foreach (array('first', 'nick', 'last', 'organization', 'household', 'legal') as $name) {
    $ret[] = array('table' => 'contact', 'name' => $name . '_name');
  }
  return $ret;
}

/**
 * Fetch an existing activity for a contact based on activity & case criteria
 * Maybe someday this will be possible through the CiviCRM API
 */
function webform_civicrm_activity_find($params) {
  $cid = $params['contact_id'];
  unset($params['contact_id']);
  $sql = 'SELECT civicrm_activity.id FROM civicrm_activity';
  if (!empty($params['case_id'])) {
    $sql .= ' INNER JOIN civicrm_case_activity ON civicrm_activity.id = civicrm_case_activity.activity_id';
  }
  $sql .= " WHERE is_current_revision = 1 AND is_deleted = 0 AND (civicrm_activity.id IN (SELECT activity_id FROM civicrm_activity_target WHERE target_contact_id = $cid) OR source_contact_id = $cid)";
  foreach ($params as $field => $value) {
    if ($value && is_array($value)) {
      $sql .= " AND $field IN (" . implode(',', $value) . ")";
    }
    elseif ($value) {
      $sql .= " AND $field = $value";
    }
  }
  $sql .= ' LIMIT 0, 1';
  return CRM_Core_DAO::singleValueQuery($sql);
}

/**
 * Fetch info and remaining spaces for events
 */
function webform_civicrm_event_info(&$events) {
  if (!empty($events)) {
    require_once 'CRM/Event/BAO/Participant.php';
    $now = time();
    // Fetch event info
    $dao = &CRM_Core_DAO::executeQuery('SELECT id, title, end_date, max_participants
      FROM civicrm_event WHERE id IN (' . implode(',', array_keys($events)) . ')');
    while ($dao->fetch()) {
      $events[$dao->id]['title'] = $dao->title;
      $events[$dao->id]['end_date'] = $dao->end_date;
      $events[$dao->id]['full'] = FALSE;
      $events[$dao->id]['ended'] = $dao->end_date && strtotime($dao->end_date) < $now;
      if ($events[$dao->id]['max_participants'] = $dao->max_participants) {
        $remaining = CRM_Event_BAO_Participant::eventFull($dao->id, TRUE, FALSE);
        if (is_string($remaining)) {
          $events[$dao->id]['full'] = TRUE;
          $events[$dao->id]['remaining'] = 0;
          $events[$dao->id]['full_message'] = $remaining;
        }
        else {
          $events[$dao->id]['remaining'] = $remaining ? $remaining : $dao->max_participants;
        }
      }
    }
  }
}

/**
 * Check if a name or email field exists for this contact.
 * This determines whether a new contact can be created on the webform.
 */
function webform_civicrm_name_field_exists($enabled, $c) {
  foreach (webform_civicrm_required_contact_fields() as $f) {
    $fid = 'civicrm_' . $c . '_contact_1_' . $f['table'] . '_' . $f['name'];
    if (!empty($enabled[$fid])) {
      return 1;
    }
  }
  return 0;
}

/**
 * Fetch the path of a file
 * And format it as a public url that CiviCRM can read
 */
function webform_civicrm_filepath($id) {
  if ($file = file_load($id)) {
    global $base_url;
    // Escape all characters except : and /
    return str_replace('public://', $base_url . '/' . variable_get('file_public_path', conf_path() . '/files') . '/', str_replace(array('%3A', '%2F'), array(':', '/'), rawurlencode($file->uri)));
  }
}
