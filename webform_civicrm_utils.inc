<?php

/**
 * @file
 * Webform CiviCRM module's utility functions.
 */


/**
 * Create or update CiviCRM contact
 * Called by both presave and insert webform hooks in order to handle the optional contact_id field correctly
 */
function webform_civicrm_contact_match($node, &$submission) {
  static $cids = array();
  if ($cids) {
    return $cids;
  }
  civicrm_initialize();
  require_once 'api/v2/Contact.php';

  $enabled = webform_civicrm_enabled_fields($node);
  $fields = webform_civicrm_get_fields();
  $sets = webform_civicrm_get_fields('sets');
  $settings = $node->webform_civicrm;
  $data = $settings['data'];
  global $user;

  // Fill contact arrays with field values
  foreach ($enabled as $fid => $field) {
    list($lobo, $c, $ent, $n, $table, $field) = explode('_', $fid, 6);
    if ($val = $submission->data[$enabled[$table .'_'. $field]]['value']) {
      if (count($val) > 1) {
        $val = drupal_map_assoc($val);
      }
      else {
        $val = $val[0];
      }
      if ($val !== NULL) {
        $data[$ent][$c][$table][$n][$field] = $val;
      }
    }
  }

  // If this is an update op, just use CIDs from original submission to avoid confusion
  if ($submission->sid) {
    if ($old = db_result(db_query('SELECT contact_id FROM {webform_civicrm_submissions} WHERE sid = '. $submission->sid))) {
      $old = explode('-', $old['contact_id']);
      $n = 1;
      foreach ($old as $cid) {
        $cids[$n] = $cid;
        $data['contact'][$n]['contact'][1]['contact_id'] = $cid;
        ++$n;
      }
    }
  }
  // Match contact 1 to logged in user if contact 1 is an individual
  if (!$cids[1] && $data['contact'][1]['contact'][1]['contact_type'] = 'individual' && $user->uid) {
    require_once 'CRM/Core/BAO/UFMatch.php';
    $cids[1] = CRM_Core_BAO_UFMatch::getContactId($user->uid);
  }
  elseif ($_SESSION['webform_civicrm_cid'] && $_SESSION['webform_civicrm_cs']) {
    require_once 'CRM/Contact/BAO/Contact/Utils.php';
    if (CRM_Contact_BAO_Contact_Utils::validChecksum($_SESSION['webform_civicrm_cid1'], $_SESSION['webform_civicrm_cs'])) {
      $cids[1] = $_SESSION['webform_civicrm_cid1'];
    }
  }
  foreach ($data['contact'] as $c => $contact) {
    // Search for an existing contact using default strict rule
    if (!$cids[$c]) {
      require_once 'CRM/Dedupe/Finder.php';
      $params = array(
        'civicrm_contact' => $contact['contact'][1],
        'civicrm_address' => $contact['address'],
        'civicrm_phone' => $contact['phone'],
        'civicrm_email' => $contact['email'],
        'civicrm_website' => $contact['website'],
      );
      if ($dupes = CRM_Dedupe_Finder::dupesByParams($params, $contact['contact'][1]['contact_type'])) {
        $cids[$c] = $dupes[0];
      }
    }

    // TODO: how to handle custom fields
    $op = 'update';
    if (!$cid) {
      $result = civicrm_contact_add($params['civicrm_contact']);
      $cid = $result['contact_id'];
      $op = 'create';
    }
    if ($cid) {
      $params['civicrm_contact']['contact_id'] = $cids[$c];
      $params['civicrm_contact']['check_permission'] = FALSE;
      civicrm_contact_update($params['civicrm_contact']);
    }
    else {
      // If an error occurred and we couldn't create contact, stop here.
      return;
    }
    // Fill values for hidden ID fields
    if ($enabled[$c .'_1_civicrm_contact_contact_id']) {
      $submission->data[$enabled[$c .'_1_civicrm_contact_contact_id']]['value'] = array($cid);
    }
    if ($enabled[$c .'_1_civicrm_contact_external_identifier'] && $op=='update') {
      $get_params = array('contact_id' => $cid, 'return_external_identifier' => 1);
      $fetched = civicrm_contact_get($get_params);
      $submission->data[$enabled[$c .'_1_civicrm_contact_external_identifier']]['value'] = array($fetched[$cid]['external_identifier']);
    }

    // Add or update location
    $params = array();
    foreach (array('address', 'phone', 'email', 'website') as $loc) {
      if ($contact[$loc]) {
        $params[$loc] = $contact[$loc];
        $params[$loc][1]['is_primary'] = 1;
      }
    }
    if ($params) {
      require_once 'api/v2/Location.php';
      $params['contact_id'] = $cids[$c];
      $params['version'] = '3.0';

      // Update existing address
      $result = civicrm_location_update($params);
      // Or create a new one
      if ($result['is_error']) {
        $result = civicrm_location_add($params);
      }
    }
    // Process groups
    if ($enabled['civicrm_groups'] || $enabled['civicrm_groups_hidden']) {
      require_once 'api/v2/GroupContact.php';

      if ($enabled['civicrm_groups']) {
        $add_groups = $submission->data[$enabled['civicrm_groups']]['value'];
        $all_groups = explode("\n", $node->webform['components'][$enabled['civicrm_groups']]['extra']['items']);
        $remove_groups = array();
        foreach ($all_groups as $g) {
          list($gid, $group) = explode('|', $g);
          $remove_groups[$gid] = $group;
        }
      }
      if ($enabled['civicrm_groups_hidden']) {
        $add_hidden = explode(',', $submission->data[$enabled['civicrm_groups_hidden']]['value'][0]);
        if ($add_groups) {
          $add_groups = array_unique(array_merge($add_groups, $add_hidden));
        }
        else {
          $add_groups = $add_hidden;
        }
      }

      if ($add_groups) {
        // Prepare for sending subscription confirmations
        $confirmations_sent = array();
        if ($node->webform_civicrm['confirm_subscription']) {
          $get_params = array('contact_id' => $cid, 'return_email' => 1);
          $fetched = civicrm_contact_get($get_params);
          $mailer_params = array('contact_id' => $cid, 'email' => $fetched[$cid]['email']);
          $mailing_lists = webform_civicrm_get_options('mailing_lists', 'arr');
        }
        else {
          $mailing_lists = array();
        }
        $group_params = array('contact_id.1' => $cid);
        if (!empty($add_groups)) {
          foreach ($add_groups as $gid) {
            $group_params['group_id'] = $mailer_params['group_id'] = (int)$gid;
            unset($remove_groups[$gid]);
            if (array_key_exists($gid, $mailing_lists)) {
              webform_civicrm_mailer_event_subscribe($mailer_params);
              $confirmations_sent[] = $mailing_lists[$gid];
            }
            else {
              civicrm_group_contact_add($group_params);
            }
          }
        }
        if (!empty($confirmations_sent)) {
          drupal_set_message(t('A confirmation has been sent to your email address, please follow the instructions in that message to finalize your subscription to @groups.', array('@groups' => implode(' '. t('and') .' ', $confirmations_sent))));
        }
      }
      // Remove contact from groups if they have unchecked them on the form
      if ($remove_groups && $op=='update') {
        $search_params = array('contact_id' => $cid);
        $groups = civicrm_group_contact_get($search_params);
        $in_groups = array();
        foreach ($groups as $group) {
          $in_groups[] = $group['group_id'];
        }
        $group_params = array('contact_id.1' => $cid);
        foreach ($remove_groups as $group_params['group_id'] => $group) {
          if (in_array($group_params['group_id'], $in_groups)) {
            civicrm_group_contact_remove($group_params);
            drupal_set_message(t('You have removed yourself from') .' '. $group .'.');
          }
        }
      }
    }
    // Process tags
    if ($enabled['civicrm_tags']) {
      require_once 'api/v2/Tag.php';
      require_once 'api/v2/EntityTag.php';
      $add_tags = drupal_explode_tags($submission->data[$enabled['civicrm_tags']]['value'][0]);
      foreach ($add_tags as $tag) {
        $params = array('name' => $tag);
        $result = civicrm_tag_get($params);
        // Create tag if it doesn't exist
        if (!($id = $result['id'])) {
          $result = civicrm_tag_create($params);
          $id = $result['tag_id'];
        }
        $params = array('tag_id' => $id, 'contact_id' => $cid);
        civicrm_entity_tag_add($params);
      }
    }
  }
  return $cids;
}


