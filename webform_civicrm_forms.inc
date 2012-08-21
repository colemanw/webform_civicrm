<?php

/**
 * @file
 * Webform CiviCRM module's front-end form functions.
 */

module_load_include('inc', 'webform_civicrm', 'webform_civicrm_utils');

/**
 * Alter front-end of webforms: Called by hook_form_alter() when rendering a civicrm-enabled webform
 * Add custom prefix.
 * Display messages.
 * Block users who should not have access.
 * Set webform default values.
 */
function _webform_civicrm_webform_frontend_form_alter(&$form, &$form_state) {
  civicrm_initialize();
  $form['#attached']['js'][] = drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_forms.js';
  $form['#validate'][] = 'webform_civicrm_form_validate';
  array_unshift($form['#submit'], 'webform_civicrm_storage');
  $config = CRM_Core_Config::singleton();
  $node = $form['#node'];
  $settings = $node->webform_civicrm;
  $data = $settings['data'];
  $fields = webform_civicrm_get_fields();
  $id['cid'] = $id = $info = array();
  // JS Cache eliminates the need for most ajax state/province callbacks
  foreach ($data['contact'] as $c) {
    if (!empty($c['number_of_address'])) {
      $js_vars = array(
        'defaultCountry' => $config->defaultContactCountry,
        'defaultStates' => webform_civicrm_get_options('state_province', $config->defaultContactCountry),
        'noCountry' => t('- First Choose a Country -'),
        'callbackPath' => url('webform-civicrm/js/state_province', array('alias' => TRUE)),
      );
      $form['#attached']['js'][] = array(
        'data' => array('webform_civicrm' => $js_vars),
        'type' => 'setting',
      );
      break;
    }
  }
  // Early return if the form (or page) was already submitted
  if (webform_civicrm_aval($form_state, 'triggering_element:#id') == 'edit-previous'
  || (empty($form_state['rebuild']) && !empty($form_state['storage']))) {
    webform_civicrm_fill_form($form['submitted'], $node);
    return;
  }
  // If this is an edit op, use the original IDs and return
  if (isset($form['#submission']->sid)) {
    if (isset($form['#submission']->civicrm)) {
      $form_state['civicrm']['id']['cid'] = $form['#submission']->civicrm['contact_id'];
      $form_state['civicrm']['id']['act'][1] = $form['#submission']->civicrm['activity_id'];
      foreach ($form_state['civicrm']['id']['cid'] as $c => $cid) {
        $info['contact'][$c]['contact'][1]['existing'] = $cid;
      }
    }
    webform_civicrm_fill_form($form['submitted'], $node, $info);
    return;
  }
  $enabled = webform_civicrm_enabled_fields($node);
  // If this form is already in-process, IDs will be stored
  if (!empty($form_state['civicrm'])) {
    $id = $form_state['civicrm']['id'];
  }
  // Search for existing contacts
  else {
    $count = count($data['contact']);
    for ($i = 1; $i <= $count; ++$i) {
      if ($existing_component = webform_civicrm_aval($enabled, "civicrm_{$i}_contact_1_contact_existing")) {
        module_load_include('inc', 'webform_civicrm', 'contact_component');
        webform_civicrm_find_contact($node, $node->webform['components'][$existing_component], $id['cid']);
      }
    }
  }
  // Lookup activity if passed in url
  if (!empty($_GET['aid']) && !empty($id['cid']) && is_numeric($_GET['aid'])) {
    $result = webform_civicrm_api('activity', 'get', array('activity_id' => $_GET['aid'], 'return.target_contact_id' => 1, 'return.assignee_contact_id' => 1));
    if (isset($result['values'][$_GET['aid']])) {
      $act = $result['values'][$_GET['aid']];
      $valid = FALSE;
      // Verify that this activity is the right type and that our contacts have some involvement in it
      if ($act['activity_type_id'] == $data['activity'][1]['activity'][1]['activity_type_id']) {
        foreach ($id['cid'] as $cid) {
          if ($act['source_contact_id'] == $cid || in_array($cid, $act['target_contact_id']) || in_array($cid, $act['assignee_contact_id'])) {
            $activity = array('activity' => array(1 => $result['values'][$_GET['aid']]));
            $custom = webform_civicrm_get_custom($_GET['aid'], 'activity');
            $info['activity'] = array(1 => ($activity + $custom));
            $id['act'][1] = $_GET['aid'];
            if (!empty($data['case'][1])) {
              $result = webform_civicrm_api('case', 'get', array('activity_id' => $id['act'][1]));
              if ($id['case'][1] = webform_civicrm_aval($result, 'id')) {
                $custom = webform_civicrm_get_custom($id['case'][1], 'case');
                $info['case'] = array(1 => (array('case' => array(1 => $result['values'][$result['id']])) + $custom));
              }
            }
            break;
          }
        }
      }
    }
  }
  // Search for case & activity
  else {
    if (!empty($data['case'][1]['existing_case_status']) && !empty($data['case'][1]['case'][1]['client_id'])) {
      foreach ((array) $data['case'][1]['case'][1]['client_id'] as $client) {
      $result = webform_civicrm_api('case', 'get', array('client_id' => $id['cid'][1]));
        foreach (webform_civicrm_aval($result, 'values', array()) as $case) {
          $case_type_id = explode(',', $case['case_type_id']);
          if (in_array($case['status_id'], $data['case'][1]['existing_case_status']) && empty($case['is_deleted']) && in_array($data['case'][1]['case'][1]['case_type_id'], $case_type_id)) {
            $id['case'][1] = $case['id'];
            $custom = webform_civicrm_get_custom($id['case'][1], 'case');
            $info['case'] = array(1 => (array('case' => array(1 => $case)) + $custom));
            break 2;
          }
        }
      }
    }
    if (!empty($data['activity'][1]['existing_activity_status']) && !empty($id['cid'][1])) {
      $params = array(
        'activity_type_id' => $data['activity'][1]['activity'][1]['activity_type_id'],
        'status_id' => $data['activity'][1]['existing_activity_status'],
      );
      if (!empty($id['case'][1])) {
        $params['case_id'] = $id['case'][1];
      }
      else {
        $params['contact_id'] = $id['cid'][1];
      }
      if ($id['act'][1] = webform_civicrm_activity_find($params)) {
        $result = webform_civicrm_api('activity', 'get', array('activity_id' => $id['act'][1]));
        $activity = array('activity' => array(1 => $result['values'][$id['act'][1]]));
        $custom = webform_civicrm_get_custom($id['act'][1], 'activity');
        $info['activity'] = array(1 => ($activity + $custom));
      }
    }
  }
  // Form alterations for unknown contacts
  if (empty($id['cid'][1])) {
    if ($settings['prefix_unknown']) {
      $form['#prefix'] = webform_civicrm_aval($form, '#prefix', '') . '<div class="webform-civicrm-prefix contact-unknown">' . nl2br($settings['prefix_unknown']) . '</div>';
    }
    if ($settings['block_unknown_users']) {
      $form['submitted']['#access'] = $form['actions']['#access'] = FALSE;
      drupal_set_message(t('Sorry, you do not have permission to access this form.'), 'warning', FALSE);
      return;
    }
  }
  // Check if events are open to registration and take appropriate action
  $events = array();
  $reg = webform_civicrm_aval($data, 'reg_options', array());
  if (!empty($data['participant_reg_type'])) {
    // Fetch events set in back-end
    $data += array('participant' => array());
    foreach ($data['participant'] as $e => $par) {
      if (!empty($par['participant'])) {
        foreach ($par['participant'] as $n => $p) {
          if (!empty($p['event_id'])) {
            // Handle multi-valued event selection
            foreach ((array) $p['event_id'] as $eid) {
              if ($eid = (int) $eid) {
                $events[$eid]['ended'] = TRUE;
                $events[$eid]['title'] = t('this event');
                $events[$eid]['count'] = webform_civicrm_aval($events, "$eid:count", 0) + 1;
                $events[$eid]['form'][] = array(
                  'contact' => $e,
                  'num' => $n,
                  'eid' => NULL
                );
              }
            }
          }
        }
      }
    }
    // Add events exposed to the form
    foreach ($enabled as $field => $fid) {
      if (strpos($field, 'participant_event_id')) {
        foreach (webform_civicrm_exposed_options($node, $fid) as $p => $label) {
          list($eid) = explode('-', $p);
          $events[$eid]['ended'] = TRUE;
          $events[$eid]['title'] = $label;
          list(, $e, , $n) = explode('_', $field);
          $events[$eid]['form'][] = array(
            'contact' => $e,
            'num' => $n,
            'eid' => $p
          );
        }
      }
    }
    if ($events && (!empty($reg['show_remaining']) || !empty($reg['block_form']))) {
      webform_civicrm_event_info($events);
      foreach ($events as $eid => $event) {
        if ($event['ended']) {
          if (!empty($reg['show_remaining']) && empty($form_state['input']) && empty($form_state['storage'])) {
            drupal_set_message(t('Sorry, %event has ended.', array('%event' => $event['title'])), 'warning', FALSE);
          }
        }
        elseif ($event['full']) {
          if (!empty($reg['show_remaining']) && empty($form_state['input']) && empty($form_state['storage'])) {
            drupal_set_message('<em>' . $event['title'] . '</em>: ' . $event['full_message'], 'warning', FALSE);
          }
        }
        else {
          $reg['block_form'] = FALSE;
          if ($event['max_participants'] && empty($form_state['input']) && empty($form_state['storage'])
          && ($reg['show_remaining'] == 'always' || intval($reg['show_remaining']) >= $event['remaining'])) {
            drupal_set_message(format_plural($event['remaining'],
              '%event has 1 remaining space.',
              '%event has @count remaining spaces.',
              array('%event' => $event['title'])), 'status', FALSE);
          }
        }
      }
      if ($reg['block_form']) {
        $form['submitted']['#access'] = $form['actions']['#access'] = FALSE;
        return;
      }
    }
  }
  // Form alterations for known contacts
  $count = count($data['contact']);
  foreach ($data['contact'] as $c => $contact) {
    if ($cid = webform_civicrm_aval($id['cid'], $c)) {
      // Retrieve contact data
      $info['contact'][$c] = webform_civicrm_contact_get($node, $enabled, $c, $cid);
      $info['contact'][$c]['contact'][1]['existing'] = $cid;
      // Retrieve participant data
      if ($events && ($c == 1 || $data['participant_reg_type'] == 'separate')) {
        $select = array('id', 'event_id', 'role_id', 'status_id');
        if (in_array('CiviCampaign', $config->enableComponents, TRUE)) {
          $select[] = 'campaign_id';
        }
        $dao = &CRM_Core_DAO::executeQuery('SELECT ' . implode(',', $select) . " FROM civicrm_participant WHERE contact_id = $cid AND event_id IN (" . implode(',', array_keys($events)) . ") AND status_id IN (SELECT id FROM civicrm_participant_status_type WHERE class <> 'Negative')");
        while ($dao->fetch()) {
          $par = array();
          foreach ($select as $sel) {
            $par['participant'][1][$sel] = $dao->$sel;
          }
          $par += webform_civicrm_get_custom($dao->id, 'Participant');
          foreach ($events[$dao->event_id]['form'] as $event) {
            if ($event['contact'] == $c) {
              $n = $event['contact'];
              $i = $event['num'];
              // Support multi-valued form elements
              $event_ids = webform_civicrm_aval($info, "participant:$n:participant:$i:event_id", array());
              if ($event['eid']) {
                $event_ids[] = $event['eid'];
              }
              foreach ($par as $k => $v) {
                $info['participant'][$n][$k][$i] = $v[1];
              }
              $info['participant'][$n]['participant'][$i]['event_id'] = $event_ids;
            }
          }
        }
      }
    }
  }
  if (!empty($id['cid'][1])) {
    if ($settings['prefix_known']) {
      $form['#prefix'] = webform_civicrm_aval($form, '#prefix', '') . '<div class="webform-civicrm-prefix contact-known">' . nl2br(webform_civicrm_replace_tokens($settings['prefix_known'], $info['contact'][1]['contact'][1])) . '</div>';
    }
    if ($settings['message'] && empty($form_state['input']) && empty($form_state['storage'])) {
      webform_civicrm_set_message($settings['message'], $info['contact'][1]['contact'][1]);
    }
  }
  // Store ids
  $form_state['civicrm']['id'] = $id;
  // Set default values and other attributes for CiviCRM form elements
  // Passing $submitted helps avoid overwriting values that have been entered on a multi-step form
  $submitted = webform_civicrm_aval($form_state, 'values:submitted', array());
  webform_civicrm_fill_form($form['submitted'], $node, $info, $submitted);
}

