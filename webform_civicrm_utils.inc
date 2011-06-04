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
  static $cid = 0;
  if ($cid) {
    return $cid;
  }
  civicrm_initialize();
  require_once 'api/api.php';

  $enabled = webform_civicrm_enabled_fields($node);
  $fields = webform_civicrm_get_fields();
  $params = array('check_permission' => FALSE);
  $table = 'contact';

  // Convert flat array to multidimensional array based on civi table names
  // This is why the order is important in webform_civicrm_get_fields()
  foreach ($fields as $fid => $field) {
    $field = str_replace('civicrm_', '', $fid);
    if ($field == 'street_address') {
      $table = 'address';
    }
    elseif ($field == 'email') {
      $table = 'email';
    }
    elseif ($field == 'phone') {
      $table = 'phone';
    }
    elseif ($field == 'groups' || $field == 'groups_hidden' || $field == 'tags') {
      $table = 'custom';
      continue;
    }
    if (isset($enabled[$fid])) {
      $val = $submission->data[$enabled[$fid]]['value'];
      if (count($val) > 1) {
        $params['civicrm_' . $table][$field] = drupal_map_assoc($val);
      }
      elseif (isset($val[0])) {
        $params['civicrm_' . $table][$field] = $val[0];
      }
    }
  }
  if (isset($params['civicrm_custom'])) {
    $custom = $params['civicrm_custom'];
    unset($params['civicrm_custom']);
  }
  global $user;

  // If this is an update op, just use CID from original submission to avoid confusion
  if (isset($submission->sid)) {
    $db = db_query('SELECT contact_id FROM {webform_civicrm_submissions} WHERE sid = :sid', array(':sid' => $submission->sid));
    foreach ($db as $existing) {
      if ($cid = $existing['contact_id']) {
        break;
      }
    }
  }
  if (!$cid) {
    // Try to match an existing contact to this submission. Logged-in user takes priority.
    if ($user->uid) {
      $result = civicrm_api('uf_match', 'get', array('uf_id' => $user->uid, 'check_permissions' => FALSE, 'version' => 3));
      if (is_array($result['values'])) {
        $result = array_pop($result['values']);
        $cid = $result['contact_id'];
      }
    }
    elseif (!empty($_SESSION['webform_civicrm_cid']) && !empty($_SESSION['webform_civicrm_cs'])) {
      require_once 'CRM/Contact/BAO/Contact/Utils.php';
      if (CRM_Contact_BAO_Contact_Utils::validChecksum($_SESSION['webform_civicrm_cid'], $_SESSION['webform_civicrm_cs'])) {
        $cid = $_SESSION['webform_civicrm_cid'];
      }
      unset ($_SESSION['webform_civicrm_cid'], $_SESSION['webform_civicrm_cs']);
    }
    // Search for an existing contact using default strict rule
    if (!$cid) {
      require_once 'CRM/Dedupe/Finder.php';
      if ($dupes = CRM_Dedupe_Finder::dupesByParams($params, 'Individual')) {
        $cid = $dupes[0];
      }
    }
  }
  $params['civicrm_contact']['contact_type'] = 'Individual';
  $params['civicrm_contact']['version'] = 3;
  $params['civicrm_contact']['check_permissions'] = FALSE;

  // Update or create new contact.
  if (isset($custom)) {
    $params['civicrm_contact'] = array_merge($params['civicrm_contact'], $custom);
  }
  if (!$cid) {
    $result = civicrm_api('contact', 'create', $params['civicrm_contact']);
    $cid = $result['id'];
    $op = 'create';
  }
  else {
    $params['civicrm_contact']['contact_id'] = $cid;
    civicrm_api('contact', 'create', $params['civicrm_contact']);
    $op = 'update';
  }
  if (!$cid) {
    // If an error occurred and we couldn't create contact, stop here.
    return;
  }
  // Fill values for hidden ID fields
  if (isset($enabled['civicrm_contact_id'])) {
    $submission->data[$enabled['civicrm_contact_id']]['value'] = array($cid);
  }
  if (isset($enabled['civicrm_external_identifier']) && $op == 'update') {
    $exid = civicrm_api('contact', 'get', array('check_permissions' => FALSE, 'version' => 3, 'contact_id' => $cid, 'return_external_identifier' => 1));
    if (is_array($exid['values'])) {
      $exid = array_pop($exid['values']);
      $submission->data[$enabled['civicrm_external_identifier']]['value'] = array($exid['external_identifier']);
    }
  }

  // Save address, email, and phone
  foreach (array('address', 'email', 'phone') as $location) {
    if (array_key_exists('civicrm_' . $location, $params)) {
      $address_params = $params['civicrm_' . $location];
      $address_params['check_permissions'] = FALSE;
      $address_params['version'] = 3;
      $address_params['contact_id'] = $cid;
      $address_params['is_primary'] = $address_params['location_type_id'] = 1;
      if (!isset($address_params['phone_type_id']) && $location == 'phone') {
        $address_params['phone_type_id'] = 1;
      }
      $result = civicrm_api($location, 'get', array('check_permissions' => FALSE, 'version' => 3, 'contact_id' => $cid));
      if (!empty($result['values'])) {
        foreach ($result['values'] as $loc) {
          if ($loc['location_type_id'] == 1) {
            if ($location == 'phone') {
              if ($address_params['phone_type_id'] != $loc['phone_type_id']) {
                continue;
              }
            }
            $address_params['id'] = $loc['id'];
            break;
          }
        }
      }
      civicrm_api($location, 'create', $address_params);
    }
  }
  // Update Drupal user->mail
  if (!empty($user->uid) && !empty($params['civicrm_email']['email'])) {
    db_update('users')
    ->fields(array('mail' => $params['civicrm_email']['email']))
    ->condition('uid', $user->uid)
    ->execute();
  }

  // Process groups
  if (isset($enabled['civicrm_groups']) || isset($enabled['civicrm_groups_hidden'])) {
    $add_groups = $add_hidden = $remove_groups = $all_groups = array();
    if (isset($enabled['civicrm_groups'])) {
      $add_groups = $submission->data[$enabled['civicrm_groups']]['value'];
      $all_groups = explode("\n", $node->webform['components'][$enabled['civicrm_groups']]['extra']['items']);
      foreach ($all_groups as $g) {
        if (!empty($g)) {
          list($gid, $group) = explode('|', $g);
          $remove_groups[$gid] = $group;
        }
      }
    }
    if (isset($enabled['civicrm_groups_hidden'])) {
      $add_hidden = explode(',', $submission->data[$enabled['civicrm_groups_hidden']]['value'][0]);
      if (!empty($add_groups)) {
        $add_groups = array_unique(array_merge($add_groups, $add_hidden));
      }
      else {
        $add_groups = $add_hidden;
      }
    }

    if (!empty($add_groups)) {
      // Prepare for sending subscription confirmations
      $confirmations_sent = array();
      $mailing_lists = array();
      if (!empty($node->webform_civicrm['confirm_subscription'])) {
        $contact = civicrm_api('contact', 'get', array('check_permissions' => FALSE, 'version' => 3, 'contact_id' => $cid, 'return_email' => 1));
        $contact = array_pop($contact['values']);
        $mailer_params = array(
          'check_permissions' => FALSE,
          'version' => 3,
          'contact_id' => $cid,
          'email' => $contact['email'],
        );
        if (!empty($contact['email'])) {
          $mailing_lists = webform_civicrm_get_options('mailing_lists', 'arr');
        }
      }
      $group_params = array('contact_id' => $cid, 'check_permissions' => FALSE, 'version' => 3);
      if (!empty($add_groups)) {
        foreach ($add_groups as $gid) {
          $group_params['group_id'] = $mailer_params['group_id'] = (int) $gid;
          unset($remove_groups[$gid]);
          if (array_key_exists($gid, $mailing_lists)) {
            civicrm_api('mailing_group', 'event_subscribe', $mailer_params);
            $confirmations_sent[] = $mailing_lists[$gid];
          }
          else {
            civicrm_api('group_contact', 'create', $group_params);
          }
        }
      }
      if (!empty($confirmations_sent)) {
        drupal_set_message(t('A confirmation has been sent to your email address, please follow the instructions in that message to finalize your subscription to @groups.', array('@groups' => implode(' ' . t('and') . ' ', $confirmations_sent))));
      }
    }
    // Remove contact from groups if they have unchecked them on the form
    if (!empty($remove_groups) && $op == 'update') {
      $groups = civicrm_api('group_contact', 'get', array('contact_id' => $cid, 'check_permissions' => FALSE, 'version' => 3));
      $in_groups = array();
      if (is_array($groups['values'])) {
        foreach ($groups['values'] as $group) {
          $in_groups[] = $group['group_id'];
        }
      }
      $group_params = array('contact_id' => $cid, 'check_permissions' => FALSE, 'version' => 3);
      foreach ($remove_groups as $group_params['group_id'] => $group) {
        if (in_array($group_params['group_id'], $in_groups)) {
          civicrm_api('group_contact', 'delete', $group_params);
          drupal_set_message(t('You have removed yourself from') . ' ' . check_plain($group) . '.');
        }
      }
    }
  }
  // Process tags
  if (array_key_exists('civicrm_tags', $enabled)) {
    $add_tags = drupal_explode_tags($submission->data[$enabled['civicrm_tags']]['value'][0]);
    foreach ($add_tags as $tag) {
      $result = civicrm_api('tag', 'get', array('name' => $tag, 'check_permissions' => FALSE, 'version' => 3));
      // Create tag if it doesn't exist
      if (!($id = $result['id'])) {
        $result = civicrm_api('tag', 'create', array('name' => $tag, 'check_permissions' => FALSE, 'version' => 3));
        $id = $result['id'];
      }
      civicrm_api('entity_tag', 'create', array('contact_id' => $cid, 'tag_id' => $id, 'check_permissions' => FALSE, 'version' => 3));
    }
  }
  return $cid;
}