/**
 * Get options from various civicrm tables
 * @Param $option_group: option group name or id
 * @Param $var: Can return values as a Webform-style string, or a FAPI style array. Specify 'str' or 'arr', matey!
 * @Param $null_label: If returning FAPI array, provide label if first option should be "none"
 */
function webform_civicrm_get_options($option_group, $var='str', $null_label='') {
  $params = array();
  if ($option_group == 'country') {
    $config = CRM_Core_Config::singleton();
    $enabled = implode(',', $config->countryLimit);
    $sql = "SELECT name AS label, id AS value FROM civicrm_country";
    if ($enabled) {
      $sql .=  " WHERE id IN ($enabled)";
    }
    $sql .= ' ORDER BY name';
  }
  elseif ($option_group == 'state') {
    $config = CRM_Core_Config::singleton();
    $sql = 'SELECT name AS label, id AS value FROM civicrm_state_province WHERE country_id = '. $config->defaultContactCountry .' ORDER BY name';
  }
  elseif ($option_group == 'groups' || $option_group == 'mailing_lists') {
    $sql = 'SELECT id AS value, title AS label FROM civicrm_group WHERE is_active = 1';
    if ($option_group == 'mailing_lists') {
      $sql .= " AND group_type LIKE '%2%' AND visibility = 'Public Pages'";
    }
  }
  elseif ($option_group == 'location_type') {
    $sql = 'SELECT name AS label, id AS value FROM civicrm_location_type WHERE is_active <> 0 ORDER BY is_default DESC';
  }
  else {
    $sql = 'SELECT value, label FROM civicrm_option_value WHERE is_active <> 0 AND option_group_id = ';
    if (is_numeric($option_group)) {
      $sql .= '%1';
      $params[1] = array($option_group, 'Integer');
    }
    else {
      $sql .= "(SELECT id FROM civicrm_option_group WHERE name = %1)";
      $params[1] = array($option_group, 'String');
    }
    // Exclude reserved activity types
    if ($option_group == 'activity_type') {
      $sql .= ' AND component_id IS NULL';
    }
    $sql .= ' ORDER BY weight, label';
  }
  $dao =& CRM_Core_DAO::executeQuery($sql, $params);
  $arr = array();
  if ($null_label) {
    $arr[] = $null_label;
  }
  $str = '';
  while ($dao->fetch()) {
    $arr[$dao->value] = $dao->label;
    $str .= ($str?"\n":'') . $dao->value .'|'. $dao->label;
  }
  return $$var;
}

