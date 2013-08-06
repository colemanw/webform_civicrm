<?php

/**
 * @file
 * Webform CiviCRM module's front-end form postprocessor.
 */

module_load_include('inc', 'webform_civicrm', 'includes/wf_crm_webform_base');

class wf_crm_webform_postprocess extends wf_crm_webform_base {
  private $submission;
  private $id = array();
  private $update = array();
  private $all_fields;
  private $all_sets;

  static $singleton;

  function __construct($node, &$submission) {
    civicrm_initialize();
    $this->node = $node;
    $this->submission = &$submission;
    $this->settings = $node->webform_civicrm;
    $this->data = $this->settings['data'];
    // Retrieve stored ids
    $this->id = wf_crm_aval(wf_crm_storage($node->nid), 'id');
    $this->enabled = wf_crm_enabled_fields($node);
    $this->all_fields = wf_crm_get_fields();
    $this->all_sets = wf_crm_get_fields('sets');
  }

  static function singleton($node, &$submission) {
    if (!self::$singleton) {
      self::$singleton = new wf_crm_webform_postprocess($node, $submission);
    }
    return self::$singleton;
  }

  /**
   * Presave processing - save contact data
   */
  public function preSave() {

    $config = CRM_Core_Config::singleton();
    $shared_address = array();

    // Fetch contact ids from "existing contact" fields
    foreach ($this->enabled as $field_key => $fid) {
      if (substr($field_key, -8) == 'existing') {
        list(, $c, ) = explode('_', $field_key, 3);
        $this->id['cid'][$c] = 0;
        $cid = wf_crm_aval(wf_crm_sub_value($this->submission, $fid), 0);
        if ($cid && is_numeric($cid)) {
          module_load_include('inc', 'webform_civicrm', 'includes/contact_component');
          $component = $this->node->webform['components'][$fid];
          $filters = wf_crm_search_filters($this->node, $component);
          // Verify access to this contact
          if (wf_crm_contact_access($component, $filters, $cid) !== FALSE) {
            $this->id['cid'][$c] = $cid;
          }
        }
      }
    }
    $updating = $this->id;

    // If this is an update op, set param for drupal_write_record()
    if (!empty($this->submission->sid)) {
      $submitted = array($this->submission->sid => new stdClass());
      webform_civicrm_webform_submission_load($submitted);
      if (isset($submitted[$this->submission->sid]->civicrm)) {
        $this->update = 'sid';
      }
    }

    // While saving a draft, just skip to the end and write the record
    if (!empty($this->submission->is_draft)) {
      return;
    }

    // Fill entity arrays with field values
    $this->fillDataFromSubmission($updating);

    // Create/update contacts
    foreach ($this->data['contact'] as $c => $contact) {
      $contact_type = $contact['contact'][1]['contact_type'];
      if (empty($this->id['cid'][$c])) {
        // Don't create contact if we don't have a name or email
        $ok = FALSE;
        foreach (wf_crm_required_contact_fields($contact_type) as $f) {
          if (!empty($contact[$f['table']][1][$f['name']])) {
            $ok = TRUE;
          }
        }
        if (!$ok) {
          $this->id['cid'][$c] = 0;
          continue;
        }
        // Search for an existing contact using default strict rule
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
                $masters = wf_civicrm_api('address', 'get',
                  array('contact_id' => $this->id['cid'][$m], 'sort' => 'is_primary DESC'));
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
        if ($dupes = CRM_Dedupe_Finder::dupesByParams($params, ucfirst($contact_type))) {
          $this->id['cid'][$c] = $dupes[0];
        }
      }
      $params = $contact['contact'][1];

      // Current employer must wait for ContactRef ids to be filled
      unset($params['employer_id']);

      // CiviCRM API is too picky about this, imho
      $params['contact_type'] = ucfirst($contact_type);

      // Create new contact
      if (empty($this->id['cid'][$c])) {
        unset($params['contact_id']);
        $params['source'] = $this->settings['new_contact_source'];
        // If creating individual with no first/last name,
        // set display name and sort_name
        if ($contact_type == 'individual' && empty($params['first_name']) && empty($params['last_name'])) {
          $params['display_name'] = $params['sort_name'] = empty($params['nick_name']) ? $contact['email'][1]['email'] : $params['nick_name'];
        }
        $result = wf_civicrm_api('contact', 'create', $params);
        if (!empty($result['id'])) {
          $this->id['cid'][$c] = $result['id'];
        }
        else {
          $this->id['cid'][$c] = 0;
        }
      }
      // Update existing contact
      else {
        // Fetch data from existing multivalued fields
        $fetch = $multi = array();
        foreach ($this->all_fields as $fid => $field) {
          if (!empty($field['extra']['multiple']) && substr($fid, 0, 7) == 'contact') {
            list(, $name) = explode('_', $fid, 2);
            if ($name != 'privacy' && isset($params[$name])) {
              $fetch["return.$name"] = 1;
              $multi[] = $name;
            }
          }
        }
        // Merge data from existing multivalued fields
        if ($multi) {
          $existing = wf_civicrm_api('contact', 'get', array('id' => $this->id['cid'][$c]) + $fetch);
          $existing = wf_crm_aval($existing, 'values:' . $this->id['cid'][$c], array());
          foreach ($multi as $name) {
            $exist = drupal_map_assoc(wf_crm_aval($existing, $name, array()));
            // Only known contacts are allowed to empty a field
            if (!empty($updating['cid'][$c])) {
              $fid = wf_crm_aval($this->enabled, "civicrm_{$c}_contact_1_contact_$name");
              foreach ($this->getExposedOptions($fid) as $k => $v) {
                unset($exist[$k]);
              }
            }
            $params[$name] = array_unique(array_merge($params[$name], $exist));
          }
        }
        $params['id'] = $this->id['cid'][$c];
        wf_civicrm_api('contact', 'create', $params);
      }
    }
    // Fill ContactRef fields with contact IDs
    $this->fillContactRefs();

    // Create/update other data associated with contacts
    foreach ($this->data['contact'] as $c => $contact) {
      if (!($cid = $this->id['cid'][$c])) {
        continue;
      }
      // Save current employer
      if ($contact['contact'][1]['contact_type'] == 'individual' && !empty($contact['contact'][1]['employer_id'])) {
        wf_civicrm_api('contact', 'create', array(
          'id' => $cid,
          'contact_type' => 'Individual',
          'employer_id' => $contact['contact'][1]['employer_id'],
        ));
      }
      // Save custom data
      wf_crm_save_custom($contact, $cid, 'Contact', !empty($updating['cid'][$c]));

      // Fill values for hidden ID & CS fields
      $fid = 'civicrm_' . $c . '_contact_1_contact_';
      if (!empty($this->enabled[$fid . 'contact_id'])) {
        wf_crm_sub_value($this->submission, $this->enabled[$fid . 'contact_id'], $cid);
      }
      if (!empty($this->enabled[$fid . 'existing'])) {
        wf_crm_sub_value($this->submission, $this->enabled[$fid . 'existing'], $cid);
      }
      if (!empty($this->enabled[$fid . 'external_identifier']) && !empty($updating['cid'][$c])) {
        $exid = wf_civicrm_api('contact', 'get', array('contact_id' => $cid, 'return.external_identifier' => 1));
        wf_crm_sub_value($this->submission, $this->enabled[$fid . 'external_identifier'], wf_crm_aval($exid, "values:$cid:external_identifier"));
      }
      if (!empty($this->enabled[$fid . 'cs'])) {
        $cs = wf_crm_sub_value($this->submission, $this->enabled[$fid . 'cs']);
        $life = !empty($cs[0]) ? intval(24 * $cs[0]) : 'inf';
        $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($cid, NULL, $life);
        wf_crm_sub_value($this->submission, $this->enabled[$fid . 'cs'], $cs);
      }

      // Save location data
      foreach (wf_crm_location_fields() as $location) {
        if (!empty($contact[$location])) {
          $existing = array();
          $params = array('contact_id' => $cid);
          if ($location != 'website') {
            $params['options'] = array('sort' => 'is_primary DESC');
          }
          $result = wf_civicrm_api($location, 'get', $params);
          if (!empty($result['values'])) {
            // start array index at 1
            $existing = array_merge(array(array()), $result['values']);
          }
          foreach ($contact[$location] as $i => $params) {
            // Translate state/prov abbr to id
            if (!empty($params['state_province_id'])) {
              if (!($params['state_province_id'] = wf_crm_state_abbr($params['state_province_id'], 'id', wf_crm_aval($params, 'country_id', $config->defaultContactCountry)))) {
                $params['state_province_id'] = '';
              }
            }
            // Update drupal email address
            if ($location == 'email' && !empty($params['email']) && $i == 1) {
              $uid = wf_crm_user_cid($cid, 'contact');
              if ($uid) {
                $user = user_load($uid);
                if ($params['email'] != $user->mail) {
                  // Verify this email is unique before saving it to user
                  $args = array(':mail' => $params['email']);
                  if (!(db_query("SELECT count(uid) FROM {users} WHERE mail = :mail", $args)->fetchField())) {
                    user_save($user, array('mail' => $params['email']));
                  }
                }
              }
            }
            // Check if anything was changed, else skip the update
            if (!empty($existing[$i])) {
              $same = TRUE;
              foreach ($params as $param => $val) {
                if ($val != (string) wf_crm_aval($existing[$i], $param, '')) {
                  $same = FALSE;
                }
              }
              if ($same) {
                continue;
              }
            }
            if ($location == 'address') {
              // Store shared addresses for later since we haven't necessarily processed
              // the contact this address is shared with yet.
              if (!empty($params['master_id'])) {
                $shared_address[$cid][$i] = array(
                  'id' => wf_crm_aval($existing, "$i:id"),
                  'mc' => $params['master_id'],
                  'loc' => $params['location_type_id'],
                );
                continue;
              }
              // Reset calculated values when updating an address
              $params['master_id'] = $params['geo_code_1'] = $params['geo_code_2'] = 'null';
            }
            $params['contact_id'] = $cid;
            if (!empty($existing[$i])) {
              $params['id'] = $existing[$i]['id'];
            }
            if ($this->locationIsEmpty($location, $params)) {
              // Delete this location if nothing was entered and this is a known contact
              if (!empty($updating['cid'][$c]) && !empty($params['id'])) {
                wf_civicrm_api($location, 'delete', $params);
              }
              continue;
            }
            if ($location != 'website') {
              if (empty($params['location_type_id'])) {
                $params['location_type_id'] = wf_crm_aval($existing, "$i:location_type_id", 1);
              }
              $params['is_primary'] = $i == 1 ? 1 : 0;
            }
            wf_civicrm_api($location, 'create', $params);
          }
        }
      }
      // Process relationships
      if (!empty($contact['relationship'])) {
        foreach ($contact['relationship'] as $i => $params) {
          if (!empty($params['relationship_type_id']) && !empty($this->id['cid'][$i])) {
            $this->processRelationship($params, $cid, $this->id['cid'][$i]);
          }
        }
      }
      // Process groups & tags
      foreach ($this->all_fields as $fid => $field) {
        list($set, $type) = explode('_', $fid, 2);
        if ($set == 'other') {
          $field_name = 'civicrm_' . $c . '_contact_1_' . $fid;
          if (!empty($contact['other'][1][$type]) || isset($this->enabled[$field_name])) {
            $fid = wf_crm_aval($this->enabled, $field_name, 0);
            $add = wf_crm_aval($contact, "other:1:$type", array());
            $remove = empty($updating['cid'][$c]) ? array() : $this->getExposedOptions($fid, $add);
            $this->addOrRemoveMultivaluedData($field['table'], 'contact', $cid, $add, $remove);
          }
        }
      }
      // Process event participation
      if (isset($this->all_sets['participant']) && !empty($this->data['participant_reg_type'])) {
        $n = $this->data['participant_reg_type'] == 'separate' ? $c : 1;
        if ($p = wf_crm_aval($this->data, "participant:$n:participant")) {
          // Fetch existing participant records
          $existing = array();
          $dao = &CRM_CORE_DAO::executeQuery("SELECT id, event_id FROM civicrm_participant WHERE contact_id = $cid AND is_test = 0");
          while ($dao->fetch()) {
            $existing[$dao->event_id] = $dao->id;
          }
          foreach ($p as $e => $params) {
            $remove = array();
            if ($fid = wf_crm_aval($this->enabled, 'civicrm_' . $c . '_participant_' . $e . '_participant_event_id')) {
              foreach ($this->getExposedOptions($fid) as $eid => $title) {
                list($eid) = explode('-', $eid);
                if (isset($existing[$eid])) {
                  $remove[$eid] = $title;
                }
              }
            }
            if (!empty($params['event_id'])) {
              $params['contact_id'] = $cid;
              if (empty($params['campaign_id']) || empty($this->all_fields['participant_campaign_id'])) {
                unset($params['campaign_id']);
              }
              // Reformat custom data from nested arrays
              $custom = array();
              foreach ($this->data['participant'][$n] as $key => $vals) {
                if (substr($key, 0, 2) == 'cg' && isset($vals[$e])) {
                  $custom[$key][1] = $vals[$e];
                }
              }
              // Loop through event ids to support multi-valued form elements
              $events = (array) $params['event_id'];
              foreach ($events as $i => $eid) {
                if (!empty($eid)) {
                  list($eid) = explode('-', $eid);
                  $params['event_id'] = $eid;
                  unset($remove[$eid], $params['registered_by_id'], $params['id'], $params['source']);
                  // Is existing participant?
                  if (!empty($existing[$eid])) {
                    $params['id'] = $existing[$params['event_id']];
                  }
                  else {
                    $params['source'] = check_plain($this->node->title);
                    if ($c > 1 && !empty($registered_by_id[$e][$i])) {
                      $params['registered_by_id'] = $registered_by_id[$e][$i];
                    }
                  }
                  $result = wf_civicrm_api('participant', 'create', $params);
                  // When registering contact 1, store id to apply to other contacts
                  if ($c == 1) {
                    $registered_by_id[$e][$i] = $result['id'];
                  }
                  if ($custom) {
                    wf_crm_save_custom($custom, $result['id'], 'Participant');
                  }
                }
              }
            }
            foreach ($remove as $eid => $title) {
              wf_civicrm_api('participant', 'create', array('status_id' => 4, 'id' => $existing[$eid]));
              drupal_set_message(t('Registration canceled for !event', array('!event' => $title)));
            }
          }
        }
      }
      // Process memberships
      if (isset($this->all_sets['membership']) && !empty($this->data['membership'][$c]['number_of_membership'])) {
        $this->processMembership($c, $cid);
      }
    }
    // Process shared addresses. We do this last after all contacts and addresses exist.
    foreach ($shared_address as $cid => $shared) {
      foreach ($shared as $i => $addr) {
        if (!empty($this->id['cid'][$addr['mc']])) {
          $masters = wf_civicrm_api('address', 'get', array('contact_id' => $this->id['cid'][$addr['mc']], 'options' => array('sort' => 'is_primary DESC')));
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
            wf_civicrm_api('address', 'create', $params);
          }
        }
      }
    }
    
  }

  /**
   * Insert/update processing - save case & activity data
   */
  public function postSave() {
    if (empty($this->submission->is_draft)) {
      // Process case
      if (!empty($this->data['case'][1]['case'][1]['client_id'])) {
        if (empty($this->id['case'][1])) {
          // Search for case
          $case = wf_crm_case_find($this->data['case'][1]);
          if ($case) {
            $this->id['case'][1] = $case['id'];
          }
        }
        // Default subject
        if (empty($this->id['case'][1]) && empty($this->data['case'][1]['case'][1]['subject'])) {
          $this->data['case'][1]['case'][1]['subject'] = check_plain($this->node->title);
        }
        if (!empty($this->id['case'][1])) {
          $this->data['case'][1]['case'][1]['id'] = $this->id['case'][1];
          unset($this->data['case'][1]['case'][1]['creator_id'], $this->data['case'][1]['case'][1]['case_type_id']);
        }
        // Save case
        $result = wf_civicrm_api('case', 'create', $this->data['case'][1]['case'][1]);
        if (!empty($result['id'])) {
          wf_crm_save_custom($this->data['case'][1], $result['id'], 'Case');
          $this->id['case'][1] = $result['id'];
        }
      }
      // Process activity
      if (!empty($this->data['activity'][1]['activity'])) {
        // Search for activity
        if ((!empty($this->id['cid'][1]) && empty($this->data['case'][1])) || (!empty($this->data['case'][1]) && !empty($this->id['case'][1]))) {
          $params = array(
            'activity_type_id' => $this->data['activity'][1]['activity'][1]['activity_type_id'],
            'status_id' => $this->data['activity'][1]['existing_activity_status'],
          );
          if (!empty($this->data['case'][1])) {
            $params['case_id'] = $this->id['case'][1];
          }
          else {
            $params['contact_id'] = $this->id['cid'][1];
          }
          if (empty($this->id['act'][1])) {
            $this->id['act'][1] = wf_crm_activity_find($params);
          }
        }
        // Save activity
        $params = $this->data['activity'][1]['activity'][1];
        // Existing activity - set id
        if (!empty($this->id['act'][1])) {
          $params['id'] = $this->id['act'][1];
        }
        // New activity - set case id and source_contact
        else {
          if (!empty($this->id['case'][1])) {
            $params['case_id'] = $this->id['case'][1];
            $params['medium_id'] = $this->data['case'][1]['case'][1]['medium_id'];
          }
          $cid = wf_crm_user_cid();
          if ($cid) {
            $params['source_contact_id'] = $cid;
          }
          else {
            foreach ($this->id['cid'] as $cid) {
              if ($cid) {
                $params['source_contact_id'] = $cid;
                break;
              }
            }
          }
        }
        // Can't pass an empty contact id to the api
        if (empty($params['assignee_contact_id'])) {
          unset($params['assignee_contact_id']);
        }
        // Format details as html
        $params['details'] = nl2br(wf_crm_aval($params, 'details', ''));
        if (empty($params['subject'])) {
          $params['subject'] = $this->data['activity'][1]['activity'][1]['subject'];
        }
        if (!empty($this->data['activity'][1]['details']['entire_result'])) {
          module_load_include('inc', 'webform', 'includes/webform.submissions');
          $params['details'] .= webform_submission_render($this->node, $this->submission, NULL, 'html');
        }
        if (!empty($this->data['activity'][1]['details']['view_link'])) {
          $params['details'] .= '<p>' . l(t('View Webform Submission'), 'node/' . $this->node->nid . '/submission/' . $this->submission->sid, array('absolute' => TRUE, 'alias' => TRUE)) . '</p>';
        }
        if (!empty($this->data['activity'][1]['details']['edit_link'])) {
          $params['details'] .= '<p>' . l(t('Edit Submission'), 'node/' . $this->node->nid . '/submission/' . $this->submission->sid . '/edit',  array('absolute' => TRUE, 'alias' => TRUE)) . '</p>';
        }
        if (empty($params['campaign_id']) || empty($this->all_fields['activity_campaign_id'])) {
          unset($params['campaign_id']);
        }
        if (!empty($this->data['activity'][1]['activity'][1]['survey_id'])) {
          $params['source_record_id'] = $this->data['activity'][1]['activity'][1]['survey_id'];
        }
        $result = wf_civicrm_api('activity', 'create', $params);

        if (!empty($result['id'])) {
          $this->id['act'][1] = $result['id'];
          wf_crm_save_custom($this->data['activity'][1], $this->id['act'][1], 'Activity');
        }
        if (!empty($this->id['act'][1]) && !empty($params['assignee_contact_id'])) {
          if (CRM_Core_BAO_Setting::getItem(CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME, 'activity_assignee_notification')) {
            // Send email to assignees. TODO: Move to CiviCRM API?
            $assignee = wf_civicrm_api('contact', 'get', array('id' => $params['assignee_contact_id']));
            $assignee = wf_crm_aval($assignee, 'values:' . $params['assignee_contact_id']);
            if (!empty($assignee['email'])) {
              $mail = array($assignee['email'] => $assignee);
              // Include attachments while sendig a copy of activity.
              $attachments = &CRM_Core_BAO_File::getEntityFile('civicrm_activity', $this->id['act'][1]);
              CRM_Case_BAO_Case::sendActivityCopy(NULL, $this->id['act'][1], $mail, $attachments, NULL);
            }
          }
        }
      }
    }
    // Write record; we do this when creating, updating, or saving a draft of a webform submission.
    $cid = '-';
    foreach (array_keys($this->data['contact']) as $c) {
      $cid .= (empty($this->id['cid'][$c]) ? 0 : $this->id['cid'][$c]) . '-';
    }
    $record = array(
      'sid' => $this->submission->sid,
      'contact_id' => $cid,
      'activity_id' => empty($this->id['act'][1]) ? 0 : $this->id['act'][1],
    );
    drupal_write_record('webform_civicrm_submissions', $record, $this->update);
  }

  /**
   * Handle adding/removing multivalued data for a contact/activity/etc.
   * Currently used only for groups and tags, but written with expansion in mind.
   *
   * @param $data_type
   *   'group' or 'tag'
   * @param $entity_type
   *   Parent entity: 'contact' etc.
   * @param $id
   *   Entity id
   * @param $add
   *   Groups/tags to add
   * @param array $remove
   *   Groups/tags to remove
   */
  private function addOrRemoveMultivaluedData($data_type, $entity_type, $id, $add, $remove = array()) {
    $confirmations_sent = $existing = $params = array();
    $add = drupal_map_assoc($add);
    static $mailing_lists = array();

    switch ($data_type) {
      case 'group':
        $api = 'group_contact';
        break;
      case 'tag':
        $api = 'entity_tag';
        break;
      default:
        $api = $data_type;
    }
    if (!empty($add) || !empty($remove)) {
      // Retrieve current records for this entity
      if ($entity_type == 'contact') {
        $params['contact_id'] = $id;
      }
      else {
        $params['entity_id'] = $id;
        $params['entity_type'] = 'civicrm_' . $entity_type;
      }
      $fetch = wf_civicrm_api($api, 'get', $params);
      foreach (wf_crm_aval($fetch, 'values', array()) as $i) {
        $existing[] = $i[$data_type . '_id'];
        unset($add[$i[$data_type . '_id']]);
      }
      foreach ($remove as $i => $name) {
        if (!in_array($i, $existing)) {
          unset($remove[$i]);
        }
      }
    }
    if (!empty($add)) {
      // Prepare for sending subscription confirmations
      if ($data_type == 'group' && !empty($this->node->webform_civicrm['confirm_subscription'])) {
        // Retrieve this contact's primary email address and perform error-checking
        $result = wf_civicrm_api('email', 'get', array('contact_id' => $id, 'options' => array('sort' => 'is_primary DESC')));
        if (!empty($result['values'])) {
          foreach ($result['values'] as $value) {
            if (($value['is_primary'] || empty($email)) && strpos($value['email'], '@')) {
              $email = $value['email'];
            }
          }
          $mailer_params = array(
            'contact_id' => $id,
            'email' => $email,
          );
          if (empty($mailing_lists)) {
            $mailing_lists = wf_crm_apivalues('group', 'get', array('visibility' => 'Public Pages', 'group_type' => 2), 'title');
          }
        }
      }
      foreach ($add as $a) {
        $params[$data_type . '_id'] = $mailer_params['group_id'] = $a;
        if ($data_type == 'group' && isset($mailing_lists[$a]) && !empty($email)) {
          $result = wf_civicrm_api('mailing_group', 'event_subscribe', $mailer_params);
          if (empty($result['is_error'])) {
            $confirmations_sent[] = check_plain($mailing_lists[$a]);
          }
          else {
            wf_civicrm_api($api, 'create', $params);
          }
        }
        else {
          wf_civicrm_api($api, 'create', $params);
        }
      }
      if ($confirmations_sent) {
        drupal_set_message(t('A message has been sent to %email to confirm subscription to !group.', array('%email' => $email, '!group' => '<em>' . implode('</em> ' . t('and') . ' <em>', $confirmations_sent) . '</em>')));
      }
    }
    // Remove data from entity
    foreach ($remove as $a => $name) {
      $params[$data_type . '_id'] = $a;
      wf_civicrm_api($api, 'delete', $params);
    }
    if (!empty($remove) && $data_type == 'group') {
      $display_name = wf_civicrm_api('contact', 'get', array('contact_id' => $id, 'return.display_name' => 1));
      $display_name = wf_crm_aval($display_name, "values:$id:display_name", t('Contact'));
      drupal_set_message(t('%contact has been removed from !group.', array('%contact' => $display_name, '!group' => '<em>' . implode('</em> ' . t('and') . ' <em>', $remove) . '</em>')));
    }
  }

  /**
   * Dispatch function to add/update relationship for a pair of contacts
   * Called during webform submission
   *
   * @param $params
   *   Params array for relationship api
   * @param $cid1
   *   Contact id
   * @param $cid2
   *   Contact id
   */
  private function processRelationship($params, $cid1, $cid2) {
    if ($cid1 != $cid2) {
      list($type, $side) = explode('_', $params['relationship_type_id']);
      $existing = $this->getRelationship(array($params['relationship_type_id']), $cid1, $cid2);
      $perm = wf_crm_aval($params, 'relationship_permission');
      // Swap contacts if this is an inverse relationship
      if ($side == 'b' || ($existing && $existing['contact_id_a'] != $cid1)) {
        list($cid1, $cid2) = array($cid2, $cid1);
        if ($perm == 1 || $perm == 2) {
          $perm = $perm == 1 ? 2 : 1;
        }
      }
      $params += $existing;
      $params['contact_id_a'] = $cid1;
      $params['contact_id_b'] = $cid2;
      $params['relationship_type_id'] = $type;
      if ($perm) {
        $params['is_permission_a_b'] = $params['is_permission_b_a'] = $perm == 3 ? 1 : 0;
        if ($perm == 1 || $perm == 2) {
          $params['is_permission_' . ($perm == 1 ? 'a_b' : 'b_a')] = 1;
        }
      }
      unset($params['relationship_permission']);
      wf_civicrm_api('relationship', 'create', $params);
    }
  }

  /**
   * Process memberships for a contact
   * Called during webform submission
   * @param int $c
   * @param int $cid
   */
  private function processMembership($c, $cid) {
    static $types;
    if (!isset($types)) {
      $types = wf_crm_apivalues('membership_type', 'get');
    }
    $existing = wf_crm_apivalues('membership', 'get', array('contact_id' => $cid, 'filters' => array('is_current' => 1)));
    foreach (wf_crm_aval($this->data, "membership:$c:membership", array()) as $params) {
      if (empty($params['membership_type_id'])) {
        continue;
      }
      // Search for existing membership to renew - must belong to same domain and organization
      // But not necessarily the same membership type to allow for upsell
      if (!empty($params['num_terms'])) {
        $type = $types[$params['membership_type_id']];
        foreach ($existing as $id => $mem) {
          $existing_type = $types[$mem['membership_type_id']];
          if ($type['domain_id'] == $existing_type['domain_id'] && $type['member_of_contact_id'] == $existing_type['member_of_contact_id']) {
            $params['id'] = $id;
            break;
          }
        }
      }
      if (empty($params['id']) && !empty($this->node->webform_civicrm['new_contact_source'])) {
        $params['source'] = $this->node->webform_civicrm['new_contact_source'];
      }
      $params['contact_id'] = $cid;
      $result = wf_civicrm_api('membership', 'create', $params);
    }
  }

  /**
   * Recursive function to fill ContactRef fields with contact IDs
   * Called during webform submission
   *
   * @param $values null|array
   *   Leave blank - used internally to recurse through data
   * @param $depth int
   *   Leave blank - used internally to track recursion level
   */
  private function fillContactRefs($values = NULL, $depth = 0) {
    $order = array('ent', 'c', 'table', 'n', 'name');
    static $ent = '';
    static $c = '';
    static $table = '';
    static $n = '';
    if ($values === NULL) {
      $values = $this->data;
    }
    foreach ($values as $key => $val) {
      ${$order[$depth]} = $key;
      if ($depth < 4 && is_array($val)) {
        $this->fillContactRefs($val, $depth + 1);
      }
      elseif ($depth == 4 && $val && wf_crm_aval($this->all_fields, "{$table}_$name:data_type") == 'ContactReference') {
        if (is_array($val)) {
          $this->data[$ent][$c][$table][$n][$name] = array();
          foreach ($val as $v) {
            if (is_numeric($v) && !empty($this->id['cid'][$v])) {
              $this->data[$ent][$c][$table][$n][$name][] = $this->id['cid'][$v];
            }
          }
        }
        else {
          unset($this->data[$ent][$c][$table][$n][$name]);
          if (!empty($this->id['cid'][$val])) {
            $this->data[$ent][$c][$table][$n][$name] = $this->id['cid'][$val];
          }
        }
      }
    }
  }

  /**
   * Dispatch function to fill data array with submitted form values
   * Called during webform submission
   *
   * @param array $updating
   *   Known contact ids
   */
  private function fillDataFromSubmission($updating) {
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    $fields = wf_crm_get_fields();
    foreach ($this->enabled as $field_key => $fid) {
      $val = wf_crm_sub_value($this->submission, $fid);
      if ($val !== FALSE) {
        list( , $c, $ent, $n, $table, $name) = explode('_', $field_key, 6);
        // Fieldsets and existing contact fields are not CRM data, so ignore
        if ($name === 'existing' || $name === 'fieldset') {
          continue;
        }
        // Ignore values from fields hidden by existing contact component
        if ($ent == 'contact' && isset($this->enabled["civicrm_{$c}_contact_1_contact_existing"])) {
          $component = $this->node->webform['components'][$this->enabled["civicrm_{$c}_contact_1_contact_existing"]];
          $existing_contact_val = wf_crm_sub_value($this->submission, $component['cid']);
          // Fields should be hidden if value is empty (no selection) or a numeric contact id
          if (!$existing_contact_val[0] || is_numeric($existing_contact_val[0])) {
            $type = ($table == 'contact' && strpos($name, 'name')) ? 'name' : $table;
            if (in_array($type, $component['extra']['hide_fields'])) {
              // Remove the value from the webform submission
              wf_crm_sub_value($this->submission, $fid, array(NULL));
              continue;
            }
          }
        }
        $field = $fields[$table . '_' . $name];
        // Ignore values from hidden fields
        if ($field['type'] == 'hidden') {
          continue;
        }
        // Translate privacy options into seperate values
        if ($name === 'privacy') {
          foreach (array_keys($this->getExposedOptions($fid)) as $key) {
            $this->data[$ent][$c][$table][$n][$key] = in_array($key, $val);
          }
          continue;
        }
        if (!empty($field['extra']['multiple'])) {
          // Merge with existing data
          if (!empty($this->data[$ent][$c][$table][$n][$name]) && is_array($this->data[$ent][$c][$table][$n][$name])) {
            $val = array_unique(array_merge($val, $this->data[$ent][$c][$table][$n][$name]));
          }
          // Implode data that will be stored as a string
          if ($table !== 'other' && $name !== 'event_id' && $table !== 'contact' && wf_crm_aval($field, 'data_type') != 'ContactReference') {
            $val = $sp . implode($sp, $val) . $sp;
          }
        }
        elseif ($name === 'image_URL') {
          if (empty($val[0]) || !($val = wf_crm_filepath($val[0]))) {
            continue;
          }
        }
        elseif ($field['type'] === 'date') {
          $val = empty($val[0]) ? '' : str_replace('-', '', $val[0]);
          // Add time field value
          $time = wf_crm_aval($this->data, "$ent:$c:$table:$n:$name", '');
          // Remove default date if it has been added
          if (strlen($time) == 14) {
            $time = substr($time, -6);
          }
          $val .= $time;
        }
        else {
          $val = isset($val[0]) ? $val[0] : '';
        }
        // Fudge together date and time fields
        if ($field['type'] === 'time' && substr($name, -8) === 'timepart') {
          $name = str_replace('_timepart', '', $name);
          // Add date (default to today)
          $date = wf_crm_aval($this->data, "$ent:$c:$table:$n:$name", date('Ymd'));
          $val = $date . str_replace(':', '', $val);
        }
        // Only known contacts are allowed to empty a field
        if (($val !== '' && $val !== NULL) || !empty($updating['cid'][$c])) {
          $this->data[$ent][$c][$table][$n][$name] = $val;
        }
      }
    }
  }

  /**
   * Test if any relevant location data has been entered
   * @param string $location
   * @param array $params
   * @return bool
   */
  private function locationIsEmpty($location, $params) {
    switch ($location) {
      case 'address':
        return empty($params['street_address'])
        && empty($params['city'])
        && empty($params['state_province_id'])
        && empty($params['country_id'])
        && empty($params['postal_code'])
        && empty($params['master_id']);
      case 'website':
        return empty($params['url']);
      default:
        return empty($params[$location]);
    }
  }

}
