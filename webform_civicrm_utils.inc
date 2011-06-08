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
    if ($val = $submission->data[$enabled[$fid]]['value']) {
      if (count($val) > 1 && $table == 'custom') {
        $params['civicrm_'. $table][$field] = drupal_map_assoc($val);
      }
      elseif ($val[0] !== '' && $val[0] !== NULL) {
        $params['civicrm_'. $table][$field] = $val[0];
      }
    }
  }
  $custom = $params['civicrm_custom'];
  unset($params['civicrm_custom']);

  require_once 'api/v2/Contact.php';

  // If this is an update op, just use CID from original submission to avoid confusion
  if ($submission->sid) {
    $db = db_query('SELECT contact_id FROM {webform_civicrm_submissions} WHERE sid = '. $submission->sid);
    if ($existing = db_fetch_array($db)) {
      $cid = $existing['contact_id'];
    }
  }
  if (!$cid) {
    // Try to match an existing contact to this submission. Logged-in user takes priority.
    global $user;
    if ($user->uid) {
      require_once 'CRM/Core/BAO/UFMatch.php';
      $cid = CRM_Core_BAO_UFMatch::getContactId($user->uid);
    }
    elseif ($_SESSION['webform_civicrm_cid'] && $_SESSION['webform_civicrm_cs']) {
      require_once 'CRM/Contact/BAO/Contact/Utils.php';
      if (CRM_Contact_BAO_Contact_Utils::validChecksum($_SESSION['webform_civicrm_cid'], $_SESSION['webform_civicrm_cs'])) {
        $cid = $_SESSION['webform_civicrm_cid'];
      }
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

  // Update or create new contact.
  // We call both add and update functions for new contacts, in order to avoid problems with API custom field handling.
  $op = 'update';
  if (!$cid) {
    $result = civicrm_contact_add($params['civicrm_contact']);
    $cid = $result['contact_id'];
    $op = 'create';
  }
  if ($cid) {
    if ($custom) {
      $params['civicrm_contact'] = array_merge($params['civicrm_contact'], $custom);
    }
    $params['civicrm_contact']['contact_id'] = $cid;
    $params['civicrm_contact']['check_permission'] = FALSE;
    civicrm_contact_update($params['civicrm_contact']);
  }
  else {
    // If an error occurred and we couldn't create contact, stop here.
    return;
  }
  // Fill values for hidden ID fields
  if (!empty($enabled['civicrm_contact_id'])) {
    $submission->data[$enabled['civicrm_contact_id']]['value'] = array($cid);
  }
  if (!empty($enabled['civicrm_external_identifier']) && $op=='update') {
    $get_params = array('contact_id' => $cid, 'return_external_identifier' => 1);
    $fetched = civicrm_contact_get($get_params);
    $submission->data[$enabled['civicrm_external_identifier']]['value'] = array($fetched[$cid]['external_identifier']);
  }

  // Add or update location
  $address_params = array();
  if ($params['civicrm_address']) {
    $address_params['address'][1] = $params['civicrm_address'];
    $address_params['address'][1]['is_primary'] = 1;
    $address_params['address'][1]['location_type_id'] = 1;
  }
  if ($params['civicrm_email']) {
    $address_params['email'][1] = $params['civicrm_email'];
    $address_params['email'][1]['is_primary'] = 1;
    $address_params['email'][1]['location_type_id'] = 1;
  }
  if ($params['civicrm_phone']) {
    $address_params['phone'][1] = $params['civicrm_phone'];
    if (!$params['civicrm_phone']['phone_type_id']) {
      $address_params['phone'][1]['phone_type_id'] = 1;
    }
    $address_params['phone'][1]['is_primary'] = 1;
    $address_params['phone'][1]['location_type_id'] = 1;
  }

  if ($address_params) {
    require_once 'api/v2/Location.php';
    $address_params['contact_id'] = $cid;
    $address_params['version'] = '3.0';

    // Update existing address
    $result = civicrm_location_update($address_params);
    // Or create a new one
    if ($result['is_error']) {
      $result = civicrm_location_add($address_params);
    }
  }
  // Process groups
  if (!empty($enabled['civicrm_groups']) || !empty($enabled['civicrm_groups_hidden'])) {
    require_once 'api/v2/GroupContact.php';
    $add_groups = $all_groups = $remove_groups = array();
    if (!empty($enabled['civicrm_groups'])) {
      $add_groups = $submission->data[$enabled['civicrm_groups']]['value'];
      $all_groups = explode("\n", $node->webform['components'][$enabled['civicrm_groups']]['extra']['items']);
      $remove_groups = array();
      foreach ($all_groups as $g) {
        list($gid, $group) = explode('|', $g);
        $remove_groups[$gid] = $group;
      }
    }
    if (!empty($enabled['civicrm_groups_hidden'])) {
      if (!empty($submission->data[$enabled['civicrm_groups_hidden']]['value'][0])) {
        $add_hidden = explode(',', $submission->data[$enabled['civicrm_groups_hidden']]['value'][0]);
        $add_groups = array_unique(array_merge($add_groups, $add_hidden));
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
  if (!empty($enabled['civicrm_tags'])) {
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
  return $cid;
}


/**
 * Get options from civicrm_option_value OR civicrm_group OR civicrm_country OR civicrm_state_province tables
 * @Param $option_group: option group name or id
 * @Param $var: Can return values as a Webform-style string, or a FAPI style array. Specify 'str' or 'arr'
 * @Param $null_label: If returning FAPI array, provide label if first option should be "none"
 */
function webform_civicrm_get_options($option_group, $var='str', $null_label='') {
  $params = array();
  if ($option_group == 'country') {
    $sql = "SELECT name AS label, id AS value FROM civicrm_country";
    $config = CRM_Core_Config::singleton();
    if (!empty($config->countryLimit)) {
      $sql .=  ' WHERE id IN ('. implode(',', $config->countryLimit) .')';
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
 * Return CiviCRM options via AJAX request
 */
function webform_civicrm_js_options($field_key, $str='') {
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
  if (array_key_exists($field_key, $lists)) {
    print webform_civicrm_get_options($lists[$field_key]);
    exit();
  }
  return drupal_not_found();
}


/**
 * Get ids of enabled CiviCRM fields for a webform
 * @Param $node - entire node object, or just the NID works too
 */
function webform_civicrm_enabled_fields($node) {
  if (!is_object($node)) {
    $node = node_load($node);
  }
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
    $fields = array(
      // Contact params
      'civicrm_first_name'     => array('name' => t('First Name'), 'type' => 'textfield', 'mandatory' => 1),
      'civicrm_nick_name'      => array('name' => t('Nick Name'), 'type' => 'textfield'),
      'civicrm_middle_name'    => array('name' => t('Middle Name'), 'type' => 'textfield'),
      'civicrm_last_name'      => array('name' => t('Last Name'), 'type' => 'textfield', 'mandatory' => 1),
      'civicrm_prefix_id'      => array('name' => t('Name Prefix'), 'type' => 'select'),
      'civicrm_suffix_id'      => array('name' => t('Name Suffix'), 'type' => 'select'),
      'civicrm_gender_id'      => array('name' => t('Gender'), 'type' => 'select'),
      'civicrm_birth_date'     => array('name' => t('Birth Date'), 'type' => 'date',
                                'extra' => array('year_start' => '-100', 'year_end' => '-1', 'timezone' => 'user')),
      'civicrm_job_title'      => array('name' => t('Job Title'), 'type' => 'textfield'),
      'civicrm_contact_id'     => array('name' => t('Contact ID'), 'type' => 'hidden', 'extra' => array('description' => t('(hidden field, useful for post-processing)'))),
      'civicrm_external_identifier' => array('name' => t('External ID'), 'type' => 'hidden', 'extra' => array('description' => t('(hidden field, useful for post-processing)'))),
      // Email params -- email must be first!
      'civicrm_email'          => array('name' => t('Email'), 'type' => 'email', 'mandatory' => 1),
      // Address params -- street_address must be first!
      'civicrm_street_address' => array('name' => t('Street Address'), 'type' => 'textfield',
                                 'extra' => array('width' => 60)),
      'civicrm_city'           => array('name' => t('City'), 'type' => 'textfield'),
      'civicrm_state_province' => array('name' => t('State/Province Abbr.'), 'type' => 'textfield',
                                 'extra' => array('maxlength' => 4, 'width' => 4, 'description' => t('Enter state/province abbreviation'))),
      'civicrm_state_province_id' => array('name' => t('State/Province Select'), 'type' => 'select', 'extra' => array('description' => t('Select state/province from list'))),
      'civicrm_postal_code'    => array('name' => t('Postal Code'), 'type' => 'textfield',
                                 'extra' => array('width' => 10)),
      'civicrm_country_id'     => array('name' => t('Country'), 'type' => 'select'),
      // Phone params -- phone must be first!
      'civicrm_phone'          => array('name' => t('Phone Number'), 'type' => 'textfield'),
      'civicrm_phone_type_id'  => array('name' => t('Phone Type'), 'type' => 'select'),
      // Other
      'civicrm_groups'         => array('name' => t('Select Group(s)'), 'type' => 'select',
                                 'extra' => array('multiple' => 1, 'description' => t('Users can add/remove themselves from CiviCRM groups (mailing lists, etc)'))),
      'civicrm_groups_hidden'  => array('name' => t('Auto-Add Group(s)'), 'type' => 'hidden',
                                 'extra' => array('description' => t('Hidden form element will add contacts to group(s) of your choosing'))),
      'civicrm_tags'  => array('name' => t('Tags'), 'type' => 'hidden',
                                 'extra' => array('description' => t('Hidden form element to add tags to contacts')))
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
      'civicrm_phone_type_id'     => 'phone_type',
      'civicrm_prefix_id'         => 'individual_prefix',
      'civicrm_suffix_id'         => 'individual_suffix',
      'civicrm_gender_id'         => 'gender',
      'civicrm_country_id'        => 'country',
      'civicrm_state_province_id' => 'state'
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
      SELECT cf.*, cg.title as custom_group_name FROM civicrm_custom_field cf
      INNER JOIN civicrm_custom_group cg ON cg.id = cf.custom_group_id
      WHERE cf.is_active <> 0 AND cg.extends IN ('Individual','Contact') AND cg.is_active <> 0
      ORDER BY cf.custom_group_id, cf.weight";
    $dao =& CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if (!$custom_types[$dao->html_type]) {
        continue;
      }
      $id = 'civicrm_custom_'. $dao->id;
      $label = drupal_strtolower($dao->label);
      // Prevent token namespace collisions
      if (in_array($label, $tokens)) {
        $label .= ' '. $dao->id;
      }
      $tokens['custom_'. $dao->id] = $label;
      $fields[$id] = $custom_types[$dao->html_type];
      $fields[$id]['name'] = $dao->label;
      $fields[$id]['mandatory'] = $dao->is_required;
      $fields[$id]['extra']['description'] = $dao->help_pre;
      $fields[$id]['value'] = str_replace($sp, ',', trim($dao->default_value, $sp));
      $fields[$id]['custom_group'] = $dao->custom_group_name;
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
 * CiviCRM group subscription API wrapper
 * Mimics the functionality of api V2 for CiviCRM versions prior to 3.3
 * TODO: This can be safely removed for the D7 version of this module
 * @param array $params
 * @return array
 */
function webform_civicrm_mailer_event_subscribe($params) {
  require_once('CRM/Utils/System.php');
  $civicrm_version = CRM_Utils_System::version();
  if($civicrm_version >= 3.3) {
    require_once('api/v2/Mailer.php');
    return civicrm_mailer_event_subscribe($params);
  }
  else {
    require_once('api/Mailer.php');
    return crm_mailer_event_subscribe($params['email'], $params['group_id']);
  }
}