/**
 * Fetch all relevant data for a given contact
 * Used to load contacts for pre-filling a webform, and also to fill in a contact via ajax
 */
function webform_civicrm_contact_get($node, $enabled, $c, $cid, $exclude = array()) {
  $info = array();
  $contact = $node->webform_civicrm['data']['contact'][$c];
  foreach (array('contact', 'address', 'email', 'phone', 'website') as $field) {
    if ((!empty($contact['number_of_' . $field]) && !in_array($field, $exclude)) || $field == 'contact') {
      $params = array('contact_id' => $cid);
      if ($field != 'contact' && $field != 'website') {
        $params['options']['sort'] = 'is_primary DESC';
      }
      $result = webform_civicrm_api($field, 'get', $params);
      if (!empty($result['values'])) {
        $result = array_merge(array(0), array_values($result['values']));
        unset($result[0]);
        if ($field == 'contact') {
          // Privacy fields
          foreach (array_keys(webform_civicrm_get_options('privacy')) as $key) {
            if (!empty($result[1][$key])) {
              $result[1]['privacy'][] = $key;
            }
          }
        }
        // Extra processing for addresses
        if ($field == 'address') {
          foreach ($result as &$address) {
            // Translate to abbr
            if (!empty($address['state_province_id'])) {
              $address['state_province_id'] = webform_civicrm_state_abbr($address['state_province_id']);
            }
            // Load custom data
            $custom = webform_civicrm_get_custom($address['id'], 'address');
            if (!empty($custom['address'])) {
              $address += $custom['address'][1];
            }
          }
        }
        $info[$field] = $result;
      }
    }
  }
  // Get custom contact data if needed
  foreach ($contact as $k => $v) {
    if (substr($k, 0, 12) == 'number_of_cg' && !empty($v)) {
      if (!in_array(substr($k, 10), $exclude)) {
        $info += webform_civicrm_get_custom($cid);
        break;
      }
    }
  }
  // Communication prefs are not fetched by default by the api
  if (isset($enabled['civicrm_' . $c . '_contact_1_contact_preferred_communication_method'])
  || isset($enabled['civicrm_' . $c . '_contact_1_contact_preferred_language'])) {
    if (!in_array('contact', $exclude)) {
      $result = webform_civicrm_api('contact', 'get', array(
        'contact_id' => $cid,
        'return.preferred_communication_method' => 1,
        'return.preferred_language' => 1,
      ));
      $info['contact'][1] += $result['values'][$cid];
    }
  }
  // Retrieve group and tag data
  if (!in_array('other', $exclude)) {
    $prefix = 'civicrm_' . $c . '_contact_1_other_';
    $api = array('tag' => 'entity_tag', 'group' => 'group_contact');
    foreach (array_keys($enabled) as $fid) {
      // This way we support multiple tag fields (for tagsets)
      if (strpos($fid, $prefix) !== FALSE) {
        list(, , , , , $ent) = explode('_', $fid);
        list(, , , , , $field) = explode('_', $fid, 6);
        // Cheap way to avoid fetching the same data twice from the api
        if (!is_array($api[$ent])) {
          $api[$ent] = webform_civicrm_api($api[$ent], 'get', array('contact_id' => $cid));
        }
        foreach (webform_civicrm_aval($api[$ent], 'values') as $val) {
          $info['other'][1][$field][] = $val[$ent . '_id'];
        }
      }
    }
  }
  return $info;
}