/**
 * Get options from civicrm_option_value OR civicrm_group OR civicrm_country OR civicrm_state_province tables
 * @Param $option_group: option group name or id
 * @Param $var: Can return values as a Webform-style string, or a FAPI style array. Specify 'str' or 'arr', matey!
 * @Param $null_label: If returning FAPI array, provide label if first option should be "none"
 */
function webform_civicrm_get_options($option_group, $var = 'str', $null_label = '') {
  $params = array();
  if ($option_group == 'country') {
    $config = CRM_Core_Config::singleton();
    $sql = "SELECT name AS label, id AS value FROM civicrm_country";
    if (!empty($config->countryLimit)) {
      $sql .=  ' WHERE id IN (' . implode(',', $config->countryLimit) . ')';
    }
    $sql .= ' ORDER BY name';
  }
  elseif ($option_group == 'state') {
    $config = CRM_Core_Config::singleton();
    $sql = 'SELECT name AS label, id AS value FROM civicrm_state_province WHERE country_id = ' . $config->defaultContactCountry . ' ORDER BY name';
  }
  elseif ($option_group == 'groups' || $option_group == 'mailing_lists') {
    $sql = 'SELECT id AS value, title AS label FROM civicrm_group WHERE is_active = 1';
    if ($option_group == 'mailing_lists') {
      $sql .= " AND group_type LIKE '%2%' AND visibility = 'Public Pages'";
    }
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
    $sql .= ' ORDER BY weight, name';
  }
  $dao = &CRM_Core_DAO::executeQuery($sql, $params);
  $arr = array();
  if ($null_label) {
    $arr[] = $null_label;
  }
  $str = '';
  while ($dao->fetch()) {
    $arr[$dao->value] = $dao->label;
    $str .= ($str ? "\n" : '') . $dao->value . '|' . $dao->label;
  }
  return $$var;
}