/**
 * Get contact types and sub-types
 */
function webform_civicrm_get_contact_types() {
  $sql = "
    SELECT LOWER(c1.name) AS name, c1.label, LOWER(c2.name) AS parent_type
    FROM civicrm_contact_type c1
    LEFT JOIN civicrm_contact_type c2 ON c1.parent_id  = c2.id
    WHERE c1.is_active = 1
    ORDER BY c1.parent_id ASC";
  $dao = &CRM_Core_DAO::executeQuery($sql);
  $contact_types = $sub_types = array();
  while ($dao->fetch()) {
    if ($dao->parent_type) {
      $sub_types[$dao->parent_type][$dao->name] = $dao->label;
    }
    else {
      $contact_types[$dao->name] = $dao->label;
      $sub_types[$dao->name] = array();
    }
  }
  return array($contact_types, $sub_types);
}

/**
 * Get valid relationship types for given contact types and sub_types
 */
function webform_civicrm_get_relationship_types($contact_type_a, $contact_type_b, $sub_type_a = NULL, $sub_type_b = NULL) {
  static $types = array();
  if (!$types) {
    $sql = "
      SELECT id, name_a_b, name_b_a, label_a_b, label_b_a, contact_type_a, contact_type_b, contact_sub_type_a, contact_sub_type_b
      FROM civicrm_relationship
      WHERE is_active <> 0";
    $dao = &CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $types[$dao->id] = (array) $dao;
    }
  }
  $ret = array();
  foreach ($types as $t) {
    if (($t['contact_type_a'] == $contact_type_a || !$t['contact_type_a'])
      && ($t['contact_type_b'] == $contact_type_b || !$t['contact_type_b'])
      && ($t['contact_sub_type_a'] == $sub_type_a || !$t['contact_sub_type_a'])
      && ($t['contact_sub_type_b'] == $sub_type_b || !$t['contact_sub_type_b'])
    ) {
      $ret[$t['id'] .'_a'] = $t['label_a_b'];
    }
    if (($t['contact_type_a'] == $contact_type_a || !$t['contact_type_a'])
      && ($t['contact_type_b'] == $contact_type_b || !$t['contact_type_b'])
      && ($t['contact_sub_type_a'] == $sub_type_a || !$t['contact_sub_type_a'])
      && ($t['contact_sub_type_b'] == $sub_type_b || !$t['contact_sub_type_b'])
      && ($t['name_a_b'] != $t['name_b_a'])
    ) {
      $ret[$t['id'] .'_b'] = $t['label_b_a'];
    }
  }
  return $ret;
}

