<?php

/**
 * @file
 * Webform CiviCRM module's utility functions.
 */


/**
 * Create/update CiviCRM data
 * Called by both presave and insert webform hooks
 */
function webform_civicrm_process_submission($node, &$submission) {
  static $data = array();
  static $id = array();
  $settings = $node->webform_civicrm;
  $op = 'insert';

  // The bulk of the work happens during presave
  if (empty($data)) {
    $op = 'presave';
    civicrm_initialize();
    require_once 'api/api.php';
    require_once 'CRM/Core/BAO/CustomValueTable.php';
    $enabled = webform_civicrm_enabled_fields($node);
    $fields = webform_civicrm_get_fields();
    $sets = webform_civicrm_get_fields('sets');
    $data = $settings['data'];
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    global $user;

    // Fill entity arrays with field values
    foreach ($enabled as $field => $fid) {
      if (!empty($submission->data[$fid]['value'])) {
        list($lobo, $c, $ent, $n, $table, $field) = explode('_', $field, 6);
        $val = $submission->data[$fid]['value'];
        if (!empty($fields[$table .'_'. $field]['extra']['multiple'])) {
           $val = $sp . implode($sp, $val) . $sp;
        }
        elseif ($fields[$table .'_'. $field]['type'] == 'date') {
          $val = str_replace('-', '', $val[0]);
        }
        else {
          $val = $val[0];
        }
        if ($val !== NULL) {
          $data[$ent][$c][$table][$n][$field] = $val;
        }
      }
    }
    if (!empty($submission->sid)) {
      $op = 'update';
    }

    // Retrieve stored ids
    if (isset($_SESSION['webform_civicrm_data'][$node->nid])) {
      $id = $_SESSION['webform_civicrm_data'][$node->nid];
      unset($_SESSION['webform_civicrm_data'][$node->nid]);
    }
    if (empty($id)) {
      $id = array('cid' => array());
    }
    foreach ($data['contact'] as $c => $contact) {
      // Search for an existing contact using default strict rule
      if (empty($id['cid'][$c])) {
        require_once 'CRM/Dedupe/Finder.php';
        $params = array('check_permission' => FALSE);
        foreach ($contact as $table => $field) {
          if (is_array($field)) {
            if (!empty($field[1])) {
              if (substr($table, 0, 2) == 'cg') {
                //TODO pass custom data to deduper
              }
              else {
                $params['civicrm_' . $table] = $field[1];
              }
            }
          }
        }
        if ($dupes = CRM_Dedupe_Finder::dupesByParams($params, ucfirst($contact['contact'][1]['contact_type']))) {
          $id['cid'][$c] = $dupes[0];
        }
      }
      $params = array_merge(array(), $contact['contact'][1]);
      $params['contact_type'] = ucfirst($params['contact_type']);

      if (empty($id['cid'][$c])) {
        $result = webform_civicrm_api('contact', 'create', $params);
        $id['cid'][$c] = $result['id'];
      }
      else {
        $params['contact_id'] = $id['cid'][$c];
        $result = webform_civicrm_api('contact', 'create', $params);
      }
      if(empty($id['cid'][$c]) || !empty($result['is_error'])) {
        watchdog('webform_civicrm', "An error occured when trying to create a new contact. Contact $c was not created.", NULL, WATCHDOG_ERROR, l($node->title . ': ' . t('Submission #') . $submission->sid, 'node/' . $node->nid . '/submission/' . $submission->sid));
        if (empty($id['cid'][$c])) {
          continue;
        }
      }
      $cid = $id['cid'][$c];
      webform_civicrm_save_custom($contact, $cid);

      // Fill values for hidden ID fields
      if (!empty($enabled['civicrm_'. $n .'_contact_1_contact_contact_id'])) {
        $submission->data[$enabled['civicrm_'. $n .'_contact_1_contact_contact_id']]['value'] = array($cid);
      }
      if (!empty($enabled['civicrm_'. $n .'_contact_1_contact_external_identifier']) && $op=='update') {
        $exid = webform_civicrm_api('contact', 'get', array( 'contact_id' => $cid, 'return.external_identifier' => 1));
        if (is_array($exid['values'])) {
          $exid = array_pop($exid['values']);
          $submission->data[$enabled['civicrm_'. $n .'_contact_1_contact_external_identifier']]['value'] = array($exid['external_identifier']);
        }
      }

      // Save location data
      foreach (array('address', 'email', 'phone', 'website') as $location) {
        if (!empty($contact[$location])) {
          $existing = array();
          $result = webform_civicrm_api($location, 'get', array( 'contact_id' => $cid));
          if (!empty($result['values'])) {
            $existing = array_values($result['values']);
            array_unshift($existing, 0);
          }
          foreach ($contact[$location] as $i => $params) {
            $params['contact_id'] = $cid;
            $params['is_primary'] = ($i == 1);
            if (!empty($existing[$i])) {
              $params['id'] = $existing[$i]['id'];
            }
            $result = webform_civicrm_api($location, 'create', $params);
            if ($location == 'address') {
              $custom = array();
              foreach ($params as $param => $val) {
                if (strpos($param, 'custom_') !== FALSE) {
                  $custom[$param] = $val;
                }
              }
              if ($custom) {
                $custom['entityID'] = $result['id'];
                CRM_Core_BAO_CustomValueTable::setValues($custom);
              }
            }
          }
        }
      }
      // Process groups
      if (isset($enabled['civicrm_'. $n .'_contact_1_other_groups']) || isset($enabled['civicrm_'. $n .'_contact_1_other_groups_hidden'])) {
        $add_groups = $remove_groups = array();
        if (isset($enabled['civicrm_'. $n .'_contact_1_other_groups'])) {
          $add_groups = $submission->data[$enabled['civicrm_'. $n .'_contact_1_other_groups']]['value'];
          $all_groups = explode("\n", $node->webform['components'][$enabled['civicrm_'. $n .'_contact_1_other_groups']]['extra']['items']);
          foreach ($all_groups as $g) {
            if (!empty($g)) {
              list($gid, $group) = explode('|', $g);
              $remove_groups[$gid] = $group;
            }
          }
        }
        if (isset($enabled['civicrm_'. $n .'_contact_1_other_groups_hidden'])) {
          $add_hidden = explode(',', $submission->data[$enabled['civicrm_'. $n .'_contact_1_other_groups_hidden']]['value'][0]);
          if (!empty($add_groups)) {
            $add_groups = array_unique(array_merge($add_groups, $add_hidden));
          }
          else {
            $add_groups = $add_hidden;
          }
        }

        if (!empty($add_groups)) {
          // Prepare for sending subscription confirmations
          $confirmations_sent = $mailing_lists = array();
          if (!empty($node->webform_civicrm['confirm_subscription'])) {
            $contact = webform_civicrm_api('contact', 'get', array( 'contact_id' => $cid, 'return_email' => 1));
            $contact = array_pop($contact['values']);
            $mailer_params = array(
              'contact_id' => $cid,
              'email' => $contact['email'],
            );
            if (!empty($contact['email'])) {
              $mailing_lists = webform_civicrm_get_options('mailing_lists', 'arr');
            }
          }
          $group_params = array('contact_id' => $cid);
          if (!empty($add_groups)) {
            foreach ($add_groups as $gid) {
              $group_params['group_id'] = $mailer_params['group_id'] = (int) $gid;
              unset($remove_groups[$gid]);
              if (array_key_exists($gid, $mailing_lists)) {
                webform_civicrm_api('mailing_group', 'event_subscribe', $mailer_params);
                $confirmations_sent[] = $mailing_lists[$gid];
              }
              else {
                webform_civicrm_api('group_contact', 'create', $group_params);
              }
            }
          }
          if (!empty($confirmations_sent) && $c == 1) {
            drupal_set_message(t('A confirmation has been sent to your email address, please follow the instructions in that message to finalize your subscription to @groups.', array('@groups' => implode(' ' . t('and') . ' ', $confirmations_sent))));
          }
        }
        // Remove contact from groups if they have unchecked them on the form
        if (!empty($remove_groups) && $op == 'update') {
          $groups = webform_civicrm_api('group_contact', 'get', array('contact_id' => $cid));
          $in_groups = array();
          if (is_array($groups['values'])) {
            foreach ($groups['values'] as $group) {
              $in_groups[] = $group['group_id'];
            }
          }
          $group_params = array('contact_id' => $cid);
          foreach ($remove_groups as $group_params['group_id'] => $group) {
            if (in_array($group_params['group_id'], $in_groups)) {
              webform_civicrm_api('group_contact', 'delete', $group_params);
              drupal_set_message(t('You have removed yourself from') . ' ' . check_plain($group) . '.');
            }
          }
        }
      }
      // Process tags
      if (array_key_exists('civicrm_'. $n .'_contact_1_other_tags', $enabled)) {
        $add_tags = drupal_explode_tags($submission->data[$enabled['civicrm_'. $n .'_contact_1_other_tags']]['value'][0]);
        foreach ($add_tags as $tag) {
          $result = webform_civicrm_api('tag', 'get', array('name' => $tag));
          // Create tag if it doesn't exist
          if (!($id = $result['id'])) {
            $result = webform_civicrm_api('tag', 'create', array('name' => $tag));
            $id = $result['id'];
          }
          webform_civicrm_api('entity_tag', 'create', array('contact_id' => $cid, 'tag_id' => $id));
        }
      }
    }
  }
  // Insert/update processing
  if ($op != 'presave') {
    $act = 0;
    if (!empty($data['activity'][1])) {
      $params = $data['activity'][1]['activity'][1];
      $params['source_contact_id'] = $id['cid'][1];
      $params['activity_date_time'] = date('Ymdhis');
      global $user;
      if (!empty($user->uid)) {
        $result = webform_civicrm_api('uf_match', 'get', array('uf_id' => $user->uid));
        if (!empty($result['values'])) {
          $result = array_pop($result['values']);
          $params['source_contact_id'] = $result['contact_id'];
        }
      }
      $params['target_contact_id'] = array();
      foreach ($data['contact'] as $c => $contact) {
        if (!empty($contact['activity_target'])) {
          $params['target_contact_id'][$id['cid'][$c]] = $id['cid'][$c];
        }
      }
      if (empty($params['details'])) {
        $params['details'] = '';
      }
      else {
        $params['details'] = nl2br($params['details']);
      }
      if (empty($params['subject'])) {
        $params['subject'] = $settings['data']['activity'][1]['activity'][1]['subject'];
      }
      if (!empty($data['activity'][1]['add_link'])) {
        $params['details'] .= '<p>'. l(t('View Webform Submission'), 'node/' . $node->nid . '/submission/' . $submission->sid) .'</p>';
      }
      unset($params['add_link']);
      if (!empty($id['act'][1])) {
        $params['id'] = $act = $id['act'][1];
        $result = webform_civicrm_api('activity', 'update', $params);
      }
      else {
        $result = webform_civicrm_api('activity', 'create', $params);
      }
      if (!empty($result['id']) || $act) {
        if (!empty($result['id'])) {
          $act = $result['id'];
        }
        webform_civicrm_save_custom($data['activity'][1], $act, 'Activity');
      }
    }
    if ($op == 'insert') {
      $record = array('sid' => $submission->sid, 'contact_id' => '-'. implode('-', $id['cid']) .'-', 'activity_id' => $act);
      drupal_write_record('webform_civicrm_submissions', $record);
    }
  }
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
    SELECT c1.name, c1.label, LOWER(c2.name) AS parent_type
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
      $contact_types[strtolower($dao->name)] = $dao->label;
      $sub_types[strtolower($dao->name)] = array();
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
    $sql = "
      SELECT id, name_a_b, name_b_a, label_a_b, label_b_a, LOWER(contact_type_a) AS type_a, LOWER(contact_type_b) AS type_b, contact_sub_type_a AS sub_type_a, contact_sub_type_b AS sub_type_b
      FROM civicrm_relationship
      WHERE is_active <> 0";
    $dao = &CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $types[$dao->id] = (array) $dao;
    }
  }
  return $types;
}


