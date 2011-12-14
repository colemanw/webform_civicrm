<?php

/**
 * @file
 * Webform CiviCRM module's utility functions.
 * The code in this file is cross-compatible with D6/Civi3 and D7/Civi4
 * Drupal-version-specific functions belong in webform_civicrm_dx_functions.inc
 */

// Load drupal-version-specific helper functions
module_load_include('inc', 'webform_civicrm', 'webform_civicrm_d' . substr(VERSION, 0, 1) . '_functions');

/**
 * Create/update CiviCRM data
 * Called by presave, insert and update webform hooks
 * @param $op: Which hook is being called
 */
function webform_civicrm_process_submission($node, &$submission, $op) {
  static $data = array();
  static $id = array('cid' => array());
  static $update = array();
  $settings = $node->webform_civicrm;

  // Presave processing - save contact data
  if ($op == 'presave') {
    civicrm_initialize();
    $config = CRM_Core_Config::singleton();
    require_once 'CRM/Core/BAO/CustomValueTable.php';
    $enabled = webform_civicrm_enabled_fields($node);
    $fields = webform_civicrm_get_fields();
    $data = $settings['data'];
    $sets = webform_civicrm_get_fields('sets');
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;

    // Retrieve stored ids
    if (!empty($_SESSION['webform_civicrm_data'][$node->nid])) {
      $id = $updating = $_SESSION['webform_civicrm_data'][$node->nid];
      unset($_SESSION['webform_civicrm_data'][$node->nid]);
      if (empty($_SESSION['webform_civicrm_data'])) {
        unset($_SESSION['webform_civicrm_data']);
      }
    }

    // If this is an update op, set param for drupal_write_record()
    if (!empty($submission->sid)) {
      $submitted = array($submission->sid => new stdClass());
      webform_civicrm_webform_submission_load($submitted);
      if (isset($submitted[$submission->sid]->civicrm)) {
        $update = 'sid';
      }
    }

    // While saving a draft, just skip to the end and write the record
    if (!empty($submission->is_draft)) {
      return;
    }

    // Fill entity arrays with field values
    foreach ($enabled as $field => $fid) {
      if (isset($submission->data[$fid]['value'])) {
        list($lobo, $c, $ent, $n, $table, $field) = explode('_', $field, 6);
        $val = $submission->data[$fid]['value'];
        if ($field == 'privacy') {
          foreach (array_keys(webform_civicrm_str2array($node->webform['components'][$fid]['extra']['items'])) as $key) {
            $data[$ent][$c][$table][$n][$key] = in_array($key, $val);
          }
          continue;
        }
        if (!empty($fields[$table . '_' . $field]['extra']['multiple'])) {
          $val = $sp . implode($sp, $val) . $sp;
        }
        elseif ($fields[$table . '_' . $field]['type'] == 'date') {
          $val = empty($val[0]) ? '' : str_replace('-', '', $val[0]);
        }
        elseif ($field != 'event_id') {
          $val = isset($val[0]) ? $val[0] : '';
        }
        // Only known contacts are allowed to empty a field
        if (($val !== '' && $val !== NULL) || !empty($updating['cid'][$c])) {
          $data[$ent][$c][$table][$n][$field] = $val;
        }
      }
    }

    $shared_address = array();
    foreach ($data['contact'] as $c => $contact) {
      if (empty($id['cid'][$c])) {
        // Don't create contact if we don't have a name or email
        if (empty($contact['contact'][1]['first_name'])
        && empty($contact['contact'][1]['last_name'])
        && empty($contact['contact'][1]['organization_name'])
        && empty($contact['contact'][1]['household_name'])
        && empty($contact['email'][1]['email'])) {
          $id['cid'][$c] = 0;
          continue;
        }
        // Search for an existing contact using default strict rule
        require_once 'CRM/Dedupe/Finder.php';
        $params = array('check_permission' => FALSE);
        foreach ($contact as $table => $field) {
          if (is_array($field) && !empty($field[1])) {
            if (substr($table, 0, 2) == 'cg') {
              //TODO pass custom data to deduper
            }
            // If sharing an address, use the master
            elseif ($table == 'address' && !empty($field[1]['master_id'])) {
              $m = $field[1]['master_id'];
              // If master address is exposed to the form, use it
              if (!empty($contact[$m]['address'][1])) {
                $params['civicrm_address'] = $contact[$m]['address'][1];
              }
              // Else look up the master contact's address
              elseif (!empty($updating['cid'][$m])) {
                $masters = webform_civicrm_api('address', 'get',
                  array('contact_id' => $id['cid'][$m], 'options' => array('sort' => 'is_primary DESC')));
                if (!empty($masters['values'])) {
                  $params['civicrm_address'] = array_shift($masters['values']);
                }
              }
            }
            elseif (in_array($table, array('contact', 'address', 'email', 'phone', 'website'))) {
              $params['civicrm_' . $table] = $field[1];
            }
          }
        }
        if ($dupes = CRM_Dedupe_Finder::dupesByParams($params, ucfirst($contact['contact'][1]['contact_type']))) {
          $id['cid'][$c] = $dupes[0];
        }
      }
      $params = $contact['contact'][1];
      $params['contact_type'] = ucfirst($params['contact_type']);
      if (empty($params['contact_sub_type'])) {
        unset($params['contact_sub_type']);
      }
      // Create new contact
      if (empty($id['cid'][$c])) {
        unset($params['contact_id']);
        $params['source'] = $settings['new_contact_source'];
        $result = webform_civicrm_api('contact', 'create', $params);
        if (!empty($result['id'])) {
          $cid = $id['cid'][$c] = $result['id'];
        }
        else {
          $id['cid'][$c] = 0;
          continue;
        }
      }
      // Update existing contact
      else {
        $params['contact_id'] = $cid = $id['cid'][$c];
        $result = webform_civicrm_api('contact', 'create', $params);
      }

      webform_civicrm_save_custom($contact, $cid);

      // Fill values for hidden ID fields
      $fid = 'civicrm_' . $c . '_contact_1_contact_';
      if (!empty($enabled[$fid . 'contact_id'])) {
        $submission->data[$enabled[$fid . 'contact_id']]['value'] = array($cid);
      }
      if (!empty($enabled[$fid . 'external_identifier']) && !empty($updating['cid'][$c])) {
        $exid = webform_civicrm_api('contact', 'get', array('contact_id' => $cid, 'return.external_identifier' => 1));
        if (!empty($exid['values'])) {
          $exid = array_pop($exid['values']);
          $submission->data[$enabled[$fid . 'external_identifier']]['value'] = array($exid['external_identifier']);
        }
      }

      // Save location data
      foreach (array('address', 'email', 'phone', 'website') as $location) {
        if (!empty($contact[$location])) {
          $existing = array();
          $result = webform_civicrm_api($location, 'get', array('contact_id' => $cid, 'options' => array('sort' => 'is_primary DESC')));
          if (!empty($result['values'])) {
            $existing = array_merge(array(array()), $result['values']);
          }
          foreach ($contact[$location] as $i => $params) {
            $empty = FALSE;
            if ($location == 'address') {
              // Check if nothing was entered
              $empty = (empty($params['street_address'])
              && empty($params['city'])
              && empty($params['state_province_id'])
              && empty($params['country_id'])
              && empty($params['postal_code'])
              && empty($params['master_id']));

              // Store shared addresses for later since we haven't necessarily processed
              // the contact this address is shared with yet.
              if (!empty($params['master_id'])) {
                $shared_address[$cid][$i] = array(
                  'id' => !empty($existing[$i]) ? $existing[$i]['id'] : NULL,
                  'mc' => $params['master_id'],
                  'loc' => $params['location_type_id'],
                );
                continue;
              }
              unset($params['master_id']);
              // Translate state/prov abbr to id
              if (!empty($params['state_province_id'])) {
                if (!($params['state_province_id'] = webform_civicrm_state_abbr($params['state_province_id'], 'id', webform_civicrm_aval($params, 'country_id', $config->defaultContactCountry)))) {
                  $params['state_province_id'] = '';
                }
              }
            }
            // Check if nothing was entered
            elseif ($location == 'website') {
              $empty = empty($params['url']);
            }
            elseif (empty($params[$location])) {
              $empty = TRUE;
            }
            elseif ($location == 'email' && $i == 1 && $uid = webform_civicrm_user_cid($cid, 'contact')) {
              // Update drupal email address
              $user = user_load($uid);
              if ($params['email'] != $user->mail) {
                user_save($user, array('mail' => $params['email']));
              }
            }
            $params['contact_id'] = $cid;
            if (!empty($existing[$i])) {
              $params['id'] = $existing[$i]['id'];
            }
            if ($empty) {
              // Delete this location if nothing was entered and this is a known contact
              if (!empty($updating['cid'][$c]) && !empty($params['id'])) {
                webform_civicrm_api($location, 'delete', $params);
              }
              continue;
            }
            if ($location != 'website' && empty($params['location_type_id'])) {
              $params['location_type_id'] = webform_civicrm_aval($existing, $i . ':location_type_id', 1);
            }
            $params['is_primary'] = $i == 1 ? 1 : 0;
            $result = webform_civicrm_api($location, 'create', $params);
            if ($location == 'address' && empty($result['is_error'])) {
              // Process custom data for address
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
      // Process Relationships
      if (!empty($contact['relationship'])) {
        foreach ($contact['relationship'] as $i => $params) {
          if (!empty($params['relationship_type_id']) && !empty($id['cid'][$i])) {
            list($type, $a_b) = explode('_', $params['relationship_type_id']);
            $b_a = $a_b == 'a' ? 'b' : 'a';
            $params['contact_id_' . $a_b] = $cid;
            $params['contact_id_' . $b_a] = $id['cid'][$i];
            if ($type == 'ce') {
              foreach (webform_civicrm_get_relationship_types() as $t) {
                if ($t['name_a_b'] == 'Employee of' && $t['name_b_a'] == 'Employer of' && $t['id'] != 'ce') {
                  $type = $t['id'];
                  break;
                }
              }
              webform_civicrm_api('contact', 'create', array('contact_id' => $params['contact_id_a'], 'employer_id' => $params['contact_id_b'], 'contact_type' => 'Individual'));
            }
            $params['relationship_type_id'] = $type;
            if (isset($params['relationship_permission'])) {
              $perm = $params['relationship_permission'];
              $params['is_permission_a_b'] = $params['is_permission_b_a'] = $perm == 3 ? 1 : 0;
              if ($perm == 1 || $perm == 2) {
                $params['is_permission_' . ($perm == 1 ? $a_b . '_' . $b_a : $b_a . '_' . $a_b)] = 1;
              }
              unset($params['relationship_permission']);
            }
            webform_civicrm_api('relationship', 'create', $params);
          }
        }
      }
      // Process groups
      $fid = 'civicrm_' . $c . '_contact_1_other_groups';
      if (isset($enabled[$fid]) || !empty($contact['other'][1]['groups_hidden'])) {
        $add_groups = $remove_groups = array();
        if (isset($enabled[$fid])) {
          $add_groups = $submission->data[$enabled[$fid]]['value'];
          $remove_groups = webform_civicrm_str2array($node->webform['components'][$enabled[$fid]]['extra']['items']);
        }
        if ($add_hidden = webform_civicrm_aval($contact, 'other:1:groups_hidden')) {
          $add_groups = array_unique(array_merge($add_groups, explode(',', $add_hidden)));
        }
        foreach ($add_groups as $gid) {
          unset($remove_groups[$gid]);
        }
        if (!empty($add_groups) || (!empty($remove_groups) && !empty($updating['cid'][$c]))) {
          // Retrieve current group memberships for this contact
          $groups = webform_civicrm_api('group_contact', 'get', array('contact_id' => $cid));
          $in_groups = array();
          if (is_array($groups['values'])) {
            foreach ($groups['values'] as $group) {
              $in_groups[] = $group['group_id'];
            }
          }
        }
        if (!empty($add_groups)) {
          // Prepare for sending subscription confirmations
          $confirmations_sent = array();
          $email = '';
          if (!empty($node->webform_civicrm['confirm_subscription'])) {
            // Retrieve this contact's primary email address and perform error-checking
            $result = webform_civicrm_api('email', 'get', array('contact_id' => $cid, 'options' => array('sort' => 'is_primary DESC')));
            if (!empty($result['values'])) {
              foreach ($result['values'] as $value) {
                if (($value['is_primary'] || empty($email)) && strpos($value['email'], '@')) {
                  $email = $value['email'];
                }
              }
              $mailer_params = array(
                'contact_id' => $cid,
                'email' => $email,
              );
              if (!isset($mailing_lists)) {
                $mailing_lists = webform_civicrm_get_options('mailing_lists');
              }
            }
          }
          $group_params = array('contact_id' => $cid);
          foreach ($add_groups as $gid) {
            if (!in_array($gid, $in_groups)) {
              $group_params['group_id'] = $mailer_params['group_id'] = (int) $gid;
              if (isset($mailing_lists[$gid]) && !empty($email)) {
                $result = webform_civicrm_api('mailing_group', 'event_subscribe', $mailer_params);
                if (empty($result['is_error'])) {
                  $confirmations_sent[] = check_plain($mailing_lists[$gid]);
                }
                else {
                  webform_civicrm_api('group_contact', 'create', $group_params);
                }
              }
              else {
                webform_civicrm_api('group_contact', 'create', $group_params);
              }
            }
          }
          if ($confirmations_sent) {
            drupal_set_message(t('A message has been sent to %email to confirm subscription to !groups.', array('%email' => $email, '!groups' => '<em>' . implode('</em> ' . t('and') . ' <em>', $confirmations_sent) . '</em>')));
          }
        }
        // Remove contact from groups if they have unchecked them on the form
        if (!empty($remove_groups) && !empty($updating['cid'][$c])) {
          $group_params = array('contact_id' => $cid);
          $display_name = webform_civicrm_api('contact', 'get', array('contact_id' => $cid, 'return.display_name' => 1));
          $display_name = $display_name['values'][$cid]['display_name'];
          foreach ($remove_groups as $group_params['group_id'] => $group) {
            if (in_array($group_params['group_id'], $in_groups)) {
              webform_civicrm_api('group_contact', 'delete', $group_params);
              drupal_set_message(t('%contact has been removed from %group.', array('%contact' => $display_name, '%group' => check_plain($group))));
            }
          }
        }
      }
      // Process tags
      if (!empty($contact['other'][1]['tags'])) {
        $add_tags = drupal_explode_tags($contact['other'][1]['tags']);
        foreach ($add_tags as $tag) {
          $result = webform_civicrm_api('tag', 'get', array('name' => $tag));
          // Create tag if it doesn't exist
          if (!($tid = webform_civicrm_aval($result, 'id'))) {
            $result = webform_civicrm_api('tag', 'create', array('name' => $tag));
            $tid = $result['id'];
          }
          webform_civicrm_api('entity_tag', 'create', array('contact_id' => $cid, 'tag_id' => $tid));
        }
      }
      // Process event participation
      if (in_array('CiviEvent', $config->enableComponents) && !empty($data['participant_reg_type'])) {
        $n = $data['participant_reg_type'] == 'separate' ? $c : 1;
        if ($p = webform_civicrm_aval($data, "participant:$n:participant")) {
          foreach ($p as $e => $params) {
            if (empty($params['event_id'])) {
              continue;
            }
            // Loop through event ids to support multi-valued form elements
            $events = is_array($params['event_id']) ? $params['event_id'] : array($params['event_id']);
            foreach ($events as $i => $params['event_id']) {
              if (!empty($params['event_id'])) {
                $params['contact_id'] = $cid;
                $params['event_id'] = (int) $params['event_id'];
                $params['source'] = check_plain($node->title);
                if ($c > 1 && !empty($registered_by_id[$e][$i])) {
                  $params['registered_by_id'] = $registered_by_id[$e][$i];
                }
                if (empty($params['campaign_id']) || !in_array('CiviCampaign', $config->enableComponents)) {
                  unset($params['campaign_id']);
                }
                // Fetch existing participant - can't be done with the API currently
                if ($existing = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_participant WHERE event_id = {$params['event_id']} AND contact_id = $cid AND is_test = 0 LIMIT 0, 1")) {
                  $params['id'] = $existing;
                }
                $result = webform_civicrm_api('participant', 'create', $params);
                if ($c == 1 && $data['participant_reg_type'] == 'all') {
                  $registered_by_id[$e][$i] = $result['id'];
                }
                // Reformat custom data from nested arrays
                $custom = array();
                foreach ($data['participant'][$n] as $key => $vals) {
                  if (substr($key, 0, 2) == 'cg' && isset($vals[$e])) {
                    $custom[$key][1] = $vals[$e];
                  }
                }
                if ($custom) {
                  webform_civicrm_save_custom($custom, $result['id'], 'Participant');
                }
              }
            }
          }
        }
      }
    }
    // Process shared addresses. We do this last after all contacts and addresses exist.
    foreach ($shared_address as $cid => $shared) {
      foreach ($shared as $i => $addr) {
        if (!empty($id['cid'][$addr['mc']])) {
          $masters = webform_civicrm_api('address', 'get', array('contact_id' => $id['cid'][$addr['mc']], 'options' => array('sort' => 'is_primary DESC')));
          if (!empty($masters['values'])) {
            $masters = array_values($masters['values']);
            // Pick the address with the same location type; default to primary.
            $params = $masters[0];
            foreach ($masters as $m) {
              if ($m['location_type_id'] == $addr['loc']) {
                $params = $m;
                break;
              }
            }
            $params['master_id'] = $params['id'];
            $params['id'] = $addr['id'];
            $params['contact_id'] = $cid;
            $params['is_primary'] = $i == 1;
            webform_civicrm_api('address', 'create', $params);
          }
        }
      }
    }
  }
  // Insert/update processing - save case & activity data
  elseif (empty($submission->is_draft)) {
    $config = CRM_Core_Config::singleton();

    // Open new case if needed.
    if (empty($id['case'][1]) && empty($id['act'][1]) && !empty($data['case'][1]) && !empty($id['cid'][1])) {
      $data['case'][1]['case'][1]['contact_id'] = $id['cid'][1];
      $result = webform_civicrm_api('case', 'create', $data['case'][1]['case'][1]);
      $id['case'][1] = $result['id'];
      $id['act'][1] = webform_civicrm_activity_find(array(
        'contact_id' => $id['cid'][1],
        'activity_type_id' => $data['activity'][1]['activity'][1]['activity_type_id'],
        'status_id' => $data['activity'][1]['existing_activity_status'],
        'case_id' => $id['case'][1],
      ));
    }
    if (!empty($data['activity'][1])) {
      $config = CRM_Core_Config::singleton();
      $params = $data['activity'][1]['activity'][1];
      $params['source_contact_id'] = $id['cid'][1];
      $params['activity_date_time'] = date('YmdHis');
      if ($cid = webform_civicrm_user_cid()) {
        $params['source_contact_id'] = $cid;
      }
      $params['target_contact_id'] = array();
      foreach ($data['contact'] as $c => $contact) {
        if (!empty($contact['activity_target']) && !empty($id['cid'][$c])) {
          $params['target_contact_id'][$id['cid'][$c]] = $id['cid'][$c];
        }
      }
      // Choose assignee contact ID from webform contacts if a CID was not specified
      if (!empty($params['assignee_contact_id']) && $params['assignee_contact_id'][0] == '#') {
        $acid = substr($params['assignee_contact_id'], 1);
        $params['assignee_contact_id'] = empty($id['cid'][$acid]) ? 0 : $id['cid'][$acid];
      }
      if (empty($params['assignee_contact_id'])) {
        unset($params['assignee_contact_id']);
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
        $params['details'] .= '<p>' . l(t('View Webform Submission'), 'node/' . $node->nid . '/submission/' . $submission->sid, array('absolute' => TRUE)) . '</p>';
      }
      unset($params['add_link']);
      if (!empty($id['act'][1])) {
        $params['id'] = $id['act'][1];
      }
      elseif (!empty($id['case'][1])) {
        $params['case_id'] = $id['case'][1];
        $params['medium_id'] = $data['case'][1]['case'][1]['medium_id'];
      }
      if (empty($params['campaign_id']) || !in_array('CiviCampaign', $config->enableComponents)) {
        unset($params['campaign_id']);
      }
      $result = webform_civicrm_api('activity', 'create', $params);

      if (!empty($result['id'])) {
        $id['act'][1] = $result['id'];
        webform_civicrm_save_custom($data['activity'][1], $id['act'][1], 'Activity');
      }
      if (!empty($config->activityAssigneeNotification)
      && !empty($id['act'][1])
      && !empty($params['assignee_contact_id'])) {
        // Send email to assignees. TODO: Move to CiviCRM API?
        $assignee = webform_civicrm_api('contact', 'get', array('id' => $params['assignee_contact_id']));
        $assignee = array_pop($assignee['values']);
        if (!empty($assignee['email'])) {
          $mail = array($assignee['email'] => $assignee);
          //include attachments while sendig a copy of activity.
          require_once 'CRM/Core/BAO/File.php';
          $attachments = &CRM_Core_BAO_File::getEntityFile('civicrm_activity', $id['act'][1]);
          require_once 'CRM/Case/BAO/Case.php';
          $result = CRM_Case_BAO_Case::sendActivityCopy(NULL, $id['act'][1], $mail, $attachments, NULL);
        }
      }
    }
  }
  // Write record; we do this when creating, updating, or saving a draft of a webform submission.
  if ($op != 'presave') {
    $cid = '-';
    foreach (array_keys($data['contact']) as $c) {
      $cid .= (empty($id['cid'][$c]) ? 0 : $id['cid'][$c]) . '-';
    }
    $record = array(
      'sid' => $submission->sid,
      'contact_id' => $cid,
      'activity_id' => empty($id['act'][1]) ? 0 : $id['act'][1],
    );
    drupal_write_record('webform_civicrm_submissions', $record, $update);
  }
}


/**
 * Get option values from various civicrm tables
 * @Param $option_group: option group name or id
 * @Param $param: some extra info for the query
 * @Return array
 */
function webform_civicrm_get_options($option_group, $param = NULL) {
  $vars = $ret = array();
  if ($option_group == 'privacy') {
    // Privacy options aren't in the database as options; they are all separate contact fields.
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
  elseif ($option_group == 'groups' || $option_group == 'mailing_lists') {
    $sql = 'SELECT id AS value, title AS label FROM civicrm_group WHERE is_active = 1 AND is_hidden = 0';
    if ($option_group == 'mailing_lists') {
      $sql .= " AND group_type LIKE '%2%' AND visibility = 'Public Pages'";
    }
  }
  elseif ($option_group == 'location_type') {
    $sql = 'SELECT name AS label, id AS value FROM civicrm_location_type WHERE is_active = 1 ORDER BY is_default DESC';
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
    $sql .= ' ORDER BY start_date DESC';
  }
  elseif ($option_group == 'group_contact') {
    $result = webform_civicrm_api('contact', 'get', array('group' => array($param => 1), 'return.display_name' => 1, 'rowCount' => 100));
    if (!empty($result['values'])) {
      foreach ($result['values'] as $val) {
        $ret[$val['contact_id']] = $val['display_name'];
      }
    }
    return $ret;
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
      $sql .= ' AND (component_id IS NULL' . ($param ? " OR component_id IN ($param))" : ')');
    }
    $sql .= ' ORDER BY weight, label';
  }
  $dao = &CRM_Core_DAO::executeQuery($sql, $vars);
  while ($dao->fetch()) {
    $ret[$dao->value] = $dao->label;
  }
  return $ret;
}


/**
 * Get options for a specific field
 * @Param $key: field key
 * @Param $format: Can return values as a Webform-style string, or a FAPI style array.
 *                 Specify 'str' or 'arr', matey!
 * @Param $data: CiviCRM entity data
 */
function webform_civicrm_field_options($key, $format, $data) {
  $ret = array();
  $fields = webform_civicrm_get_fields();
  $lists = webform_civicrm_get_fields('lists');
  if ($pieces = webform_civicrm_explode_key($key)) {
    list($lobo, $c, $ent, $n, $table, $name) = $pieces;
    if ($name == 'contact_sub_type') {
      list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
      $ret = array($contact_types[$data['contact'][$c]['contact'][1]['contact_type']]) + $sub_types[$data['contact'][$c]['contact'][1]['contact_type']];
    }
    elseif ($name == 'relationship_type_id') {
      $ret = webform_civicrm_get_contact_relationship_types($data['contact'][$c]['contact'][1]['contact_type'], $data['contact'][$n]['contact'][1]['contact_type'], $data['contact'][$c]['contact'][1]['contact_sub_type'], $data['contact'][$n]['contact'][1]['contact_sub_type']);
    }
    elseif ($name == 'relationship_permission') {
      $ret = array(
        1 => t('Contact !a may view and edit contact !b', array('!a' => $c, '!b' => $n)),
        2 => t('Contact !a may view and edit contact !b', array('!a' => $n, '!b' => $c)),
        3 => t('Both contacts may view and edit each other'),
      );
    }
    elseif ($name == 'master_id') {
      foreach ($data['contact'] as $num => $contact) {
        if ($num != $c) {
          $ret[$num] =  t('Contact !num', array('!num' => $num));
        }
      }
    }
    elseif ($name == 'status_id' && $table == 'participant') {
      require_once 'CRM/Event/PseudoConstant.php';
      $ret = CRM_Event_PseudoConstant::participantStatus(NULL, NULL, 'label');
    }
    elseif (isset($lists[$name])) {
      $param = NULL;
      if ($name == 'event_id') {
        $param = $data['reg_options'];
      }
      if ($name == 'assignee_contact_id'
      && !($param = webform_civicrm_aval($data, 'activity:1:assignee_group'))) {
        foreach ($data['contact'] as $num => $contact) {
          $ret['#' . $num] =  t('Contact !num', array('!num' => $num));
        }
      }
      else {
        $ret = webform_civicrm_get_options($lists[$name], $param);
        $field = $fields[$table . '_' . $name];
        // Format money data correctly
        if (!empty($field['data_type']) && $field['data_type'] == 'Money') {
          $old = $ret;
          $ret = array();
          foreach ($old as $key => $val) {
            $ret[number_format(str_replace(',', '', $key), 2, '.', '')] = $val;
          }
        }
      }
    }
  }
  return $format == 'str' ? webform_civicrm_array2str($ret) : $ret;
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
    if (($t['type_a'] == $type_a || !$t['type_a'])
    && ($t['type_b'] == $type_b || !$t['type_b'])
    && ($t['sub_type_a'] == $sub_type_a || !$t['sub_type_a'])
    && ($t['sub_type_b'] == $sub_type_b || !$t['sub_type_b'])) {
      $ret[$t['id'] . '_a'] = $t['label_a_b'];
    }
    // Reciprocal form - only show if different from above
    if (($t['type_a'] == $type_b || !$t['type_a'])
    && ($t['type_b'] == $type_a || !$t['type_b'])
    && ($t['sub_type_a'] == $sub_type_b || !$t['sub_type_a'])
    && ($t['sub_type_b'] == $sub_type_a || !$t['sub_type_b'])
    && ($t['label_a_b'] != $t['label_b_a'] && $t['label_b_a'] || $t['type_a'] != $t['type_b'])) {
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
 * @Param $node - node object
 * @Param $submission - if supplied, will match field keys with submitted values
 */
function webform_civicrm_enabled_fields($node, $submission = NULL) {
  $enabled = array();
  if (!empty($node->webform['components'])) {
    $fields = webform_civicrm_get_fields();
    foreach ($node->webform['components'] as $c) {
      $exp = explode('_', $c['form_key'], 5);
      if (count($exp) == 5) {
        list($lobo, $i, $ent, $n, $id) = $exp;
        if ((isset($fields[$id]) || $id == 'fieldset_fieldset')
        && $lobo == 'civicrm' && is_numeric($i) && is_numeric($n)) {
          if ($submission) {
            $enabled[$c['form_key']] = webform_civicrm_aval($submission, $c['cid']);
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
 * Recursively walk through form array and set default values for fields based on CiviCRM entity data
 * Called by _webform_civicrm_webform_frontend_form_alter() when webform is being viewed
 * @Param $elements: FAPI array
 * @Param $data: Array of CiviCRM data
 */
function webform_civicrm_fill_values(&$elements, $data, $fields, $submitted) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  foreach ($elements as $eid => &$element) {
    if ($eid[0] == '#' || !is_array($element)) {
      continue;
    }
    // Recurse through nested elements
    webform_civicrm_fill_values($element, $data, $fields, $submitted);
    if (!empty($element['#type']) && $element['#type'] == 'fieldset') {
      continue;
    }
    if ($pieces = webform_civicrm_explode_key($eid)) {
      list($lobo, $c, $ent, $n, $table, $name) = $pieces;
      $field = $fields[$table . '_' . $name];
      if (!empty($field['data_type'])) {
        $dt = $element['#civicrm_data_type'] = $field['data_type'];
        // Add CiviCRM JS to link fields
        if ($dt == 'Link' && substr($element['#type'], 0, 4) == 'text') {
          $element['#attributes']['onblur'] = "if (this.value == 'http://') {this.value = '';}";
          $element['#attributes']['onfocus'] = "if (this.value == '') {this.value = 'http://';}";
        }
      }
      // If the user has already entered a value for this field, don't change it
      if (isset($data[$ent][$c][$table][$n][$name])
      && !(isset($element['#webform_component']['cid'])
           && isset($submitted[$element['#webform_component']['cid']]))) {
        $val = $data[$ent][$c][$table][$n][$name];
        if ($element['#type'] == 'date') {
          $dates = explode('-', $val);
          $val = array(
            'year' => $dates[0],
            'month' => intval($dates[1]),
            'day' => intval($dates[2]),
          );
        }
        elseif (($element['#type'] == 'checkboxes' || !empty($element['#multiple'])) && !is_array($val)) {
          $val = explode($sp, trim($val, $sp));
        }
        if ($element['#type'] != 'checkboxes' && empty($element['#multiple']) && is_array($val)) {
          $val = array_pop($val);
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
 * Match a state/province id to its abbr.
 */
function webform_civicrm_state_abbr($input, $ret = 'abbreviation', $country_id = NULL) {
  $input_type = $ret == 'id' ? 'abbreviation' : 'id';
  $sql = "SELECT $ret FROM civicrm_state_province WHERE $input_type = %1";
  $vars = array(1 => array($input, $ret == 'id' ? 'String' : 'Integer'));
  if ($country_id) {
    $sql .= " AND country_id = %2";
    $vars[2] = array($country_id, 'Integer');
  }
  $sql .= " LIMIT 0, 1";
  return CRM_Core_DAO::singleValueQuery($sql, $vars);
}


/**
 * Recursive validation callback for webform submissions.
 */
function _webform_civicrm_form_validate($form, &$form_state, $submitted) {
  // Recurse through form elements.
  foreach (element_children($form) as $key) {
    if (is_array($form[$key]) && ($element = $form[$key])) {
      _webform_civicrm_form_validate($form[$key], $form_state, $submitted);
      if (!empty($element['#civicrm_data_type'])
      && substr($element['#type'], 0, 4) === 'text'
      && $element['#value'] !== NULL
      && $element['#value'] !== '') {
        $dt = $element['#civicrm_data_type'];
        // Validate state/prov abbreviation
        if ($dt == 'state_province_abbr') {
          $ckey = str_replace('state_province', 'country', $key);
          if (!empty($submitted[$ckey]) && is_numeric($submitted[$ckey])) {
            $country_id = $submitted[$ckey];
          }
          else {
            $config = CRM_Core_Config::singleton();
            $country_id = $config->defaultContactCountry;
          }
          $states = webform_civicrm_get_options('state_province', $country_id);
          if ($states && !array_key_exists(strtoupper($element['#value']), $states)) {
            $countries = webform_civicrm_get_options('country');
            form_error($element, t('Mismatch: "@state" is not a state/province of %country. Please enter a valid state/province abbreviation for %field.', array('@state' => $element['#value'], '%country' => $countries[$country_id], '%field' => $element['#title'])));
          }
        }
        // Strings don't need any validation
        // For the moment we don't validate links because of CRM-8968
        elseif ($dt !== 'Link' && $dt !== 'String' && $dt !== 'Memo'
        && CRM_Utils_Type::escape($element['#value'], $dt, FALSE) === NULL) {
          // Allow data type names to be translated
          switch ($dt) {
            case 'Int':
              $dt = t('an integer');
              break;
            case 'Float':
              $dt = t('a number');
              break;
            case 'Link':
              $dt = t('a web address starting with http://');
              break;
            case 'Money':
              $dt = t('a currency value');
              break;
          }
          form_error($element, t('Please enter @type for %field.', array('@type' => $dt, '%field' => $element['#title'])));
        }
      }
    }
  }
}


/**
 * Validation callback for event registrations.
 */
function _webform_civicrm_participant_validate($form, &$form_state, $submitted) {
  $data = $form['#node']->webform_civicrm['data'];
  // Check how many valid contacts we have
  $contacts = array();
  foreach (array_keys($data['contact']) as $c) {
    if (!empty($_SESSION['webform_civicrm'][$form['#node']->nid]['cid'][$c])
    || !empty($submitted["civicrm_{$c}_contact_1_contact_first_name"])
    || !empty($submitted["civicrm_{$c}_contact_1_contact_last_name"])
    || !empty($submitted["civicrm_{$c}_contact_1_contact_organization_name"])
    || !empty($submitted["civicrm_{$c}_contact_1_contact_household_name"])
    || !empty($submitted["civicrm_{$c}_contact_1_email_email"])) {
      $contacts[] = $c;
    }
  }
  $events = array();
  $add = $data['participant_reg_type'] == 'all' ? count($contacts) : 1;
  // Fetch events set in back-end
  foreach ($data['participant'] as $c => $par) {
    if (!empty($par['participant']) && in_array($c, $contacts)) {
      foreach ($par['participant'] as $p) {
        if (!empty($p['event_id']) && $p['event_id'] != 'create_civicrm_webform_element') {
          list($eid) = explode('-', $p['event_id']);
          if (is_numeric($eid)) {
            $events[$eid]['ended'] = TRUE;
            $events[$eid]['title'] = t('this event');
            $events[$eid]['count'] = webform_civicrm_aval($events, "$eid:count", 0) + $add;
          }
        }
      }
    }
  }
  // Add events selected by user
  foreach ($submitted as $field => $value) {
    if (strpos($field, 'participant_event_id')) {
      list($lobo, $c, $ent, $n, $table, $key) = webform_civicrm_explode_key($field);
      if (in_array($c, $contacts)
      || ($c == 1 && $data['participant_reg_type'] == 'all')) {
        $value = is_array($value) ? $value : array($value);
        foreach($value as $val) {
          $eid = explode('-', $val);
          if (count($eid) == 2 && is_numeric($eid[0]) && $eid[0]) {
            $eid = $eid[0];
            $events[$eid]['ended'] = TRUE;
            $events[$eid]['title'] = t('this event');
            $events[$eid]['count'] = webform_civicrm_aval($events, "$eid:count", 0) + $add;
          }
        }
      }
    }
  }
  // Subtract events already registered for - this only works with known contacts
  if ($events && !empty($_SESSION['webform_civicrm_data'][$form['#node']->nid]['cid'])) {
    $dao = &CRM_Core_DAO::executeQuery("SELECT event_id
      FROM civicrm_participant p, civicrm_participant_status_type s
      WHERE s.id = p.status_id AND s.is_counted = 1
      AND event_id IN (" . implode(',', array_keys($events)) . ")
      AND contact_id IN (" . implode(',', $_SESSION['webform_civicrm_data'][$form['#node']->nid]['cid']) . ")
      AND is_test = 0");
    while ($dao->fetch()) {
      if (isset($events[$dao->event_id])) {
        if ((--$events[$dao->event_id]['count']) === 0) {
          unset($events[$dao->event_id]);
        }
      }
    }
  }
  webform_civicrm_event_info($events);
  foreach ($events as $eid => $event) {
    if ($event['ended']) {
      form_set_error($eid, t('Sorry, you may no longer register for %event.', array('%event' => $event['title'])));
    }
    elseif ($event['max_participants'] && $event['count'] > $event['remaining']) {
      if (!empty($event['full'])) {
        form_set_error($eid, '<em>' . $event['title'] . '</em>: ' . $event['full_message']);
      }
      else {
        form_set_error($eid, format_plural($event['remaining'],
          'Sorry, you tried to register !count people for %event but there is only 1 space remaining.',
          'Sorry, you tried to register !count people for %event but there are only @count spaces remaining.', array('%event' => $event['title'], '!count' => $event['count'])));
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
    $config = CRM_Core_Config::singleton();
    $components = $config->enableComponents;

    // Field keys are in the format table_column
    // Use a # sign as a placeholder for field number in the title (or by default it will be appended to the end)
    // Setting 'expose_list' allows the value to be set on the config form
    // Set 'do_not_cache' if the option list is not static
    // Set label for 'empty_option' for exposed lists that do not require input
    $fields['contact_contact_sub_type'] = array(
      'name' => t('Type of'),
      'type' => 'select',
      'value' => '0',
      'expose_list' => TRUE,
      'do_not_cache' => TRUE,
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
    $fields['contact_privacy'] = array(
      'name' => t('Privacy Preferences'),
      'type' => 'select',
      'extra' => array('multiple' => 1),
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
      'do_not_cache' => TRUE,
      'extra' => array('description' => t('Will overwrite address fields with those of the other contact.')),
      'empty_option' => t('- do not share -'),
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
      'do_not_cache' => TRUE,
      'empty_option' => t('- no one -')
    );
    if (in_array('CiviCampaign', $components)) {
      $fields['activity_campaign_id'] = array(
        'name' => t('Campaign'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('- none -'),
      );
    }
    $fields['relationship_relationship_type_id'] = array(
      'name' => t('Relationship to Contact #'),
      'type' => 'select',
      'expose_list' => TRUE,
      'do_not_cache' => TRUE,
      'empty_option' => t('- no relationship -'),
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
      'do_not_cache' => TRUE,
      'empty_option' => t('- no permissions -'),
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
        'name' => t('Event'),
        'type' => 'select',
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
          'empty_option' => t('- none -'),
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
    // key: key accepted by civicrm api
    // value: name of civicrm_option_group
    $lists = array(
      'prefix_id' => 'individual_prefix',
      'suffix_id' => 'individual_suffix',
      'gender_id' => 'gender',
      'preferred_communication_method' => 'preferred_communication_method',
      'privacy' => 'privacy',
      'country_id' => 'country',
      'phone_type_id' => 'phone_type',
      'location_type_id' => 'location_type',
      'website_type_id' => 'website_type',
      'master_id' => 'master_id',
      'relationship_type_id' => 'relationship_type_id',
      'is_active' => 'yes_no',
      'relationship_permission' => 'relationship_permission',
      'contact_sub_type' => 'contact_sub_type',
      'groups' => 'groups',
      'groups_hidden' => 'groups',
      'assignee_contact_id' => 'group_contact',
      'status_id' => 'activity_status',
      'event_id' => 'event',
      'role_id' => 'participant_role',
      'campaign_id' => 'campaign',
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
    if (in_array('CiviEvent', $components)) {
      $sets['participant'] = array('entity_type' => 'participant', 'label' => t('Participant'), 'max_instances' => 9);
    }

    // Pull custom fields and match to Webform element types
    $custom_types = array(
      'Select' => array('type' => 'select'),
      'Multi-Select' => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'AdvMulti-Select' => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'Radio' => array('type' => 'select', 'extra' => array('aslist' => 0)),
      'CheckBox' => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'Text'  => array('type' => 'textfield'),
      'TextArea' => array('type' => 'textarea'),
      'RichTextEditor' => array('type' => 'textarea'),
      'Select Date' => array('type' => 'date'),
      'Link'  => array('type' => 'textfield'),
      'Select Country' => array('type' => 'select'),
      'Multi-Select Country' => array('type' => 'select', 'extra' => array('multiple' => 1)),
      'Select State/Province' => array('type' => 'select'),
      'Multi-Select State/Province' => array('type' => 'select', 'extra' => array('multiple' => 1)),
    );
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    $custom_extends = "'contact','individual','organization','household','address','activity'";
    if (in_array('CiviEvent', $components)) {
      $custom_extends .= ",'participant'";
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
        if ($dao->entity_type == 'address') {
          $set = 'address';
        }
        else {
          $set = 'cg' . $dao->custom_group_id;
          $sets[$set]['label'] = $dao->custom_group_name;
          if ($dao->entity_type != 'activity' && $dao->entity_type != 'participant') {
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
        $label = drupal_strtolower($dao->label);
        $fields[$id] = $custom_types[$dao->html_type];
        $fields[$id]['name'] = $dao->label;
        $fields[$id]['mandatory'] = $dao->is_required;
        $fields[$id]['extra']['description'] = $dao->help_pre;
        $fields[$id]['value'] = str_replace($sp, ',', trim($dao->default_value, $sp));
        $fields[$id]['data_type'] = $dao->data_type;

        if ($fields[$id]['type'] == 'date') {
          $fields[$id]['extra']['start_date'] = ($dao->start_date_years ? '-' . $dao->start_date_years : '-50') . ' years';
          $fields[$id]['extra']['end_date'] = ($dao->end_date_years ? '+' . $dao->end_date_years : '+50') . ' years';
        }
        elseif ($og = $dao->option_group_id) {
          $lists['custom_' . $dao->id] = $og;
        }
        elseif ($dao->html_type == 'Select Country' || $dao->html_type == 'Multi-Select Country') {
          $lists['custom_' . $dao->id] = 'country';
        }
        elseif ($dao->html_type == 'Select State/Province' || $dao->html_type == 'Multi-Select State/Province') {
          $lists['custom_' . $dao->id] = 'state_province';
        }
        elseif ($fields[$id]['type'] == 'select') {
          $lists['custom_' . $dao->id] = 'yes_no';
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
    return empty($_SESSION['CiviCRM']['userID']) ? NULL : $_SESSION['CiviCRM']['userID'];
  }
  $result = webform_civicrm_api('uf_match', 'get', array($type . '_id' => $id));
  if (!empty($result['values'])) {
    $result = array_pop($result['values']);
    return $type == 'uf' ? $result['contact_id'] : $result['uf_id'];
  }
}


/**
 * Explodes form key into an array and verifies that it is in the right format
 */
function webform_civicrm_explode_key($key) {
  $pieces = explode('_', $key, 6);
  if (count($pieces) != 6 || $pieces[0] != 'civicrm') {
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
  // The API doesn't always sort correctly, so we'll do it here:
  elseif (!empty($params['options']['sort']) && !empty($result['values'])) {
    $values = array();
    foreach ($result['values'] as $val) {
      if (empty($val['is_primary'])) {
        $values[] = $val;
      }
      else {
        array_unshift($values, $val);
      }
    }
    $result['values'] = $values;
  }
  return $result;
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
  $sql .= " WHERE is_current_revision = 1 AND is_deleted = 0 AND civicrm_activity.id IN (SELECT activity_id FROM civicrm_activity_target WHERE target_contact_id = $cid)";
  foreach ($params as $field => $value) {
    $sql .= " AND $field = $value";
  }
  $sql .= ' LIMIT 0, 1';
  $dao = &CRM_Core_DAO::executeQuery($sql);
  while ($dao->fetch()) {
    return $dao->id;
  }
}


/**
 * Fetch events set in the back-end
 */
function webform_civicrm_event_count($data) {

  return $events;
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
 * Displays the admin-defined message with "not you?" link to known contacts
 */
function webform_civicrm_set_message($message, $contact) {
  $message = webform_civicrm_replace_tokens($message, $contact);
  preg_match_all('#\{([^}]+)\}#', $message, $matches);
  $path = substr(VERSION, 0, 1) == '6' ? 'logout' : 'user/logout';
  if ($matches[0]) {
    foreach ($matches[0] as $pos => $match) {
      if (user_is_logged_in()) {
        $link = l($matches[1][$pos], $path, array('query' => array('destination' => $_GET['q'])));
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
 * Callback to serve AJAX requests.
 */
function webform_civicrm_js_options($key, $str='') {
  // Populate state/prov lists on the fly
  if ($key == 'state_province') {
    if (!$str || (intval($str) != $str && $str != 'default')) {
      webform_civicrm_json(array('' => t('- first choose a country -')));
    }
    civicrm_initialize();
    webform_civicrm_json(webform_civicrm_get_options('state_province', $str));
  }

  if (!user_access('access CiviCRM')) {
    return drupal_access_denied();
  }
  // Serve AHAH requests for D6
  if (substr($key, 0, 12) == 'civicrm_ahah') {
    webform_civicrm_ahah_callback($key);
  }

  // Autocomplete callback for tags
  if ($key == 'tags') {
    $matches = array();
    if ($str) {
      civicrm_initialize();
      // Handle a comma-separated list of tags.
      $existing = drupal_explode_tags($str);
      $last_str = trim(array_pop($existing));
      $prefix = count($existing) ? implode(', ', $existing) . ', ' : '';
      $params = array(1 => array('%' . $last_str . '%', 'String'));
      $sql = "SELECT name FROM civicrm_tag WHERE is_selectable = 1 AND used_for = 'civicrm_contact' AND name LIKE %1";
      if ($existing) {
        $sql .= " AND name NOT IN ('" . implode("','", $existing) . "')";
      }
      $sql .= " LIMIT 0, 20";
      $dao =& CRM_Core_DAO::executeQuery($sql, $params);
      while ($dao->fetch()) {
        $matches[$prefix . $dao->name] = check_plain($dao->name);
      }
    }
    webform_civicrm_json($matches);
  }
  return drupal_not_found();
}


/**
 * Return a value from an array.
 * @param $haystack: the array to search
 * @param $keys: pass a single key, or multiple keys separated by : to get a nested value
 * @param $default: value to return if given array key does not exist or is empty
 * @return: found value or default
 */
function webform_civicrm_aval($haystack, $keys, $default = NULL) {
  foreach (explode(':', $keys) as $key) {
    if (!is_array($haystack) || !isset($haystack[$key]) || (empty($haystack[$key]) && $default !== NULL)) {
      return $default;
    }
    $haystack = $haystack[$key];
  }
  // $haystack has been reduced down to the item we want
  return $haystack;
}