/**
 * Handle various AHAH requests.
 */
function webform_civicrm_js_options($key, $str='') {
  if (!user_access('access CiviCRM')) {
    return drupal_access_denied();
  }
  // Serve AHAH requests for config form
  if (substr($key, 0, 12) == 'civicrm_ahah') {
    require_once 'webform_civicrm_forms.inc';
    $snippet = webform_civicrm_process_ahah_form();

    // Retrieve the portion of form to fetch (array keys are passed in as a . separated string)
    $keys = explode('.', substr($key, 13));
    foreach($keys as $key) {
      $snippet = $snippet[$key];
    }
    unset($snippet['#prefix'], $snippet['#suffix']);
    $output = theme('status_messages');
    $output .= drupal_render($snippet);
    exit(drupal_json(array('status' => TRUE, 'data' => $output)));
  }

  civicrm_initialize();
  // Autocomplete callback for tags
  if ($key == 'tags') {
    if (!$str) {
      exit();
    }
    // Handle a comma-separated list of tags.
    $existing = drupal_explode_tags($str);
    $last_str = trim(array_pop($existing));
    $matches = array();
    $prefix = count($existing) ? implode(', ', $existing) .', ' : '';
    $params = array(1 => array('%'. $last_str .'%', 'String'));
    $sql = "SELECT name FROM civicrm_tag WHERE is_selectable = 1 AND used_for = 'civicrm_contact' AND name LIKE %1 LIMIT 0, 12";
    $dao =& CRM_Core_DAO::executeQuery($sql, $params);
    while ($dao->fetch()) {
      $matches[$prefix . $dao->name] = check_plain($dao->name);
    }
    exit(drupal_json($matches));
  }
  // Get option lists
  $lists = webform_civicrm_get_fields('lists');
  if (array_key_exists($key, $lists)) {
    print webform_civicrm_get_options($lists[$key]);
    exit();
  }
  return drupal_not_found();
}


/**
 * Get ids of enabled CiviCRM fields for a webform.
 * @Param $node - node object
 */