/**
 * Get valid relationship types for given contact types and sub_types
 */
function webform_civicrm_get_contact_relationship_types($type_a, $type_b, $sub_type_a = NULL, $sub_type_b = NULL) {
  $types = webform_civicrm_get_relationship_types();
  $ret = array();
  foreach ($types as $t) {
    if (($t['type_a'] == $type_a || !$t['type_a'])
      && ($t['type_b'] == $type_b || !$t['type_b'])
      && ($t['sub_type_a'] == $sub_type_a || !$t['sub_type_a'])
      && ($t['sub_type_b'] == $sub_type_b || !$t['sub_type_b'])
    ) {
      $ret[$t['id'] .'_a'] = $t['label_a_b'];
    }
    if (($t['type_a'] == $type_a || !$t['type_a'])
      && ($t['type_b'] == $type_b || !$t['type_b'])
      && ($t['sub_type_a'] == $sub_type_a || !$t['sub_type_a'])
      && ($t['sub_type_b'] == $sub_type_b || !$t['sub_type_b'])
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

    // Retrieve the portion of form to fetch (array keys are encoded as a . separated string)
    $keys = explode('.', substr($key, 13));
    foreach($keys as $key) {
      $snippet = $snippet[$key];
    }
    unset($snippet['#prefix'], $snippet['#suffix']);
    $output = theme('status_messages') . drupal_render($snippet);
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
 * Get custom data for an entity
 */
function webform_civicrm_get_custom($entity_id, $entity_type = NULL, $normalize = TRUE) {
  static $parents = array();
  if (empty($parents)) {
    require_once 'CRM/Core/BAO/CustomValueTable.php';
    // Create matching table to sort fields by group
    $fields = array_keys(webform_civicrm_get_fields());
    foreach ($fields as $key) {
      list($group, $field) = explode('_', $key, 2);
      if (substr($field, 0, 7) == 'custom_') {
        $parents[$field] = $group;
      }
    }
  }
  $params = array(
    'entityID' => $entity_id,
  );
  if ($entity_type) {
    $params['entityType'] = ucfirst($entity_type);
  }
  $result = CRM_Core_BAO_CustomValueTable::getValues($params);
  if ($result['is_error']) {
    return array();
  } 
  unset($result['is_error'], $result['entityID']);
  $values = array();
  // Convert multi-value strings to arrays and sort by group
  foreach ($result as $key => $value) {
    $pieces = explode('_', $key);
    if($pieces[0] == 'custom') {
      $name = 'custom_'. $pieces[1];
      if (empty($pieces[2])) {
        $pieces[2] = ($normalize ? 1 : 0);
      }
      $values[$parents[$name]][$pieces[2]][$name] = $value;
    }
  }
  if ($normalize) {
    // Normalize array keys
    foreach($values as $key => &$value) {
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
      foreach ($values as $offset => $custom) {
        $suf = '';
        if ($id = each($existing[$table])) {
          if (!empty($id[0])) {
            $suf = '_' . $id[0];
          }
        }
        foreach ($custom as $k => $v) {
          $params[$k.$suf] = $v;
        }
      }
    }
  }
  CRM_Core_BAO_CustomValueTable::setValues($params);
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
 * Recursively walk through form array and set default values for fields based on CiviCRM entity data
 * Called by _webform_civicrm_webform_frontend_form_alter() when webform is being viewed by a known contact
 * @Param $elements: FAPI array
 * @Param $data: Array of CiviCRM data
 */
function webform_civicrm_fill_values(&$elements, $data) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  foreach ($elements as $eid => &$element) {
    if (substr($eid, 0, 1) == '#' || !is_array($element)) {
      continue;
    }
    if ($element['#type'] == 'fieldset') {
      // Recurse through fieldsets
      webform_civicrm_fill_values($element, $data);
      continue;
    }
    if ($pieces = webform_civicrm_explode_key($eid)) {
      list($lobo, $c, $ent, $n, $table, $name) = $pieces;
      if (isset($data[$ent][$c][$table][$n][$name])) {
        $val = $data[$ent][$c][$table][$n][$name];
        if ($element['#type'] == 'date') {
          $dates = explode('-', $val);
          $val = array('year' => $dates[0], 'month' => intval($dates[1]), 'day' => intval($dates[2]));
        }
        elseif (($element['#type'] == 'checkboxes' || !empty($element['#multiple'])) && !is_array($val)) {
          $val = explode($sp, trim($val, $sp));
        }
        if ($element['#type'] == 'hidden') {
          $element['#value'] = $val;
        }
        $element['#default_value'] = $val;
      }
    }
  }
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
      'value' => '0',
      'extra' => array('aslist' => 1),
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
    $fields['website_url'] = array(
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
    $fields['relationship_relationship_type_id'] = array(
      'name' => t('Relationship to Contact'),
      'type' => 'select',
//       'expose_list' => TRUE,
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
      'relationship_type_id'           => 'relationship_type',
    );

    $sets = array(
      'contact' => array('entity_type' => 'contact', 'label' => t('Contact Fields')),
      'other' => array('entity_type' => 'contact', 'label' => t('Tags and Groups')),
      'address' => array('entity_type' => 'contact', 'label' => t('Address'), 'max_instances' => 9),
      'phone' => array('entity_type' => 'contact', 'label' => t('Phone'), 'max_instances' => 9),
      'email' => array('entity_type' => 'contact', 'label' => t('Email'), 'max_instances' => 9),
      'website' => array('entity_type' => 'contact', 'label' => t('Website'), 'max_instances' => 9),
      'activity' => array('entity_type' => 'activity', 'label' => t('Expose Activity Fields')),
      'relationship' => array('entity_type' => 'contact', 'label' => t('Relationship to Contact')),
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
      SELECT cf.*, cg.title AS custom_group_name, LOWER(cg.extends) AS entity_type, cg.extends_entity_column_value AS sub_types, cg.is_multiple, cg.max_multiple, cg.id AS custom_group_id
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
        $lists['custom_'. $dao->id] = $og;
      }
      elseif ($dao->html_type == 'Select Country' || $dao->html_type == 'Multi-Select Country') {
        $lists['custom_'. $dao->id] = 'country';
      }
      elseif ($dao->html_type == 'Select State/Province' || $dao->html_type == 'Multi-Select State/Province') {
        $lists['custom_'. $dao->id] = 'state';
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
  if (count($pieces) != 6 || $pieces[0] != 'civicrm') {
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
 * Wrapper for all CiviCRM API calls
 * For consistency, future-proofing, and error handling
 */
function webform_civicrm_api($entity, $operation, $params) {
  $params['check_permissions'] = FALSE;
  $params['version'] = 3;
  $result = civicrm_api($entity, $operation, $params);
  if (!empty($result['is_error'])) {
    dsm($params);
    dsm($result);
    dsm(debug_backtrace());
  }
  return $result;
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