/**
 * Return CiviCRM options via AJAX request
 */
function webform_civicrm_js_options($field_key, $str = '') {
  if (!user_access('access CiviCRM')) {
    return drupal_access_denied();
  }
  civicrm_initialize();
  // Autocomplete callback for tags
  if ($field_key == 'tags') {
    if (!$str) {
      exit();
    }
    // Handle a comma-separated list of tags.
    $existing = drupal_explode_tags($str);
    $last_str = trim(array_pop($existing));
    $matches = array();
    $prefix = count($existing) ? implode(', ', $existing) . ', ' : '';
    $params = array(1 => array('%' . $last_str . '%', 'String'));
    $sql = "SELECT name FROM civicrm_tag WHERE is_selectable = 1 AND used_for = 'civicrm_contact' AND name LIKE %1 LIMIT 0, 12";
    $dao = &CRM_Core_DAO::executeQuery($sql, $params);
    while ($dao->fetch()) {
      $matches[$prefix . $dao->name] = check_plain($dao->name);
    }
    exit(drupal_json_output($matches));
  }
  // Get option lists
  $lists = webform_civicrm_get_fields('lists');
  if (array_key_exists($field_key, $lists)) {
    print webform_civicrm_get_options($lists[$field_key]);
    exit();
  }
  return drupal_not_found();
}

/**
 * Get ids of enabled CiviCRM fields for a webform
 * @Param $node - node object
 */
function webform_civicrm_enabled_fields($node) {
  if (!is_array($node->webform['components'])) {
    return;
  }
  $fields = webform_civicrm_get_fields();
  $enabled = array();
  foreach ($node->webform['components'] as $c) {
    if (array_key_exists($c['form_key'], $fields)) {
      $enabled[$c['form_key']] = $c['cid'];
    }
  }
  return $enabled;
}

/**
 * Fetches CiviCRM field data
 * @Param $var: name of variable to return: fields, tokens or lists
 * @Return fields: The CiviCRM contact fields this module supports
 * @Return tokens: Available tokens keyed to field ids
 * @Return lists: Option lists keyed to option_group name
 */