function webform_civicrm_enabled_fields($node, $format = 'cid') {
  if (!is_array($node->webform['components'])) {
    return;
  }
  $fields = webform_civicrm_get_fields();
  $enabled = array();
  foreach ($node->webform['components'] as $c) {
    $exp = explode('_', $c['form_key'], 5);
      if (count($exp) == 5) {
      list($lobo, $i, $ent, $n, $id) = $exp;
      if ((array_key_exists($id, $fields) || $id == 'fieldset_fieldset') && $lobo == 'civicrm' && is_numeric($i) && is_numeric($n)) {
        if ($format == 'cid') {
          $enabled[$c['form_key']] = $c['cid'];
        }
        else {
          $enabled[$c['form_key']] = 'create_civicrm_webform_element';
        }
      }
    }
  }
  return $enabled;
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
    // Field keys are in the format table_column
    $fields['contact_contact_sub_type'] = array(
      'name' => t('Type of'),
      'type' => 'select',
      'extra' => array('multiple' => 1, 'aslist' => 0),
    );
    $fields['contact_first_name'] = array(
      'name' => t('First Name'),
      'type' => 'textfield',
      'mandatory' => 1,
      'contact_type' => 'individual',
    );
    $fields['contact_nick_name'] = array(
      'name' => t('Nick Name'),
      'type' => 'textfield',
      'contact_type' => 'individual',
    );
    $fields['contact_middle_name'] = array(
      'name' => t('Middle Name'),
      'type' => 'textfield',
      'contact_type' => 'individual',
    );
    $fields['contact_last_name'] = array(
      'name' => t('Last Name'),
      'type' => 'textfield',
      'mandatory' => 1,
      'contact_type' => 'individual',
    );
    $fields['contact_prefix_id'] = array(
      'name' => t('Name Prefix'),
      'type' => 'select',
      'contact_type' => 'individual',
    );
    $fields['contact_suffix_id'] = array(
      'name' => t('Name Suffix'),
      'type' => 'select',
      'contact_type' => 'individual',
    );
    $fields['contact_gender_id'] = array(
      'name' => t('Gender'),
      'type' => 'select',
      'contact_type' => 'individual',
    );
    $fields['contact_birth_date'] = array(
      'name' => t('Birth Date'),
      'type' => 'date',
      'extra' => array(
        'year_start' => '-100',
        'year_end' => '-1',
        'timezone' => 'user',
      ),
      'contact_type' => 'individual',
    );
    $fields['contact_job_title'] = array(
      'name' => t('Job Title'),
      'type' => 'textfield',
      'contact_type' => 'individual',
    );
    $fields['contact_organization_name'] = array(
      'name' => t('Organization Name'),
      'type' => 'textfield',
      'mandatory' => 1,
      'contact_type' => 'organization',
    );
    $fields['contact_household_name'] = array(
      'name' => t('Household Name'),
      'type' => 'textfield',
      'mandatory' => 1,
      'contact_type' => 'household',
    );
    $fields['contact_contact_id'] = array(
      'name' => t('Contact ID'),
      'type' => 'hidden',
      'extra' => array('description' => t('(hidden field, useful for post-processing)')),
    );
    $fields['contact_external_identifier'] = array(
      'name' => t('External ID'),
      'type' => 'hidden',
      'extra' => array('description' => t('(hidden field, useful for post-processing)')),
    );
    $fields['contact_preferred_communication_method'] = array(
      'name' => t('Preferred Communication Method(s)'),
      'type' => 'select',
      'extra' => array('multiple' => 1),
    );
    $fields['email_email'] = array(
      'name' => t('Email'),
      'type' => 'email',
    );
    $fields['email_location_type_id'] = array(
      'name' => t('Email Location Type'),
      'type' => 'select',
      'expose_list' => TRUE,
    );
    $fields['address_street_address'] = array(
      'name' => t('Street Address'),
      'type' => 'textfield',
      'extra' => array('width' => 60),
    );
    $fields['address_supplemental_address_1'] = array(
      'name' => t('Street Address Line 2'),
      'type' => 'textfield',
      'extra' => array('width' => 60),
    );
    $fields['address_supplemental_address_2'] = array(
      'name' => t('Street Address Line 3'),
      'type' => 'textfield',
      'extra' => array('width' => 60),
    );
    $fields['address_city'] = array(
      'name' => t('City'),
      'type' => 'textfield',
    );
    $fields['address_state_province'] = array(
      'name' => t('State/Province Abbr.'),
      'type' => 'textfield',
      'extra' => array(
        'maxlength' => 4,
        'width' => 4,
        'description' => t('Enter state/province abbreviation'),
      ),
    );
    $fields['address_state_province_id'] = array(
      'name' => t('State/Province Select'),
      'type' => 'select',
      'extra' => array('description' => t('Select state/province from list')),
    );
    $fields['address_postal_code'] = array(
      'name' => t('Postal Code'),
      'type' => 'textfield',
      'extra' => array('width' => 10),
    );
    $fields['address_country_id'] = array(
      'name' => t('Country'),
      'type' => 'select',
    );
    $fields['address_location_type_id'] = array(
      'name' => t('Address Location Type'),
      'type' => 'select',
      'expose_list' => TRUE,
    );
    $fields['phone_phone'] = array(
      'name' => t('Phone Number'),
      'type' => 'textfield',
    );
    $fields['phone_phone_type_id'] = array(
      'name' => t('Phone Type'),
      'type' => 'select',
      'table' => 'phone',
      'expose_list' => TRUE,
    );
    $fields['phone_location_type_id'] = array(
      'name' => t('Phone Location Type'),
      'type' => 'select',
      'expose_list' => TRUE,
    );
    $fields['website_website'] = array(
      'name' => t('Website'),
      'type' => 'textfield',
    );
    $fields['website_website_type_id'] = array(
      'name' => t('Website Type'),
      'type' => 'select',
      'expose_list' => TRUE,
    );
    $fields['other_groups'] = array(
      'name' => t('Select Group(s)'),
      'type' => 'select',
      'extra' => array(
        'multiple' => 1,
        'description' => t('Users can add/remove themselves from CiviCRM groups (mailing lists, etc)'),
      ),
      'table' => 'group',
    );
    $fields['other_groups_hidden'] = array(
      'name' => t('Auto-Add Group(s)'),
      'type' => 'hidden',
      'extra' => array('description' => t('Hidden form element will add contacts to group(s) of your choosing')),
      'table' => 'group',
    );
    $fields['other_tags'] = array(
      'name' => t('Tags'),
      'type' => 'hidden',
      'extra' => array('description' => t('Hidden form element to add tags to contacts')),
      'table' => 'tag',
    );
    $fields['activity_subject'] = array(
      'name' => t('Activity Subject'),
      'type' => 'textfield',
      'extra' => array('width' => 60),
    );
    $fields['activity_details'] = array(
      'name' => t('Activity Details'),
      'type' => 'textarea',
    );

    $tokens = array(
      'display_name'        => t('display name'),
      'first_name'          => t('first name'),
      'nick_name'           => t('nick name'),
      'middle_name'         => t('middle name'),
      'last_name'           => t('last name'),
      'individual_prefix'   => t('name prefix'),
      'individual_suffix'   => t('name suffix'),
      'gender'              => t('gender'),
      'birth_date'          => t('birth date'),
      'job_title'           => t('job title'),
      'current_employer'    => t('current employer'),
      'contact_id'          => t('contact id'),
      'street_address'      => t('street address'),
      'city'                => t('city'),
      'state_province'      => t('state/province abbr'),
      'state_province_name' => t('state/province full'),
      'postal_code'         => t('postal code'),
      'country'             => t('country'),
      'world_region'        => t('world region'),
      'phone'               => t('phone number'),
      'email'               => t('email'),
    );

    $lists = array(
      'prefix_id'                      => 'individual_prefix',
      'suffix_id'                      => 'individual_suffix',
      'gender_id'                      => 'gender',
      'preferred_communication_method' => 'preferred_communication_method',
      'country_id'                     => 'country',
      'state_province_id'              => 'state',
      'phone_type_id'                  => 'phone_type',
      'location_type_id'               => 'location_type',
      'website_type_id'                => 'website_type',
    );

    $sets = array(
      'contact' => array('entity_type' => 'contact', 'label' => t('Contact Fields')),
      'other' => array('entity_type' => 'contact', 'label' => t('Tags and Groups')),
      'address' => array('entity_type' => 'contact', 'label' => t('Address'), 'max_instances' => 9),
      'phone' => array('entity_type' => 'contact', 'label' => t('Phone'), 'max_instances' => 9),
      'email' => array('entity_type' => 'contact', 'label' => t('Email'), 'max_instances' => 9),
      'website' => array('entity_type' => 'contact', 'label' => t('Website'), 'max_instances' => 9),
      'activity' => array('entity_type' => 'activity', 'label' => t('Expose Activity Fields')),
    );

    // Pull custom fields and match to Webform element types
    $custom_types = array(
      'Select'                      => array('type' => 'select'),
      'Multi-Select'                => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'AdvMulti-Select'             => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'Radio'                       => array('type' => 'select', 'extra' => array('aslist' => 0)),
      'CheckBox'                    => array('type' => 'select', 'extra' => array('multiple' => 1, 'aslist' => 0)),
      'Text'                        => array('type' => 'textfield'),
      'TextArea'                    => array('type' => 'textarea'),
      'RichTextArea'                => array('type' => 'textarea'),
      'Select Date'                 => array('type' => 'date'),
      'Link'                        => array('type' => 'textfield'),
      'Select Country'              => array('type' => 'select'),
      'Multi-Select Country'        => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'Select State/Province'       => array('type' => 'select'),
      'Multi-Select State/Province' => array('type' => 'select', 'extra' => array('multiple' => 1)),
    );
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    $sql = "
      SELECT cf.*, cg.title AS custom_group_name, LOWER(cg.extends) AS entity_type, LOWER(cg.extends_entity_column_value) AS sub_types, cg.is_multiple, cg.max_multiple, cg.id AS custom_group_id
      FROM civicrm_custom_field cf
      INNER JOIN civicrm_custom_group cg ON cg.id = cf.custom_group_id
      WHERE cf.is_active <> 0 AND cg.extends IN ('contact','individual','organization','household','address','activity') AND cg.is_active <> 0
      ORDER BY cf.custom_group_id, cf.weight";
    $dao =& CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if (!$custom_types[$dao->html_type]) {
        continue;
      }
      if ($dao->entity_type == 'address') {
        $set = 'address';
      }
      else {
        $set = 'cg'. $dao->custom_group_id;
        $sets[$set]['label'] = $dao->custom_group_name;
        if ($dao->entity_type != 'activity') {
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
          $sets[$set]['entity_type'] = 'activity';
        }
        if ($dao->sub_types) {
          $sets[$set]['sub_types'] = explode($sp, trim($dao->sub_types, $sp));
        }
      }
      $id = $set .'_custom_'. $dao->id;
      $label = drupal_strtolower($dao->label);
      $fields[$id] = $custom_types[$dao->html_type];
      $fields[$id]['name'] = $dao->label;
      $fields[$id]['mandatory'] = $dao->is_required;
      $fields[$id]['extra']['description'] = $dao->help_pre;
      $fields[$id]['value'] = str_replace($sp, ',', trim($dao->default_value, $sp));

      if ($fields[$id]['type'] == 'date') {
        $fields[$id]['extra']['year_start'] = $dao->start_date_years ? '-'. $dao->start_date_years : '-50';
        $fields[$id]['extra']['year_end'] = $dao->end_date_years ? '+'. $dao->end_date_years : '+50';
      }
      elseif ($og = $dao->option_group_id) {
        $lists[$id] = $og;
      }
      elseif ($dao->html_type == 'Select Country' || $dao->html_type == 'Multi-Select Country') {
        $lists[$id] = 'country';
      }
      elseif ($dao->html_type == 'Select State/Province' || $dao->html_type == 'Multi-Select State/Province') {
        $lists[$id] = 'state';
      }
      elseif ($fields[$id]['type'] == 'select') {
        $fields[$id]['extra']['items'] = '1|'. t('Yes') ."\n0|". t('No');
      }
      elseif ($fields[$id]['type'] == 'textarea') {
        $fields[$id]['extra']['cols'] = $dao->note_columns;
        $fields[$id]['extra']['rows'] = $dao->note_rows;
      }
    }
  }
  return $$var;
}