/**
 * Recursively walk through form array and set properties of CiviCRM fields
 * Called by _webform_civicrm_webform_frontend_form_alter() when webform is being viewed
 * @Param $elements: FAPI array
 * @Param $node: Object
 * @Param $data: CiviCRM data to autofill form
 * @Param $submitted: Existing submission
 */
function webform_civicrm_fill_form(&$elements, $node, $data = array(), $submitted = array()) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  $fields = webform_civicrm_get_fields();
  foreach ($elements as $eid => &$element) {
    if ($eid[0] == '#' || !is_array($element)) {
      continue;
    }
    // Recurse through nested elements
    webform_civicrm_fill_form($element, $node, $data, $submitted);
    if (empty($element['#type']) || $element['#type'] == 'fieldset' || $element['#type'] == 'file') {
      continue;
    }
    if (!empty($element['#webform_component']) && $pieces = webform_civicrm_explode_key($eid)) {
      list( , $c, $ent, $n, $table, $name) = $pieces;
      // Separate out time fields
      if (substr($name, -8) === 'timepart') {
        $name = str_replace('_timepart', '', $name);
      }
      if ($field = webform_civicrm_aval($fields, $table . '_' . $name)) {
        $component = $element['#webform_component'];
        $element['#attributes']['class'][] = 'civicrm-enabled';
        if (!empty($field['data_type'])) {
          $dt = $element['#civicrm_data_type'] = $field['data_type'];
          // Add CiviCRM JS to link fields
          if ($dt == 'Link' && substr($element['#type'], 0, 4) == 'text') {
            $element['#attributes']['onblur'] = "if (this.value == 'http://') {this.value = '';}";
            $element['#attributes']['onfocus'] = "if (this.value == '') {this.value = 'http://';}";
          }
        }
        if ($name == 'existing') {
          webform_civicrm_fill_contact_value($node, $component, &$element);
        }
        // Provide live options from the Civi DB
        elseif (!empty($component['extra']['civicrm_live_options']) && isset($element['#options'])) {
          $params = array('extra' => webform_civicrm_aval($field, 'extra', array())) + $component;
          $new = webform_civicrm_field_options($params, 'live_options', $node->webform_civicrm['data']);
          $old = $element['#options'];
          $resave = FALSE;
          // If an item doesn't exist, we add it. If it's changed, we update it.
          // But we don't subtract items that have been removed in civi - this prevents
          // breaking the display of old submissions.
          foreach ($new as $k => $v) {
            if (!isset($old[$k]) || $old[$k] != $v) {
              $old[$k] = $v;
              $resave = TRUE;
            }
          }
          if ($resave) {
            $component['extra']['items'] = webform_civicrm_array2str($old);
            webform_component_update($component);
          }
          $element['#options'] = $new;
        }
        // If the user has already entered a value for this field, don't change it
        if (isset($data[$ent][$c][$table][$n][$name])
        && !(isset($component['cid']) && isset($submitted[$component['cid']]))) {
          $val = $data[$ent][$c][$table][$n][$name];
          if (($element['#type'] == 'checkboxes' || !empty($element['#multiple'])) && !is_array($val)) {
            $val = explode($sp, trim($val, $sp));
          }
          if ($element['#type'] != 'checkboxes' && $element['#type'] != 'date'
          && empty($element['#multiple']) && is_array($val)) {
            // If there's more than one value for a non-multi field, pick the most appropriate
            if (!empty($element['#options'])) {
              foreach ($element['#options'] as $k => $v) {
                if (in_array($k, $val)) {
                  $val = $k;
                  break;
                }
              }
            }
            else {
              $val = array_pop($val);
            }
          }
          if ($element['#type'] == 'hidden') {
            $element['#value'] = $val;
          }
          // Set default value
          $element['#default_value'] = $val;
        }
      }
    }
  }
}