function webform_civicrm_get_fields($var = 'fields') {
  static $fields = 0;
  static $tokens = 0;
  static $lists = 0;

  if (!$fields) {
    // The order of these fields is important: see function webform_civicrm_contact_match
    $fields = array();
    // Contact params
    $fields['civicrm_first_name'] = array(
      'name' => t('First Name'),
      'type' => 'textfield',
      'mandatory' => 1,
    );
    $fields['civicrm_nick_name'] = array(
      'name' => t('Nick Name'),
      'type' => 'textfield',
    );
    $fields['civicrm_middle_name'] = array(
      'name' => t('Middle Name'),
      'type' => 'textfield',
    );
    $fields['civicrm_last_name'] = array(
      'name' => t('Last Name'),
      'type' => 'textfield',
      'mandatory' => 1,
    );
    $fields['civicrm_prefix_id'] = array(
      'name' => t('Name Prefix'),
      'type' => 'select',
    );
    $fields['civicrm_suffix_id'] = array(
      'name' => t('Name Suffix'),
      'type' => 'select',
    );
    $fields['civicrm_gender_id'] = array(
      'name' => t('Gender'),
      'type' => 'select',
    );
    $fields['civicrm_birth_date'] = array(
      'name' => t('Birth Date'),
      'type' => 'date',
      'extra' => array(
        'year_start' => '-100',
        'year_end' => '-1',
        'timezone' => 'user',
      ),
    );
    $fields['civicrm_job_title'] = array(
      'name' => t('Job Title'),
      'type' => 'textfield',
    );
    $fields['civicrm_contact_id'] = array(
      'name' => t('Contact ID'),
      'type' => 'hidden',
      'extra' => array('description' => t('(hidden field, useful for post-processing)')),
    );
    $fields['civicrm_external_identifier'] = array(
      'name' => t('External ID'),
      'type' => 'hidden',
      'extra' => array('description' => t('(hidden field, useful for post-processing)')),
    );
    $fields['civicrm_preferred_communication_method'] = array(
      'name' => t('Preferred Communication Method(s)'),
      'type' => 'select',
      'extra' => array('multiple' => 1),
    );
    // Email params -- email must be first!
    $fields['civicrm_email'] = array(
      'name' => t('Email'),
      'type' => 'email',
      'mandatory' => 1,
    );
    // Address params -- street_address must be first!
    $fields['civicrm_street_address'] = array(
      'name' => t('Street Address'),
      'type' => 'textfield',
      'extra' => array('width' => 60),
    );
    $fields['civicrm_supplemental_address_1'] = array(
      'name' => t('Street Address Line 2'),
      'type' => 'textfield',
      'extra' => array('width' => 60),
    );
    $fields['civicrm_supplemental_address_2'] = array(
      'name' => t('Street Address Line 3'),
      'type' => 'textfield',
      'extra' => array('width' => 60),
    );
    $fields['civicrm_city'] = array(
      'name' => t('City'),
      'type' => 'textfield',
    );
    $fields['civicrm_state_province'] = array(
      'name' => t('State/Province Abbr.'),
      'type' => 'textfield',
      'extra' => array(
        'maxlength' => 4,
        'width' => 4,
        'description' => t('Enter state/province abbreviation'),
      ),
    );
    $fields['civicrm_state_province_id'] = array(
      'name' => t('State/Province Select'),
      'type' => 'select',
      'extra' => array('description' => t('Select state/province from list')),
    );
    $fields['civicrm_postal_code'] = array(
      'name' => t('Postal Code'),
      'type' => 'textfield',
      'extra' => array('width' => 10),
    );
    $fields['civicrm_country_id'] = array(
      'name' => t('Country'),
      'type' => 'select',
    );
    // Phone params -- phone must be first!
    $fields['civicrm_phone'] = array(
      'name' => t('Phone Number'),
      'type' => 'textfield',
    );
    $fields['civicrm_phone_type_id'] = array(
      'name' => t('Phone Type'),
      'type' => 'select',
    );
    // Other
    $fields['civicrm_groups'] = array(
      'name' => t('Select Group(s)'),
      'type' => 'select',
      'extra' => array(
        'multiple' => 1,
        'description' => t('Users can add/remove themselves from CiviCRM groups (mailing lists, etc)'),
      ),
    );
    $fields['civicrm_groups_hidden'] = array(
      'name' => t('Auto-Add Group(s)'),
      'type' => 'hidden',
      'extra' => array('description' => t('Hidden form element will add contacts to group(s) of your choosing')),
    );
    $fields['civicrm_tags'] = array(
      'name' => t('Tags'),
      'type' => 'hidden',
      'extra' => array('description' => t('Hidden form element to add tags to contacts')),
    );

    $tokens = array(
      'display_name' => t('display name'),
      'first_name' => t('first name'),
      'nick_name' => t('nick name'),
      'middle_name' => t('middle name'),
      'last_name' => t('last name'),
      'prefix' => t('name prefix'),
      'suffix' => t('name suffix'),
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

    $lists = array(
      'civicrm_phone_type_id' => 'phone_type',
      'civicrm_prefix_id' => 'individual_prefix',
      'civicrm_suffix_id' => 'individual_suffix',
      'civicrm_gender_id' => 'gender',
      'civicrm_country_id' => 'country',
      'civicrm_state_province_id' => 'state',
      'civicrm_preferred_communication_method' => 'preferred_communication_method',
    );

    // Pull custom fields and match to Webform element types
    $custom_types = array(
      'Select' => array('type' => 'select'),
      'Multi-Select' => array(
        'type' => 'select',
        'extra' => array('multiple' => 1),
      ),
      'AdvMulti-Select' => array(
        'type' => 'select',
        'extra' => array('multiple' => 1),
      ),
      'Radio' => array(
        'type' => 'select',
        'extra' => array('aslist' => 0),
      ),
      'CheckBox' => array(
        'type' => 'select',
        'extra' => array(
          'multiple' => 1,
          'aslist' => 0,
        ),
      ),
      'Text' => array('type' => 'textfield'),
      'TextArea' => array('type' => 'textarea'),
      'RichTextArea' => array('type' => 'textarea'),
      'Select Date' => array('type' => 'date'),
      'Link' => array('type' => 'textfield'),
      'Select Country' => array('type' => 'select'),
      'Multi-Select Country' => array(
        'type' => 'select',
        'extra' => array('multiple' => 1),
      ),
      'Select State/Province' => array('type' => 'select'),
      'Multi-Select State/Province' => array(
        'type' => 'select',
        'extra' => array('multiple' => 1),
      ),
    );
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    $sql = "
      SELECT cf.*, cg.title as custom_group_name FROM civicrm_custom_field cf
      INNER JOIN civicrm_custom_group cg ON cg.id = cf.custom_group_id
      WHERE cf.is_active <> 0 AND cg.extends IN ('Individual','Contact') AND cg.is_active <> 0
      ORDER BY cf.custom_group_id, cf.weight";
    $dao = &CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if (!$custom_types[$dao->html_type]) {
        continue;
      }
      $id = 'civicrm_custom_' . $dao->id;
      $label = drupal_strtolower($dao->label);
      // Prevent token namespace collisions
      if (in_array($label, $tokens)) {
        $label .= ' ' . $dao->id;
      }
      $tokens['custom_' . $dao->id] = $label;
      $fields[$id] = $custom_types[$dao->html_type];
      $fields[$id]['name'] = $dao->label;
      $fields[$id]['mandatory'] = $dao->is_required;
      $fields[$id]['extra']['description'] = $dao->help_pre;
      $fields[$id]['value'] = str_replace($sp, ',', trim($dao->default_value, $sp));
      $fields[$id]['custom_group'] = $dao->custom_group_name;
      if ($fields[$id]['type'] == 'date') {
        $fields[$id]['extra']['year_start'] = $dao->start_date_years ? '-' . $dao->start_date_years : '-50';
        $fields[$id]['extra']['year_end'] = $dao->end_date_years ? '+' . $dao->end_date_years : '+50';
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
        $fields[$id]['extra']['items'] = '1|' . t('Yes') . "\n0|" . t('No');
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
 * Token replacement for form messages
 */
function webform_civicrm_replace_tokens($str, $contact) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  $tokens = webform_civicrm_get_fields('tokens');
  $values = array();
  foreach ($tokens as $k => &$t) {
    if (isset($contact[$k])) {
      $value = $contact[$k];
      if (is_array($value)) {
        $value = implode(', ', $value);
      }
      $value = str_replace($sp, ' &amp; ', trim($value, $sp));
    }
    else {
      $value = '';
    }
    $values[] = $value;
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
  if (!empty($matches[0])) {
    foreach ($matches[0] as $pos => $match) {
      if (user_is_logged_in()) {
        $link = l($matches[1][$pos], 'user/logout', array('query' => array('destination' => $_GET['q'])));
      }
      else {
        $link = l($matches[1][$pos], $_GET['q']);
      }
      $message = str_replace($match, $link, $message);
    }
  }
  drupal_set_message($message, 'notice');
}