/**
 * Explodes form key into an array and verifies that it has the right number of pieces
 */
function webform_civicrm_explode_key($key) {
  $pieces = explode('_', $key, 6);
  if (count($pieces) != 6) {
    return FALSE;
  }
  return $pieces;
}

/**
 * Token replacement for form messages
 */
function webform_civicrm_replace_tokens($str, $contact) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  $tokens = webform_civicrm_get_fields('tokens');
  $values = array();
  foreach ($tokens as $k => &$t) {
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
 * Displays the admin-defined message with "not you?" link to known contacts
 */
function webform_civicrm_set_message($message, $contact) {
  $message = webform_civicrm_replace_tokens($message, $contact);
  preg_match_all('#\{([^}]+)\}#', $message, $matches);
  if ($matches[0]) {
    foreach ($matches[0] as $pos => $match) {
      if (user_is_logged_in()) {
        $link = l($matches[1][$pos], 'logout', array('query' => 'destination='. $_GET['q']));
      }
      else {
        $link = l($matches[1][$pos], $_GET['q']);
      }
      $message = str_replace($match, $link, $message);
    }
  }
  drupal_set_message($message);
}


/**
 * Loads a form during AHAH callback.
 * (This will be part of Drupal API in D7)
 */
function webform_civicrm_process_ahah_form() {
  $form_state = array('storage' => NULL, 'submitted' => FALSE);
  $form_build_id = $_POST['form_build_id'];
  $form = form_get_cache($form_build_id, $form_state);
  $args = $form['#parameters'];
  $form_id = array_shift($args);
  $form_state['post'] = $form['#post'] = $_POST;
  // Enable the submit/validate handlers to determine whether AHAH-submittted.
  $form_state['ahah_submission'] = TRUE;
  $form['#programmed'] = $form['#redirect'] = FALSE;
  drupal_process_form($form_id, $form, $form_state);
  $form = drupal_rebuild_form($form_id, $form_state, $args, $form_build_id);
  return $form;
}