/**
 * Webform submission handler
 * Create/update CiviCRM contacts and related data
 * Called by presave, insert and update webform hooks
 * @param $op: Which hook is being called
 */
function webform_civicrm_process_submission($node, &$submission, $op) {
  civicrm_initialize();
  static $data = array();
  static $id = array();
  static $update = array();
  $settings = $node->webform_civicrm;

  // Presave processing - save contact data
  if ($op == 'presave') {
    $config = CRM_Core_Config::singleton();
    require_once 'CRM/Core/BAO/CustomValueTable.php';
    $enabled = webform_civicrm_enabled_fields($node);
    $fields = webform_civicrm_get_fields();
    $data = $settings['data'];
    $sets = webform_civicrm_get_fields('sets');
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    $shared_address = array();

    // Retrieve stored ids
    $id = webform_civicrm_aval(webform_civicrm_storage($node->nid), 'id');

    // Fetch contact ids from "existing contact" fields
    foreach ($enabled as $field_key => $fid) {
      if (substr($field_key, -8) == 'existing') {
        list(, $c, ) = explode('_', $field_key, 3);
        $id['cid'][$c] = 0;
        if (!empty($submission->data[$fid]['value'][0]) && is_numeric($submission->data[$fid]['value'][0])) {
          module_load_include('inc', 'webform_civicrm', 'contact_component');
          $component = $node->webform['components'][$fid];
          $cid = $submission->data[$fid]['value'][0];
          $filters = webform_civicrm_search_filters($node, $component);
          // Verify access to this contact
          if (webform_civicrm_contact_access($component, $filters, $cid) !== FALSE) {
            $id['cid'][$c] = $cid;
          }
        }
      }
    }
    $updating = $id;

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
    foreach ($enabled as $field_key => $fid) {
      if (isset($submission->data[$fid]['value'])) {
        list( , $c, $ent, $n, $table, $name) = explode('_', $field_key, 6);
        if ($name === 'existing') {
          continue;
        }
        // Ignore values from fields hidden by existing contact component
        if (!empty($updating['cid'][$c]) && isset($enabled["civicrm_{$c}_contact_1_contact_existing"])) {
          $component = $node->webform['components'][$enabled["civicrm_{$c}_contact_1_contact_existing"]];
          $type = ($table == 'contact' && strpos($name, 'name')) ? 'name' : $table;
          if (in_array($type, $component['extra']['hide_fields'])) {
            continue;
          }
        }
        $field = $fields[$table . '_' . $name];
        // Ignore values from hidden fields
        if ($field['type'] == 'hidden') {
          continue;
        }
        $val = $submission->data[$fid]['value'];
        if ($name === 'privacy') {
          foreach (array_keys(webform_civicrm_exposed_options($node, $fid)) as $key) {
            $data[$ent][$c][$table][$n][$key] = in_array($key, $val);
          }
          continue;
        }
        if (!empty($field['extra']['multiple'])) {
          // Merge with existing data
          if (!empty($data[$ent][$c][$table][$n][$name]) && is_array($data[$ent][$c][$table][$n][$name])) {
            $val = array_unique(array_merge($val, $data[$ent][$c][$table][$n][$name]));
          }
          // Implode data that will be stored as a string
          if ($table !== 'other' && $name !== 'event_id' && $table !== 'contact' && webform_civicrm_aval($field, 'data_type') != 'ContactReference') {
            $val = $sp . implode($sp, $val) . $sp;
          }
        }
        elseif ($name === 'image_URL') {
          if (empty($val[0]) || !($val = webform_civicrm_filepath($val[0]))) {
            continue;
          }
        }
        elseif ($field['type'] === 'date') {
          $val = empty($val[0]) ? '' : str_replace('-', '', $val[0]);
          // Add time field value
          $time = webform_civicrm_aval($data, "$ent:$c:$table:$n:$name", '');
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
          $date = webform_civicrm_aval($data, "$ent:$c:$table:$n:$name", date('Ymd'));
          $val = $date . str_replace(':', '', $val);
        }
        // Only known contacts are allowed to empty a field
        if (($val !== '' && $val !== NULL) || !empty($updating['cid'][$c])) {
          $data[$ent][$c][$table][$n][$name] = $val;
        }
      }
    }
    // Create/update contacts
    foreach ($data['contact'] as $c => $contact) {
      if (empty($id['cid'][$c])) {
        // Don't create contact if we don't have a name or email
        $ok = FALSE;
        foreach (webform_civicrm_required_contact_fields() as $f) {
          if (!empty($contact[$f['table']][1][$f['name']])) {
            $ok = TRUE;
          }
        }
        if (!$ok) {
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
                  array('contact_id' => $id['cid'][$m], 'sort' => 'is_primary DESC'));
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

      // Current employer must wait for ContactRef ids to be filled
      unset($params['employer_id']);

      $params['contact_type'] = ucfirst($params['contact_type']);

      // Create new contact
      if (empty($id['cid'][$c])) {
        unset($params['contact_id']);
        $params['source'] = $settings['new_contact_source'];
        $result = webform_civicrm_api('contact', 'create', $params);
        if (!empty($result['id'])) {
          $id['cid'][$c] = $result['id'];
        }
        else {
          $id['cid'][$c] = 0;
        }
      }
      // Update existing contact
      else {
        // Fetch data from existing multivalued fields
        $fetch = $multi = array();
        foreach ($fields as $fid => $field) {
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
          $existing = webform_civicrm_api('contact', 'get', array('id' => $id['cid'][$c]) + $fetch);
          $existing = webform_civicrm_aval($existing, 'values:' . $id['cid'][$c], array());
          foreach ($multi as $name) {
            $exist = drupal_map_assoc(webform_civicrm_aval($existing, $name, array()));
            // Only known contacts are allowed to empty a field
            if (!empty($updating['cid'][$c])) {
              $fid = webform_civicrm_aval($enabled, "civicrm_{$c}_contact_1_contact_$name");
              foreach (webform_civicrm_exposed_options($node, $fid) as $k => $v) {
                unset($exist[$k]);
              }
            }
            $params[$name] = array_unique(array_merge($params[$name], $exist));
          }
        }
        $params['contact_id'] = $id['cid'][$c];
        $result = webform_civicrm_api('contact', 'create', $params);
      }
    }
    // Fill ContactRef fields with contact IDs
    foreach (array('contact', 'participant', 'activity', 'case') as $ent) {
      foreach (webform_civicrm_aval($data, $ent, array()) as $c => $v1) {
        if (is_array($v1) && is_numeric($c)) {
          foreach ($v1 as $table => $v2) {
            if (is_array($v2)) {
              foreach ($v2 as $n => $v3) {
                if (is_array($v3) && is_numeric($n)) {
                  foreach ($v3 as $name => $val) {
                    if ($val && webform_civicrm_aval($fields, "{$table}_$name:data_type") === 'ContactReference') {
                      if (is_array($val)) {
                        $data[$ent][$c][$table][$n][$name] = array();
                        foreach ($val as $k => $v) {
                          if (is_numeric($v) && !empty($id['cid'][$v])) {
                            $data[$ent][$c][$table][$n][$name][] = $id['cid'][$v];
                          }
                        }
                      }
                      else {
                        unset($data[$ent][$c][$table][$n][$name]);
                        if (!empty($id['cid'][$val])) {
                          $data[$ent][$c][$table][$n][$name] = $id['cid'][$val];
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    // Create/update other data associated with contacts
    foreach ($data['contact'] as $c => $contact) {
      if (!($cid = $id['cid'][$c])) {
        continue;
      }
      // Save current employer
      if (!empty($contact['contact'][1]['employer_id'])) {
        webform_civicrm_api('contact', 'create', array(
          'contact_id' => $cid,
          'contact_type' => ucfirst($contact['contact'][1]['contact_type']),
          'employer_id' => $contact['contact'][1]['employer_id'],
        ));
      }
      // Save custom data
      webform_civicrm_save_custom($contact, $cid);

      // Fill values for hidden ID & CS fields
      $fid = 'civicrm_' . $c . '_contact_1_contact_';
      if (!empty($enabled[$fid . 'contact_id'])) {
        $submission->data[$enabled[$fid . 'contact_id']]['value'] = array($cid);
      }
      if (!empty($enabled[$fid . 'external_identifier']) && !empty($updating['cid'][$c])) {
        $exid = webform_civicrm_api('contact', 'get', array('contact_id' => $cid, 'return.external_identifier' => 1));
        $submission->data[$enabled[$fid . 'external_identifier']]['value'] = array(webform_civicrm_aval($exid, "values:$cid:external_identifier"));
      }
      if (!empty($enabled[$fid . 'cs'])) {
        if (empty($params['cs'])) {
          $life = 'inf';
        }
        else {
          $life = intval(24 * $params['cs']);
        }
        require_once 'CRM/Contact/BAO/Contact/Utils.php';
        $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($cid, NULL, $life);
        $submission->data[$enabled[$fid . 'cs']]['value'] = array($cs);
      }

      // Save location data
      foreach (array('address', 'email', 'phone', 'website') as $location) {
        if (!empty($contact[$location])) {
          $existing = array();
          $params = array('contact_id' => $cid);
          if ($location != 'website') {
            $params['options'] = array('sort' => 'is_primary DESC');
          }
          $result = webform_civicrm_api($location, 'get', $params);
          if (!empty($result['values'])) {
            // start array index at 1
            $existing = array_merge(array(array()), $result['values']);
          }
          foreach ($contact[$location] as $i => $params) {
            // Translate state/prov abbr to id
            if (!empty($params['state_province_id'])) {
              if (!($params['state_province_id'] = webform_civicrm_state_abbr($params['state_province_id'], 'id', webform_civicrm_aval($params, 'country_id', $config->defaultContactCountry)))) {
                $params['state_province_id'] = '';
              }
            }
            // Check if anything was changed, else skip the update
            if (!empty($existing[$i])) {
              $same = TRUE;
              foreach ($params as $param => $val) {
                if ($val != (string) webform_civicrm_aval($existing[$i], $param, '')) {
                  $same = FALSE;
                }
              }
              if ($same) {
                continue;
              }
            }
            $empty = FALSE;
            if ($location == 'address') {
              // Check if nothing was entered for address
              $empty = (empty($params['street_address'])
                && empty($params['city'])
                && empty($params['state_province_id'])
                && empty($params['country_id'])
                && empty($params['postal_code'])
                && empty($params['master_id'])
              );
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
              // Reset calculated values when updating an address
              $params['master_id'] = $params['geo_code_1'] = $params['geo_code_2'] = 'null';
            }
            // Check if nothing was entered for website
            elseif ($location == 'website') {
              $empty = empty($params['url']);
            }
            // Check if nothing was entered for email or phone
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
            if ($location != 'website') {
              if (empty($params['location_type_id'])) {
                $params['location_type_id'] = webform_civicrm_aval($existing, $i . ':location_type_id', 1);
              }
              $params['is_primary'] = $i == 1 ? 1 : 0;
            }
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
      // Process relationships
      if (!empty($contact['relationship'])) {
        foreach ($contact['relationship'] as $i => $params) {
          if (!empty($params['relationship_type_id']) && !empty($id['cid'][$i])) {
            list($type, $a_b) = explode('_', $params['relationship_type_id']);
            $a_b = $a_b == 'r' ? 'a' : $a_b;
            $b_a = $a_b == 'a' ? 'b' : 'a';
            $params['contact_id_' . $a_b] = $cid;
            $params['contact_id_' . $b_a] = $id['cid'][$i];
            $params['relationship_type_id'] = $type;
            if ($perm = webform_civicrm_aval($params, 'relationship_permission')) {
              $params['is_permission_a_b'] = $params['is_permission_b_a'] = $perm == 3 ? 1 : 0;
              if ($perm == 1 || $perm == 2) {
                $params['is_permission_' . ($perm == 1 ? $a_b . '_' . $b_a : $b_a . '_' . $a_b)] = 1;
              }
            }
            unset($params['relationship_permission']);
            webform_civicrm_api('relationship', 'create', $params);
          }
        }
      }
      // Process groups & tags
      foreach ($fields as $fid => $field) {
        list($set, $type) = explode('_', $fid, 2);
        if ($set == 'other') {
          $field_name = 'civicrm_' . $c . '_contact_1_' . $fid;
          if (!empty($contact['other'][1][$type]) || isset($enabled[$field_name])) {
            $fid = webform_civicrm_aval($enabled, $field_name, 0);
            $add = webform_civicrm_aval($contact, "other:1:$type", array());
            $remove = empty($updating['cid'][$c]) ? array() : webform_civicrm_exposed_options($node, $fid, $add);
            webform_civicrm_add_remove($field['table'], 'contact', $node, $cid, $add, $remove);
          }
        }
      }
      // Process event participation
      if (in_array('CiviEvent', $config->enableComponents, TRUE) && !empty($data['participant_reg_type'])) {
        $n = $data['participant_reg_type'] == 'separate' ? $c : 1;
        if ($p = webform_civicrm_aval($data, "participant:$n:participant")) {
          // Fetch existing participant records
          $existing = array();
          $dao = &CRM_CORE_DAO::executeQuery("SELECT id, event_id FROM civicrm_participant WHERE contact_id = $cid AND is_test = 0");
          while ($dao->fetch()) {
            $existing[$dao->event_id] = $dao->id;
          }
          foreach ($p as $e => $params) {
            $remove = array();
            if ($fid = webform_civicrm_aval($enabled, 'civicrm_' . $c . '_participant_' . $e . '_participant_event_id')) {
              foreach (webform_civicrm_exposed_options($node, $fid) as $eid => $title) {
                list($eid) = explode('-', $eid);
                if (isset($existing[$eid])) {
                  $remove[$eid] = $title;
                }
              }
            }
            if (!empty($params['event_id'])) {
              $params['contact_id'] = $cid;
              if (empty($params['campaign_id']) || !in_array('CiviCampaign', $config->enableComponents, TRUE)) {
                unset($params['campaign_id']);
              }
              // Reformat custom data from nested arrays
              $custom = array();
              foreach ($data['participant'][$n] as $key => $vals) {
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
                    $params['source'] = check_plain($node->title);
                    if ($c > 1 && !empty($registered_by_id[$e][$i])) {
                      $params['registered_by_id'] = $registered_by_id[$e][$i];
                    }
                  }
                  $result = webform_civicrm_api('participant', 'create', $params);
                  // When registering contact 1, store id to apply to other contacts
                  if ($c == 1) {
                    $registered_by_id[$e][$i] = $result['id'];
                  }
                  if ($custom) {
                    webform_civicrm_save_custom($custom, $result['id'], 'Participant');
                  }
                }
              }
            }
            foreach ($remove as $eid => $title) {
              webform_civicrm_api('participant', 'create', array('status_id' => 4, 'id' => $existing[$eid]));
              drupal_set_message(t('Registration canceled for !event', array('!event' => $title)));
            }
          }
        }
      }
    }
    // Process shared addresses. We do this last after all contacts and addresses exist.
    foreach ($shared_address as $cid => $shared) {
      foreach ($shared as $i => $addr) {
        if (!empty($id['cid'][$addr['mc']])) {
          $masters = webform_civicrm_api('address', 'get', array('contact_id' => $id['cid'][$addr['mc']], 'options' =>array('sort' => 'is_primary DESC')));
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

    // Save case.
    if (!empty($data['case'][1]) && !empty($data['case'][1]['case'][1]['client_id'])) {
      if (empty($id['case'][1])) {
        $data['case'][1]['case'][1]['subject'] = check_plain($node->title);
      }
      else {
        $data['case'][1]['case'][1]['id'] = $id['case'][1];
        unset($data['case'][1]['case'][1]['creator_id'], $data['case'][1]['case'][1]['case_type_id']);
      }
      $result = webform_civicrm_api('case', 'create', $data['case'][1]['case'][1]);
      if (!empty($result['values']['id'])) {
        webform_civicrm_save_custom($data['case'][1], $result['values']['id'], 'Case');
        $id['case'][1] = $result['values']['id'];
        if (empty($id['act'][1])) {
          $id['act'][1] = webform_civicrm_activity_find(array(
            'activity_type_id' => $data['activity'][1]['activity'][1]['activity_type_id'],
            'status_id' => $data['activity'][1]['existing_activity_status'],
            'case_id' => $id['case'][1],
          ));
        }
      }
    }
    if (!empty($data['activity'][1])) {
      $params = $data['activity'][1]['activity'][1];
      if ($cid = webform_civicrm_user_cid()) {
        $params['source_contact_id'] = $cid;
      }
      else {
        foreach ($id['cid'] as $cid) {
          if ($cid) {
            $params['source_contact_id'] = $cid;
            break;
          }
        }
      }
      if (empty($params['assignee_contact_id'])) {
        unset($params['assignee_contact_id']);
      }
      // Format details as html
      $params['details'] = nl2br(webform_civicrm_aval($params, 'details', ''));
      if (empty($params['subject'])) {
        $params['subject'] = $settings['data']['activity'][1]['activity'][1]['subject'];
      }
      if (!empty($data['activity'][1]['details']['entire_result'])) {
        module_load_include('inc', 'webform', 'includes/webform.submissions');
        $params['details'] .= webform_submission_render($node, $submission, NULL, 'html');
      }
      if (!empty($data['activity'][1]['details']['view_link'])) {
        $params['details'] .= '<p>' . l(t('View Webform Submission'), 'node/' . $node->nid . '/submission/' . $submission->sid, array('absolute' => TRUE, 'alias' => TRUE)) . '</p>';
      }
      if (!empty($data['activity'][1]['details']['edit_link'])) {
        $params['details'] .= '<p>' . l(t('Edit Submission'), 'node/' . $node->nid . '/submission/' . $submission->sid . '/edit',  array('absolute' => TRUE, 'alias' => TRUE)) . '</p>';
      }
      if (!empty($id['act'][1])) {
        $params['id'] = $id['act'][1];
      }
      elseif (!empty($id['case'][1])) {
        $params['case_id'] = $id['case'][1];
        $params['medium_id'] = $data['case'][1]['case'][1]['medium_id'];
      }
      if (empty($params['campaign_id']) || !in_array('CiviCampaign', $config->enableComponents, TRUE)) {
        unset($params['campaign_id']);
      }
      $result = webform_civicrm_api('activity', 'create', $params);

      if (!empty($result['id'])) {
        $id['act'][1] = $result['id'];
        webform_civicrm_save_custom($data['activity'][1], $id['act'][1], 'Activity');
      }
      if (!empty($id['act'][1]) && !empty($params['assignee_contact_id'])) {
        require_once 'CRM/Core/BAO/Setting.php';
        if (CRM_Core_BAO_Setting::getItem(CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME, 'activity_assignee_notification')) {
          // Send email to assignees. TODO: Move to CiviCRM API?
          $assignee = webform_civicrm_api('contact', 'get', array('id' => $params['assignee_contact_id']));
          $assignee = webform_civicrm_aval($assignee, 'values:' . $params['assignee_contact_id']);
          if (!empty($assignee['email'])) {
            $mail = array($assignee['email'] => $assignee);
            // Include attachments while sendig a copy of activity.
            require_once 'CRM/Core/BAO/File.php';
            $attachments = &CRM_Core_BAO_File::getEntityFile('civicrm_activity', $id['act'][1]);
            require_once 'CRM/Case/BAO/Case.php';
            $result = CRM_Case_BAO_Case::sendActivityCopy(NULL, $id['act'][1], $mail, $attachments, NULL);
          }
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
 * Handle adding/removing multivalued data for a contact/activity/etc.
 * Currently used only for groups and tags, but written with expansion in mind.
 */
function webform_civicrm_add_remove($data_type, $entity_type, $node, $id, $add, $remove = array()) {
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
    $fetch = webform_civicrm_api($api, 'get', $params);
    foreach (webform_civicrm_aval($fetch, 'values', array()) as $i) {
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
    if ($data_type == 'group' && !empty($node->webform_civicrm['confirm_subscription'])) {
      // Retrieve this contact's primary email address and perform error-checking
      $result = webform_civicrm_api('email', 'get', array('contact_id' => $id, 'options' => array('sort' => 'is_primary DESC')));
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
          $mailing_lists = webform_civicrm_get_options('mailing_lists');
        }
      }
    }
    foreach ($add as $a) {
      $params[$data_type . '_id'] = $mailer_params['group_id'] = $a;
      if ($data_type == 'group' && isset($mailing_lists[$a]) && !empty($email)) {
        $result = webform_civicrm_api('mailing_group', 'event_subscribe', $mailer_params);
        if (empty($result['is_error'])) {
          $confirmations_sent[] = check_plain($mailing_lists[$a]);
        }
        else {
          webform_civicrm_api($api, 'create', $params);
        }
      }
      else {
        webform_civicrm_api($api, 'create', $params);
      }
    }
    if ($confirmations_sent) {
      drupal_set_message(t('A message has been sent to %email to confirm subscription to !group.', array('%email' => $email, '!group' => '<em>' . implode('</em> ' . t('and') . ' <em>', $confirmations_sent) . '</em>')));
    }
  }
  // Remove data from entity
  foreach ($remove as $a => $name) {
    $params[$data_type . '_id'] = $a;
    webform_civicrm_api($api, 'delete', $params);
  }
  if (!empty($remove) && $data_type == 'group') {
    $display_name = webform_civicrm_api('contact', 'get', array('contact_id' => $id, 'return.display_name' => 1));
    $display_name = webform_civicrm_aval($display_name, "values:$id:display_name", t('Contact'));
    drupal_set_message(t('%contact has been removed from !group.', array('%contact' => $display_name, '!group' => '<em>' . implode('</em> ' . t('and') . ' <em>', $remove) . '</em>')));
  }
}

/**
 * For a given field, determine which options exist on the webform.
 */
function webform_civicrm_exposed_options($node, $fid, $exclude = array()) {
  if ($fid) {
    $field = $node->webform['components'][$fid];
    if ($field['type'] == 'select') {
      // Fetch static options
      if (empty($field['extra']['civicrm_live_options'])) {
        $exposed = webform_civicrm_str2array($node->webform['components'][$fid]['extra']['items']);
      }
      // Fetch live options
      else {
        $exposed = webform_civicrm_field_options($field, 'live_options', $node->webform_civicrm['data']);
      }
      foreach ($exclude as $i) {
        unset($exposed[$i]);
      }
      return $exposed;
    }
  }
  return array();
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
      && substr(webform_civicrm_aval($element, '#type', ''), 0, 4) === 'text'
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
        // Strings and files don't need any validation
        elseif ($dt !== 'String' && $dt !== 'Memo' && $dt !== 'File'
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
  $contacts = $cids = array();
  // Check how many valid contacts we have
  foreach (array_keys($data['contact']) as $c) {
    // Check if we have a contact_id
    if (!empty($submitted["civicrm_{$c}_contact_1_contact_existing"])) {
      $cids[] = $contacts[$c] = $submitted["civicrm_{$c}_contact_1_contact_existing"];
    }
    // Or else see if enough info was entered to create a contact
    elseif (webform_civicrm_name_field_exists($submitted, $c)) {
      $contacts[$c] = 0;
    }
  }
  $events = array();
  $add = $data['participant_reg_type'] == 'all' ? count($contacts) : 1;
  // Fetch events set in back-end
  foreach ($data['participant'] as $c => $par) {
    if (!empty($par['participant']) && isset($contacts[$c])) {
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
      list(, $c) = webform_civicrm_explode_key($field);
      if (isset($contacts[$c]) || ($c == 1 && $data['participant_reg_type'] == 'all')) {
        $value = is_array($value) ? $value : array($value);
        foreach ($value as $val) {
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
  if ($events && $cids) {
    $dao = &CRM_Core_DAO::executeQuery("SELECT event_id
      FROM civicrm_participant p, civicrm_participant_status_type s
      WHERE s.id = p.status_id AND s.is_counted = 1
      AND event_id IN (" . implode(',', array_keys($events)) . ")
      AND contact_id IN (" . implode(',', $cids) . ")
      AND is_test = 0");
    while ($dao->fetch()) {
      if (isset($events[$dao->event_id])) {
        if ((--$events[$dao->event_id]['count']) === 0) {
          unset($events[$dao->event_id]);
        }
      }
    }
    $dao->free();
  }
  webform_civicrm_event_info($events);
  foreach ($events as $eid => $event) {
    if ($event['ended']) {
      form_set_error($eid, t('Sorry, you can no longer register for %event.', array('%event' => $event['title'])));
    }
    elseif ($event['max_participants'] && $event['count'] > $event['remaining']) {
      if (!empty($event['full'])) {
        form_set_error($eid, '<em>' . $event['title'] . '</em>: ' . $event['full_message']);
      }
      else {
        form_set_error($eid, format_plural($event['remaining'],
          'Sorry, you tried to register !count people for %event but there is only 1 space remaining.',
          'Sorry, you tried to register !count people for %event but there are only @count spaces remaining.',
          array('%event' => $event['title'], '!count' => $event['count'])));
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
  if (!empty($matches[0])) {
    $q = $_GET;
    unset($q['q'], $q['cs'], $q['cid'], $q['cid1']);
    if (empty($_GET['cid']) && empty($_GET['cid1'])) {
      $q['cid1'] = 0;
    }
    foreach ($matches[0] as $pos => $match) {
      $link = l($matches[1][$pos], $_GET['q'], array('query' => $q, 'alias' => TRUE));
      $message = str_replace($match, $link, $message);
    }
  }
  drupal_set_message($message);
}
