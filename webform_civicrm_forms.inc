<?php

/**
 * @file
 * Webform CiviCRM module's form building, altering, and processing functions.
 * The functions in this file are all cross-compatible with D6/Civi3 and D7/Civi4
 * Drupal-version-specific functions belong in webform_civicrm_dx_functions.inc
 */

module_load_include('inc', 'webform_civicrm', 'webform_civicrm_utils');

/**
 * Form to configure CiviCRM options for a Webform
 * Called indirectly from hook_menu() for D7-D6 compatibility
 */
function webform_civicrm_configure_form_builder($form, &$form_state, $node) {
  $form = array();
  $form_state['storage']['node'] = $node;

  // Display confirmtion message before deleting fields
  if (!empty($form_state['storage']['msg'])) {
    $form['#prefix'] = $form_state['storage']['msg'];
    $form['cancel'] = $form['no_delete'] = $form['confirm'] = array('#type' => 'submit');
    $form['confirm']['#value'] = t('Remove Fields and Save Settings');
    $form['no_delete']['#value'] = t('Leave Fields and Save Settings');
    $form['cancel']['#value'] = t('Cancel (go back)');
    return $form;
  }
  webform_civicrm_add_js('webform_civicrm_forms.js');
  drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_style.css');
  civicrm_initialize();
  $config = CRM_Core_Config::singleton();

  $fields = webform_civicrm_get_fields();
  $sets = webform_civicrm_get_fields('sets');

  // Initialize form on first view
  if (empty($form_state['values']))  {
    // For an existing civi-webform, load settings
    if (isset($node->webform_civicrm)) {
      $settings = $node->webform_civicrm;
    }
    // For a new civi-webform, set defaults
    else {
      $settings = array(
        'data' => array(
          'contact' => array(
            1 => array(
              'contact' => array(1 => array(
                  'contact_type' => 'individual',
                  'contact_sub_type' => NULL,
                )),
              'activity_target' => 1,
            ),
          ),
        ),
        'confirm_subscription' => 1,
        'create_fieldsets' => 1,
        'new_contact_source' => check_plain($node->title),
        'contact_matching' => 1,
      );
    }
  }
  // On rebuilding the form
  else {
    $settings = webform_civicrm_aval($form_state['storage'], 'vals', $form_state['values']);
    webform_civicrm_process_form_settings($settings);
    unset($form_state['storage']['vals']);
  }
  // Merge in existing fields
  $existing = array_keys(webform_civicrm_enabled_fields($node));
  $settings += array_fill_keys($existing, 'create_civicrm_webform_element');

  $tokens = '<strong>' . t('Contact 1 Tokens:') . '</strong> [' . implode('], [', webform_civicrm_get_fields('tokens')) . '].';
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  $data = $settings['data'];
  $contacts = count($data['contact']);
  $js = 'var webform_civicrm_relationship_data = ' . json_encode(webform_civicrm_get_relationship_types()) . ';';
  webform_civicrm_add_js($js, 'inline');

  // Sort fields by set
  foreach ($fields as $fid => $field) {
    list($group) = explode('_', $fid, 2);
    $sets[$group]['fields'][$fid] = $field;
  }
  $form['nid'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable CiviCRM Processing'),
    '#default_value' => !empty($settings['nid']),
    '#return_value' => $node->nid,
    '#description' => '<a id="web-civi-help" class="module-link module-link-help" href="/admin/help/webform_civicrm" target="_blank">' . t('Webform CiviCRM Help') . '</a>
    <ul><li>' . t('Contacts will be created or updated when users submit this webform.') . '</li></ul>',
  );
  $form['number_of_contacts'] = array(
    '#type' => 'select',
    '#title' => t('Number of Contacts'),
    '#default_value' => $contacts,
    '#options' => drupal_map_assoc(range(1, 30)),
  );
  $form['change_form_settings'] = array(
    '#type' => 'button',
    '#value' => t('Change Form Settings'),
    '#prefix' => '<div id="no-js-button-wrapper" class="messages warning">',
    '#suffix' => '<div>' . t('You have Javascript disabled, so you will need to click this button after changing any option to see the result.') . '</div></div>',
  );
  $form['webform_civicrm'] = array('#type' => 'vertical_tabs');

  // Contact settings
  foreach ($data['contact'] as $n => $c) {
    $form['contact_' . $n] = array(
      '#type' => 'fieldset',
      '#title' => t('Contact !num', array('!num' => $n)),
      '#description' => $n > 1 ? NULL : t('Primary contact. Usually assumed to be the person filling out the form.'),
      '#group' => 'webform_civicrm',
    );
    $form['contact_' . $n][$n . '_contact_type'] = array(
      '#type' => 'select',
      '#title' => t('Contact Type'),
      '#default_value' => $c['contact'][1]['contact_type'],
      '#options' => $contact_types,
    );
    webform_civicrm_ajax_form_item($form, 'contact_' . $n, $n . '_contact_type', 'contact_subtype_wrapper');

    // Contact sub-type form item gets some special treatment
    $fid = 'civicrm_' . $n . '_contact_1_contact_contact_sub_type';
    $form['contact_' . $n]['contact_subtype_wrapper'][$fid] = webform_civicrm_configure_form_item($fid, $fields['contact_contact_sub_type'], $settings, $contact_types[$c['contact'][1]['contact_type']]);
    $form['contact_' . $n]['contact_subtype_wrapper'][$fid]['#title'] .= ' ' . $contact_types[$c['contact'][1]['contact_type']];
    $form['contact_' . $n]['contact_subtype_wrapper'][$fid]['#description'] = t('You may optionally select a more specific type for this contact. Choose "user select" to create a form element with these options.');
    webform_civicrm_ajax_form_item($form, 'contact_' . $n . ':contact_subtype_wrapper', $fid, 'contact_custom_wrapper');

    if (empty($sub_types[$c['contact'][1]['contact_type']])) {
      $form['contact_' . $n]['contact_subtype_wrapper']['civicrm_' . $n . '_contact_1_contact_contact_sub_type']['#prefix'] = '<div class="hidden">';
      $form['contact_' . $n]['contact_subtype_wrapper']['civicrm_' . $n . '_contact_1_contact_contact_sub_type']['#suffix'] = '</div>';
      array_shift($form['contact_' . $n]['contact_subtype_wrapper'][$fid]['#options']);
    }
    foreach ($sets as $sid => $set) {
      $hide = FALSE;
      if ($set['entity_type'] != 'contact') {
        continue;
      }
      if ($sid == 'relationship') {
        if (!($set['max_instances'] = $n - 1)) {
          continue;
        }
      }
      if (!empty($set['contact_type'])) {
        if ($set['contact_type'] != $c['contact'][1]['contact_type']) {
          $hide = TRUE;
        }
      }
      if (!empty($set['sub_types'])) {
        if (!in_array($c['contact'][1]['contact_sub_type'], $set['sub_types'])) {
          $hide = TRUE;
        }
        $pos = &$form['contact_' . $n]['contact_subtype_wrapper']['contact_custom_wrapper'];
        $path = 'contact_' . $n . ':contact_subtype_wrapper:contact_custom_wrapper';
      }
      elseif (!empty($set['contact_type']) || $sid == 'contact') {
        $pos = &$form['contact_' . $n]['contact_subtype_wrapper'];
        $path = 'contact_' . $n . ':contact_subtype_wrapper';
      }
      else {
        $pos = &$form['contact_' . $n];
        $path = 'contact_' . $n;
      }
      if (!empty($set['max_instances'])) {
        if (!isset($c['number_of_' . $sid])) {
          $c['number_of_' . $sid] = 0;
        }
        $pos['contact_' . $n . '_number_of_' . $sid] = array(
          '#type' => 'select',
          '#title' => t('Number of %type Fields', array('%type' => $sid == 'relationship' ? t('Relationship') : $set['label'])),
          '#default_value' => $c['number_of_' . $sid],
          '#options' => range(0, $set['max_instances']),
        );
        if ($hide) {
          $pos['contact_' . $n . '_number_of_' . $sid]['#prefix'] = '<div class="hidden">';
          $pos['contact_' . $n . '_number_of_' . $sid]['#suffix'] = '</div>';
        }
        webform_civicrm_ajax_form_item($form, $path, 'contact_' . $n . '_number_of_' . $sid, $n . $sid . '_wrapper');
      }
      else {
        $c['number_of_' . $sid] = 1;
      }
      for ($i = 1; $i <= $c['number_of_' . $sid] && !$hide; ++$i) {
        $fsid = 'civicrm_' . $n . $sid . $i . '_fieldset';
        $fieldset = array(
          '#type' => 'fieldset',
          '#title' => $set['label'],
          '#attributes' => array('id' => $fsid, 'class' => webform_civicrm_fapi_class('web-civi-checkbox-set')),
          'js_select' => webform_civicrm_js_select($fsid),
        );
        if ((isset($set['max_instances']) && $set['max_instances'] > 1) || $sid == 'relationship') {
          $fieldset['#title'] .= ' ' . $i;
          if (in_array($sid, array('email', 'address', 'phone', 'website')) && $i == 1) {
            $fieldset['#title'] .= ' ' . t('(primary)');
          }
        }
        foreach ($set['fields'] as $fid => $field) {
          if ($fid == 'contact_contact_sub_type' ||
          ($fid == 'address_master_id' && $contacts == 1) ||
          (isset($field['contact_type']) && $field['contact_type'] != $c['contact'][1]['contact_type'])) {
            continue;
          }
          $fid = 'civicrm_' . $n . '_contact_' . $i . '_' . $fid;
          $fieldset[$fid] = webform_civicrm_configure_form_item($fid, $field, $settings);
        }
        if (isset($set['max_instances'])) {
          $pos[$n . $sid . '_wrapper'][$n . $sid . $i . '_fieldset'] = $fieldset;
        }
        else {
          $pos[$n . $sid . $i . '_fieldset'] = $fieldset;
        }
      }
    }
  }

  // Configure messages
  $form['prefix'] = array(
    '#type' => 'fieldset',
    '#title' => t('Introduction Text'),
    '#description' => t('This text will appear at the top of the form. You may configure separate messages for known contacts (logged in users, or users following a hashed link from civimail) and unknown (anonymous) users.'),
    '#group' => 'webform_civicrm',
  );
  $form['prefix']['prefix_known'] = array(
    '#type' => 'textarea',
    '#title' => t('Introduction text for known contacts'),
    '#default_value' => webform_civicrm_aval($settings, 'prefix_known'),
    '#description' => $tokens,
  );
  $form['prefix']['prefix_unknown'] = array(
    '#type' => 'textarea',
    '#title' => t('Introduction text for unknown contacts'),
    '#default_value' => webform_civicrm_aval($settings, 'prefix_unknown'),
    '#description' => t('No tokens available for unknown contacts.'),
  );
  $form['st_message'] = array(
    '#type' => 'fieldset',
    '#title' => t('Contact 1 Logout Message'),
    '#description' => t('Prompt for users who are logged in as, or following a hashed link for, someone else.'),
    '#group' => 'webform_civicrm',
  );
  $form['st_message']['toggle_message'] = array(
    '#type' => 'checkbox',
    '#title' => t('Display message to known contacts?'),
    '#default_value' => !empty($settings['message']),
  );
  $form['st_message']['message'] = array(
    '#type' => 'textfield',
    '#title' => t('Text (displayed as a status message)'),
    '#default_value' => webform_civicrm_aval($settings, 'message', t("You are viewing this form as [display name]. Please {click here if that's not you}.")),
    '#size' => 100,
    '#maxlength' => 255,
    '#description' => t('Enclose your "not you" link text in curly brackets {like this}.') . '<p>' . $tokens . '</p>',
  );

  // Case and activity settings
  $form['act'] = array(
    '#type' => 'fieldset',
    '#title' => t('Create Activity'),
    '#description' => t('Create an activity for contacts when this form is submitted?'),
    '#group' => 'webform_civicrm',
  );
  if (in_array('CiviCase', $config->enableComponents)
  && $case_types = webform_civicrm_get_options('case_type')) {
    $form['act']['case_type_id'] = array(
      '#type' => 'select',
      '#title' => t('CiviCase Type'),
      '#description' => t('Is this activity part of a case?'),
      '#options' => array(t('- no case -')) + $case_types,
    );
    webform_civicrm_ajax_form_item($form, 'act', 'case_type_id', 'case');
  }
  $param = NULL;
  if (!empty($data['case'][1]['case'][1]['case_type_id'])) {
    $form['act']['case_type_id']['#default_value'] = $data['case'][1]['case'][1]['case_type_id'];
    $param = '7';
    $form['act']['case']['case_fieldset'] = array(
      '#type' => 'fieldset',
      '#title' => t('Case Settings'),
    );
    $form['act']['case']['case_fieldset']['case_status_id'] = array(
      '#type' => 'select',
      '#title' => t('Case Status'),
      '#description' => t('If a "@type" case with this status already exists for this contact, the activity will be added to that case. Otherwise a new case will be opened with this status.', array('@type' => $case_types[$data['case'][1]['case'][1]['case_type_id']])),
      '#options' => webform_civicrm_get_options('case_status'),
      '#default_value' => webform_civicrm_aval($data, 'case:1:case:1:status_id'),
    );
    $form['act']['case']['case_fieldset']['case_medium_id'] = array(
      '#type' => 'select',
      '#title' => t('Medium'),
      '#description' => t('Medium for activities added to cases from this webform.'),
      '#options' => webform_civicrm_get_options('encounter_medium'),
      '#default_value' => webform_civicrm_aval($data, 'case:1:case:1:medium_id'),
    );
    $form['act']['case']['case_fieldset']['case_subject'] = array(
      '#type' => 'textfield',
      '#title' => t('Case Subject'),
      '#description' => t('Optional subject for "open case" activity for new cases.'),
      '#default_value' => webform_civicrm_aval($data, 'case:1:case:1:subject', check_plain($node->title)),
    );
    $form['act']['case']['case_fieldset']['case_creator_id'] = array(
      '#type' => 'textfield',
      '#size' => 5,
      '#required' => TRUE,
      '#title' => t('Case Manager ID'),
      '#description' => t('Contact ID of "case manager" for newly created cases.'),
      '#default_value' => webform_civicrm_aval($data, 'case:1:case:1:creator_id', webform_civicrm_user_cid()),
    );
  }
  $act_types = webform_civicrm_get_options('activity_type', $param);
  $form['act']['case']['activity_type_id'] = array(
    '#type' => 'select',
    '#title' => t('Activity Type'),
    '#options' => array(t('- no activity -')) + $act_types,
  );
  webform_civicrm_ajax_form_item($form, 'act:case', 'activity_type_id', 'activity_fields');
  if (isset($data['activity'][1]['activity'][1]['activity_type_id'])
    && isset($act_types[$data['activity'][1]['activity'][1]['activity_type_id']])) {
    $form['act']['case']['activity_type_id']['#default_value'] = $settings['activity_type_id'] = $data['activity'][1]['activity'][1]['activity_type_id'];
    $form['act']['case']['activity_fields']['activity_targets'] = array(
      '#title' => t('Activity Participants'),
      '#type' => 'checkboxes',
      '#options' => array(),
      '#default_value' => array(),
      '#required' => TRUE,
    );
    $form['act']['case']['activity_fields']['activity_assignee_group'] = array(
      '#title' => t('Choose Assignee From'),
      '#type' => 'select',
      '#options' => array(t('- Webform Contacts -')) + webform_civicrm_get_options('groups'),
      '#default_value' => webform_civicrm_aval($data, 'activity:1:assignee_group'),
    );
    webform_civicrm_ajax_form_item($form, 'act:case:activity_fields', 'activity_assignee_group', 'assignee');
    // Activity assignee
    $fid = 'civicrm_1_activity_1_activity_assignee_contact_id';
    $form['act']['case']['activity_fields']['assignee'][$fid] = webform_civicrm_configure_form_item($fid, $fields['activity_assignee_contact_id'], $settings);
    if (!empty($config->activityAssigneeNotification)) {
      $form['act']['case']['activity_fields']['assignee'][$fid]['#description'] = t('A copy of this activity will be emailed to the assignee.');
    }
    $form['act']['case']['activity_fields']['existing_activity_status'] = array(
      '#type' => 'select',
      '#title' => t('Update Existing Activity'),
      '#description' => t('If a "@type" activity with this status already exists for contact 1, it will be updated. Otherwise a new one will be created.', array('@type' => $act_types[$settings['activity_type_id']])),
      '#options' => array(t('- always create new activity -')) + webform_civicrm_get_options('activity_status'),
      '#default_value' => webform_civicrm_aval($data, 'activity:1:existing_activity_status'),
    );
    $form['act']['case']['activity_fields']['activity_subject'] = array(
      '#type' => 'textfield',
      '#title' => t('Default Activity Subject'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#description' => t('You can override this default by allowing users to enter a subject (enable "Activity Subject" field below)'),
      '#default_value' => webform_civicrm_aval($data, 'activity:1:activity:1:subject', check_plain($node->title)),
    );
    $form['act']['case']['activity_fields']['activity_link'] = array(
      '#type' => 'checkbox',
      '#title' => t('Include link to webform submission in activity details'),
      '#default_value' => !(empty($data['activity'][1]['add_link']) && isset($data['activity'][1]['add_link'])),
    );
    foreach ($data['contact'] as $n => $c) {
      $form['act']['case']['activity_fields']['activity_targets']['#options'][$n] = t('Contact !num', array('!num' => $n));
      if (!isset($settings['activity_subject']) || !empty($c['activity_target'])) {
        $form['act']['case']['activity_fields']['activity_targets']['#default_value'][] = $n;
      }
    }
    foreach ($sets as $sid => $set) {
      $sid .= '_fieldset';
      if ($set['entity_type'] == 'activity') {
        if (!empty($set['sub_types']) && !in_array($settings['activity_type_id'], $set['sub_types'])) {
          continue;
        }
        $form['act']['case']['activity_fields'][$sid] = array(
          '#type' => 'fieldset',
          '#title' => $set['label'],
          '#attributes' => array('id' => $sid, 'class' => webform_civicrm_fapi_class('web-civi-checkbox-set')),
          'js_select' => webform_civicrm_js_select($sid),
        );
        foreach ($set['fields'] as $fid => $field) {
          if (empty($field['do_not_cache'])) {
            $fid = 'civicrm_1_activity_1_' . $fid;
            $form['act']['case']['activity_fields'][$sid][$fid] = webform_civicrm_configure_form_item($fid, $field, $settings);
          }
        }
      }
    }
  }

  // Event participant settings
  if (in_array('CiviEvent', $config->enableComponents)) {
    $form['event'] = array(
      '#type' => 'fieldset',
      '#title' => t('Register Event Participants'),
      '#group' => 'webform_civicrm',
    );
    $reg_type = webform_civicrm_aval($data, 'participant_reg_type');
    $form['event']['participant_reg_type'] = array(
      '#type' => 'select',
      '#title' => t('Registration Method'),
      '#default_value' => $reg_type,
      '#options' => array(
        t('- no event registration -'),
        'all' => t('Register all contacts for the same event(s)'),
        'separate' => t('Register each contact separately'),
      ),
    );
    $form['event']['event_type'] = array(
      '#type' => 'select',
      '#title' => t('Show Events of Type'),
      '#options' => array('any' => t('- Any Type -')) + webform_civicrm_get_options('event_type'),
      '#default_value' => webform_civicrm_aval($data, 'reg_options:event_type', 'any'),
      '#prefix' => '<div id="event-reg-options-wrapper"><div class="web-civi-checkbox-set">',
      '#parents' => array('reg_options', 'event_type'),
      '#tree' => TRUE,
    );
    $form['event']['show_past_events'] = array(
      '#type' => 'checkbox',
      '#title' => t('Show Past Events'),
      '#default_value' => (bool) webform_civicrm_aval($data, 'reg_options:show_past_events'),
      '#suffix' => '</div>',
      '#parents' => array('reg_options', 'show_past_events'),
      '#tree' => TRUE,
    );
    $form['event']['reg_options'] = array(
      '#prefix' => '<div class="clear-block"></div>',
      '#suffix' => '</div>',
      '#type' => 'fieldset',
      '#title' => t('Registration Options'),
      '#collapsible' => TRUE,
      '#tree' => TRUE,
    );
    $form['event']['reg_options']['show_remaining'] = array(
      '#type' => 'select',
      '#title' => t('Show Remaining Space in Events'),
      '#default_value' => webform_civicrm_aval($data, 'reg_options:show_remaining', 0),
      '#description' => t('Display a message at the top of the form for each event with a registration limit or past end date.'),
      '#options' => array(
        t('Never'),
        'always' => t('Always'),
        '0_full' => t('When full - 0 spaces left'),
      ),
    );
    foreach(array(5, 10, 20, 50, 100, 200, 500, 1000) as $num) {
      $form['event']['reg_options']['show_remaining']['#options'][$num] = t('When under !num spaces left', array('!num' => $num));
    }
    $form['event']['reg_options']['validate'] = array(
      '#type' => 'checkbox',
      '#title' => t('Prevent Registration for Past/Full Events'),
      '#default_value' => (bool) webform_civicrm_aval($data, 'reg_options:validate'),
      '#description' => t('Will not allow the form to be submitted if user registers for an event that is ended or full.'),
    );
    $form['event']['reg_options']['block_form'] = array(
      '#type' => 'checkbox',
      '#title' => t('Block Form Access when Event(s) are Full/Ended'),
      '#default_value' => (bool) webform_civicrm_aval($data, 'reg_options:block_form'),
      '#description' => t('Hide webform if all the events for the form are full or ended.'),
    );
    webform_civicrm_ajax_form_item($form, 'event', 'participant_reg_type', 'participants');
    webform_civicrm_ajax_form_item($form, 'event', 'event_type', 'participants');
    webform_civicrm_ajax_form_item($form, 'event', 'show_past_events', 'participants');
    for ($n = 1; $n <= $contacts; ++$n) {
      $class = (!$reg_type || ($reg_type == 'all' && $n > 1)) ? 'class="hidden"' : '';
      $form['event']['participants'][$n] = array(
        '#type' => 'fieldset',
        '#title' => $reg_type == 'all' && $n == 1 ? t('Registration') : t('Contact !num', array('!num' => $n)),
        '#prefix' => "<div id=\"participant-fieldset-$n\" $class>",
        '#suffix' => '</div>',
      );
      if (!($num = webform_civicrm_aval($data, "participant:{$n}:number_of_participant"))
      || ($n > 1 && $reg_type == 'all')) {
        $num = 0;
      }
      $form['event']['participants'][$n]['participant_' . $n . '_number_of_participant'] = array(
        '#type' => 'select',
        '#title' => $reg_type == 'all' ? t('Number of Events') : t('Number of Events for Contact !num', array('!num' => $n)),
        '#default_value' => $num,
        '#options' => range(0, $sets['participant']['max_instances']),
      );
      webform_civicrm_ajax_form_item($form, "event:participants:{$n}", 'participant_' . $n . '_number_of_participant', 'div');
      for ($e = 1; $e <= $num; ++$e) {
        $fs = "participant_{$n}_event_{$e}_fieldset";
        $form['event']['participants'][$n]['div'][$fs] = array(
          '#type' => 'fieldset',
          '#title' => t('Event !num', array('!num' => $e)),
          '#attributes' => array('id' => $fs),
        );
        foreach ($sets as $sid => $set) {
          if ($set['entity_type'] == 'participant') {
            $sid = 'civicrm_' . $n . '_participant_' . $e . '_' . $sid . '_fieldset';
            $class = 'web-civi-checkbox-set';
            if (!empty($set['sub_types'])) {
              list($event_id, $event_type) = $participant_event_id != 'create_civicrm_webform_element' ? explode('-', $participant_event_id) : array('', webform_civicrm_aval($data, 'reg_options:event_type', ''));
              switch ($set['extension_of']) {
                case 1:
                  if (!in_array($participant_role_id, $set['sub_types'])) {
                    $class .= ' hidden';
                  }
                  $class .= ' extends-condition roleid-';
                  break;
                case 2:
                  if (!in_array($event_id, $set['sub_types'])) {
                    $class .= ' hidden';
                  }
                  $class .= ' extends-condition eventid-';
                  break;
                case 3:
                  if (!in_array($event_type, $set['sub_types'])) {
                    $class .= ' hidden';
                  }
                  $class .= ' extends-condition eventtype-';
                  break;
              }
              $class .= implode('-', $set['sub_types']);
            }
            $form['event']['participants'][$n]['div'][$fs][$sid] = array(
              '#type' => 'fieldset',
              '#title' => $set['label'],
              '#attributes' => array('id' => $sid, 'class' => webform_civicrm_fapi_class($class)),
              'js_select' => webform_civicrm_js_select($sid),
            );
            foreach ($set['fields'] as $fid => $field) {
              $id = 'civicrm_' . $n . '_participant_' . $e . '_' . $fid;
              $item = webform_civicrm_configure_form_item($id, $field, $settings);
              if ($fid == 'participant_event_id' || $fid == 'participant_role_id') {
                $item['#attributes']['onchange'] = "web_civi_participant_conditional('#$fs');";
                $item['#attributes']['class'] = webform_civicrm_fapi_class($fid);
                $$fid = webform_civicrm_aval($item, '#default_value');
              }
              $form['event']['participants'][$n]['div'][$fs][$sid][$id] = $item;
            }
          }
        }
      }
    }
  }

  // Configure additional options
  $form['options'] = array(
    '#type' => 'fieldset',
    '#title' => t('Additional Options'),
    '#group' => 'webform_civicrm',
    '#description' => '<p>' .
    t('To have this form auto-filled for anonymous users, send the following link from CiviMail:') .
    '<br />' . url('node/' . $node->nid, array('absolute' => TRUE)) . '?cid1={contact.contact_id}&{contact.checksum}</p>',
  );
  $form['options']['contact_matching'] = array(
    '#type' => 'checkbox',
    '#title' => t('Autofill Contact 1 with Current User'),
    '#default_value' => $settings['contact_matching'],
    '#description' => '<span id="civi-contact-match-on">' . t('Enable this option if users will fill out this form on behalf of themselves. Disable it to allow a logged-in user to enter someone else as contact 1.') . '</span> <span id="civi-contact-match-off">' . t('This option is disabled when contact 1 is not an individual.') . '</span>',
  );
  $form['options']['create_fieldsets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Create Fieldsets'),
    '#default_value' => (bool) $settings['create_fieldsets'],
    '#description' => t('Create a fieldset around each contact. Allows the contact clone feature to work. (Optional - all CiviCRM fields will be processed regardless of how they are arranged).'),
  );
  $form['options']['confirm_subscription'] = array(
    '#type' => 'checkbox',
    '#title' => t('Confirm Subscriptions'),
    '#default_value' => (bool) $settings['confirm_subscription'],
    '#description' => t('Recommended. Send a confirmation email before adding contacts to publicly subscribable mailing list groups.') . '<br />' . t('Your public mailing lists:') . ' <em>',
  );
  if ($ml = webform_civicrm_get_options('mailing_lists')) {
    $form['options']['confirm_subscription']['#description'] .= implode(', ', $ml) . '</em>';
  }
  else {
    $form['options']['confirm_subscription']['#description'] .= t('none') . '</em>';
  }
  $form['options']['block_unknown_users'] = array(
    '#type' => 'checkbox',
    '#title' => t('Block Unknown Users'),
    '#default_value' => !empty($settings['block_unknown_users']),
    '#description' => t('Only allow users to see this form if they are logged in or following a personalized link from CiviMail.'),
  );
  $form['options']['new_contact_source'] = array(
    '#type' => 'textfield',
    '#title' => t('New Contact Source'),
    '#maxlength' => 255,
    '#size' => 30,
    '#default_value' => $settings['new_contact_source'],
    '#description' => t('Optional "source" label for any new contact created by this webform.'),
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save Settings'),
  );
  if (module_exists('vertical_tabs')) {
    $form['#pre_render'][] = 'vertical_tabs_form_pre_render';
  }
  return $form;
}


/**
 * Build the $data array for webform settings; called while rebuilding or post-processing the configure form.
 */
function webform_civicrm_process_form_settings(&$vals) {
  $data = array('contact' => array());
  $created_new_contact = FALSE;
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  for ($n = 1; $n <= $vals['number_of_contacts']; ++$n) {
    if (isset($vals[$n . '_contact_type'])) {
      $data['contact'][$n] = array(
        'contact' => array(1 => array(
          'contact_type' => $vals[$n . '_contact_type'],
          'contact_sub_type' => NULL,
        )),
        'activity_target' => isset($vals['activity_targets'][$n]) ? $vals['activity_targets'][$n] : 1,
      );
      if (isset($vals['civicrm_' . $n . '_contact_1_contact_contact_sub_type'])) {
        $sub_type = $vals['civicrm_' . $n . '_contact_1_contact_contact_sub_type'];
        if (isset($sub_types[$vals[$n . '_contact_type']][$sub_type])) {
          $data['contact'][$n]['contact'][1]['contact_sub_type'] = $sub_type;
        }
      }
    }
    else {
      $data['contact'][$n] = array(
        'contact' => array(1 => array(
            'contact_type' => 'individual',
            'contact_sub_type' => NULL,
          )),
        'activity_target' => 1,
      );
      $created_new_contact = TRUE;
    }
  }
  if ($created_new_contact) {
    drupal_set_message(t('Tip: Consider using the clone feature to add multiple similar contacts. (see help for more info)'), 'status', FALSE);
  }
  foreach ($vals as $key => $val) {
    if (strpos($key, '_number_of_') !== FALSE) {
      list($ent, $n, $k) = explode('_', $key, 3);
      if (isset($data[$ent][$n]) || $ent == 'participant') {
        $data[$ent][$n][$k] = $val;
      }
    }
  }
  if (!empty($vals['activity_type_id'])) {
    $data['activity'] = array(1 => array('activity' => array(1 => array('activity_type_id' => $vals['activity_type_id']))));
    if (isset($vals['activity_subject'])) {
      $data['activity'][1]['activity'][1]['subject'] = $vals['activity_subject'];
      $data['activity'][1]['assignee_group'] = $vals['activity_assignee_group'];
      $data['activity'][1]['add_link'] = $vals['activity_link'];
      $data['activity'][1]['existing_activity_status'] = $vals['existing_activity_status'];
    }
    else {
      $data['activity'][1]['activity'][1]['status_id'] = 2;
    }
  }
  if (!empty($vals['case_type_id'])) {
    $data['case'] = array(1 => array('case' => array(1 => array('case_type_id' => $vals['case_type_id']))));
    if (isset($vals['case_subject'])) {
      $data['case'][1]['case'][1]['subject'] = $vals['case_subject'];
      $data['case'][1]['case'][1]['status_id'] = $vals['case_status_id'];
      $data['case'][1]['case'][1]['creator_id'] = $vals['case_creator_id'];
      $data['case'][1]['case'][1]['medium_id'] = $vals['case_medium_id'];
    }
  }
  if (isset($vals['participant_reg_type'])) {
    $data['participant_reg_type'] = $vals['participant_reg_type'];
    $data['reg_options'] = $vals['reg_options'];
  }
  $vals['data'] = $data;
}


/**
 * Build a field item for the configure form
 */
function webform_civicrm_configure_form_item($fid, $field, $settings) {
  static $lists = array();
  if (!$lists) {
    $lists = webform_civicrm_get_fields('lists');
  }
  list($lobo, $c, $ent, $n, $table, $name) = explode('_', $fid, 6);
  $field['name'] = str_replace('#', $table == 'relationship' ? $n : '', $field['name']);

  // Create dropdown list
  if (!empty($field['expose_list'])) {
    // Retrieve option list and cache (except for dynamic types)
    if (!is_array($lists[$name]) || !empty($field['do_not_cache'])) {
      $lists[$name] = array('create_civicrm_webform_element' => t('-user select-'));
      if (!empty($field['empty_option'])) {
        $lists[$name] += array($field['empty_option']);
      }
      $lists[$name] += webform_civicrm_field_options($fid, 'arr', $settings['data']);
    }
    $item = array(
      '#type' => 'select',
      '#title' => $field['name'],
      '#options' => $lists[$name],
      '#default_value' => !empty($field['empty_option']) ? 0 : NULL,
    );
    if (isset($settings[$fid])) {
      $item['#default_value'] = $settings[$fid];
    }
    elseif (isset($settings['data'][$ent][$c][$table][$n][$name])) {
      $item['#default_value'] = $settings['data'][$ent][$c][$table][$n][$name];
    }
    else {
      $options = array_keys($lists[$name]);
      $item['#default_value'] = $options[1];
    }
    if ($table == 'address' && $name == 'master_id') {
      $item['#attributes']['onchange'] = "web_civi_master_id($c, $n);";
    }
  }
  // Create checkbox
  else {
    $item = array(
      '#type' => 'checkbox',
      '#title' => $field['name'],
      '#return_value' => 'create_civicrm_webform_element',
      '#default_value' => !empty($settings[$fid]),
    );
  }
  if (isset($field['extra']['description']) && $d = $field['extra']['description']) {
    $item['#description'] = strlen($d) > 75 ? substr($d, 0, 75) . '...' : $d;
  }
  return $item;
}


/**
 * Submission handler, saves CiviCRM options for a Webform node
 */
function webform_civicrm_configure_form_submit($form, &$form_state) {
  $button = $form_state['clicked_button']['#id'];
  $node = $form_state['storage']['node'];
  $vals = webform_civicrm_aval($form_state, 'storage:vals', $form_state['values']);

  if ((empty($node->webform_civicrm) && !$vals['nid']) || $button == 'edit-cancel') {
    $form_state['rebuild'] = TRUE;
    unset($form_state['storage']['msg']);
    return;
  }
  unset($form_state['storage']);

  civicrm_initialize();
  $delete_me = $enabled = webform_civicrm_enabled_fields($node);
  $fields = webform_civicrm_get_fields();
  $sets = webform_civicrm_get_fields('sets');

  // Fields to delete
  foreach ($enabled as $key => $val) {
    if ((webform_civicrm_aval($vals, $key) === 'create_civicrm_webform_element' && $vals['nid'])
    || strpos($key, 'fieldset') !== FALSE) {
      unset($delete_me[$key]);
    }
  }

  // Display a confirmation before deleting fields
  if ($delete_me && $button == 'edit-submit') {
    $msg = '<p>' . t('These existing fields are no longer needed for CiviCRM processing based on your new form settings.') . '</p><ul>';
    foreach ($delete_me as $key => $id) {
      list($lobo, $c, $ent, $n, $table, $name) = explode('_', $key, 6);
      $info = '';
      if ($ent == 'contact' || $ent == 'participant') {
        $info = '<em>' . t('Contact !num', array('!num' => $c));
      }
      if ($info && isset($sets[$table]['max_instances'])) {
        $info .= ' ' . $sets[$table]['label'] . ' ' . $n;
      }
      $info .= $info ? ':</em> ' : '';
      $msg .= '<li>' . $info . $node->webform['components'][$id]['name'] . '</li>';
    }
    $msg .= '</ul><p>' . t('Would you like them to be automatically removed from the webform? This is recommended unless you need webform-results reporting for these fields. (They can still be deleted manually later if you choose not to remove them now.)') . '</p><p><em>' . t('Note: Deleting webform components cannot be undone, and will result in the loss of webform-results info for those elements. Data in the CiviCRM database will not be affected.') . '</em></p>';
    $form_state['storage']['msg'] = $msg;
    $form_state['storage']['vals'] = $vals;
    $form_state['rebuild'] = TRUE;
    return;
  }

  module_load_include('inc', 'webform', 'includes/webform.components');
  $nid = $node->nid;
  $form_state['redirect'] = 'node/' . $nid . '/webform';

  if ($delete_me && $button == 'edit-confirm') {
    // Delete fields
    foreach ($delete_me as $id) {
      drupal_set_message(t('Deleted field: %name', array('%name' => $node->webform['components'][$id]['name'])));
      webform_component_delete($node, $node->webform['components'][$id]);
    }
  }

  // Disable CiviCRM for this form
  if (!$vals['nid']) {
    webform_civicrm_disable($nid);
    drupal_set_message(t('CiviCRM processing for this form is now disabled.'));
    return;
  }

  // CiviCRM enabled
  webform_civicrm_process_form_settings($vals);
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  if (!$vals['toggle_message']) {
    $vals['message'] = '';
  }

  $i = $new_fields = 0;
  $lists = webform_civicrm_get_fields('lists');
  foreach ($vals as $key => $val) {
    if (substr($key, 0, 7) == 'civicrm') {
      ++$i;
      list($lobo, $c, $ent, $n, $table, $name) = explode('_', $key, 6);
      // Add webform components (fields)
      if (!isset($enabled[$key]) && $val === 'create_civicrm_webform_element') {
        $field = $fields[$table . '_' . $name];
        $field['nid'] = $nid;
        $field['form_key'] = $key;
        $field['weight'] = $i;
        $field['pid'] = 0;
        if ($n > 1 || $table == 'relationship') {
          if (strpos($field['name'], '#') === FALSE) {
            $field['name'] .= ' ' . $n;
          }
          else {
            $field['name'] = str_replace('#', $n, $field['name']);
          }
        }
        else {
          $field['name'] = str_replace(' #', '', $field['name']);
        }
        if ($name == 'contact_sub_type') {
          $field['name'] .= ' ' . $contact_types[$vals['data']['contact'][$c]['contact'][1]['contact_type']];
        }

        if (($field['type'] == 'textfield' || $field['type'] == 'email') && empty($field['extra']['width'])) {
          $field['extra']['width'] = 20;
        }

        // Set default country
        if ($name == 'country_id') {
          $config = CRM_Core_Config::singleton();
          $field['value'] = $config->defaultContactCountry;
        }
        // Retrieve option list
        if (isset($lists[$name])) {
          $field['extra']['items'] = webform_civicrm_field_options($key, 'str', $vals['data']);
          if (!isset($field['extra']['aslist']) && empty($field['extra']['multiple'])) {
            $field['extra']['aslist'] = 1;
          }
          elseif (!isset($field['extra']['aslist'])) {
            $field['extra']['aslist'] = 0;
          }
          if (is_numeric($lists[$name])) {
            // Lookup defaults for custom fields
            $sql = 'SELECT value FROM civicrm_option_value WHERE is_default AND is_active AND option_group_id = ' . $lists[$name];
            $dao = &CRM_Core_DAO::executeQuery($sql);
            while ($dao->fetch()) {
              $field['value'] .= ($field['value'] ? ',' : '') . $dao->value;
            }
          }
        }
        if ($table == 'other' || $field['type'] == 'hidden') {
          unset($field['extra']['description']);
        }
        if ($field['type'] == 'date') {
          $field['extra']['timezone'] = 'user';
          $field['extra']['datepicker'] = 1;
        }
        // Create fieldsets - for contact and contact-specific event fields only
        if (!empty($vals['create_fieldsets'])
        && $ent != 'activity'
        && ($ent != 'participant' || webform_civicrm_aval($node->webform_civicrm['data'], 'participant_reg_type') == 'separate')) {
          if (!isset($enabled['civicrm_' . $c . '_contact_1_fieldset_fieldset'])) {
            $new_set = array(
              'nid' => $nid,
              'form_key' => 'civicrm_' . $c . '_contact_1_fieldset_fieldset',
              'type' => 'fieldset',
              'name' => t('Contact !num', array('!num' => $c)),
              'pid' => 0,
              'weight' => $i,
              'extra' => array(),
            );
            $enabled['civicrm_' . $c . '_contact_1_fieldset_fieldset'] = webform_component_insert($new_set);
          }
          $field['pid'] = $enabled['civicrm_' . $c . '_contact_1_fieldset_fieldset'];
        }
        // Save webform component
        $fid = webform_component_insert($field);
        ++$new_fields;
      }
      // Store exposed contact settings that don't create form elements
      elseif (isset($fields[$table . '_' . $name]['expose_list'])
      && $val !== 'create_civicrm_webform_element' && $val !== 0) {
        $vals['data'][$ent][$c][$table][$n][$name] = $val;
      }
    }
  }
  if ($new_fields == 1) {
    drupal_set_message(t('Created field: %name', array('%name' => $field['name'])));
  }
  elseif ($new_fields) {
    drupal_set_message(t('Created !num new fields.', array('!num' => $new_fields)));
  }

  // Write/update record
  if (empty($node->webform_civicrm)) {
    drupal_write_record('webform_civicrm_forms', $vals);
    drupal_set_message(t('CiviCRM processing for this form is now enabled.'));
  }
  else {
    drupal_write_record('webform_civicrm_forms', $vals, 'nid');
    drupal_set_message(t('Your CiviCRM form settings have been updated.'));
  }
}


/**
 * Alter front-end of webforms: Called by hook_form_alter() when rendering a civicrm-enabled webform
 * Add custom prefix.
 * Display messages.
 * Block users who should not have access.
 * Set webform default values.
 */
function _webform_civicrm_webform_frontend_form_alter(&$form, &$form_state) {
  webform_civicrm_add_js('webform_civicrm_webforms.js');
  $form['#validate'][] = 'webform_civicrm_form_validate';

  civicrm_initialize();
  $config = CRM_Core_Config::singleton();
  $node = $form['#node'];
  $settings = $node->webform_civicrm;
  $data = $settings['data'];
  $fields = webform_civicrm_get_fields();
  $id = $info = $args = array();

  // JS Cache eliminates the need for most ajax state/province callbacks
  foreach ($data['contact'] as $c) {
    if (!empty($c['number_of_address'])) {
      $dc = $config->defaultContactCountry;
      $js = '
        var stateProvinceDefault = ' . json_encode(webform_civicrm_get_options('state_province', $dc)) . ";
        var stateProvinceCache = {
          'default':stateProvinceDefault,
          '$dc':stateProvinceDefault,
          '':{'':'" . t('- first choose a country -') . "'}};
        var webformSelectNa = '" . t('- N/A -') . "';
        var webformSelectNone = '" . t('- None -') . "';
        var webformSelectSelect = '" . t('- Select -') . "';";
      webform_civicrm_add_js($js, 'inline');
      break;
    }
  }

  // Early return if the form (or page) was already submitted
  if (empty($form_state['rebuild']) && !empty($form_state['input'])
  || webform_civicrm_aval($form_state, 'clicked_button:#id') == 'edit-previous') {
    webform_civicrm_fill_values($form['submitted'], array(), $fields, array());
    return;
  }

  // If this is an edit op, use the original IDs and return
  if (!empty($form['#submission'])) {
    if (isset($form['#submission']->civicrm)) {
      $_SESSION['webform_civicrm_data'][$node->nid]['cid'] = $form['#submission']->civicrm['contact_id'];
      $_SESSION['webform_civicrm_data'][$node->nid]['act'][1] = $form['#submission']->civicrm['activity_id'];
    }
    webform_civicrm_fill_values($form['submitted'], array(), $fields, array());
    return;
  }

  // If this form is already in-process, IDs will be stored in session
  if (!empty($form_state['input'])) {
    $id = $_SESSION['webform_civicrm_data'][$node->nid];
  }
  else {
    $args = $_GET;
    if (!empty($args['cid']) && empty($args['cid1'])) {
      $args['cid1'] = $args['cid'];
    }
    $count = count($data['contact']);
    for ($i = 1; $i <= $count; ++$i) {
      if (!empty($args['cid' . $i]) && is_numeric($cid = $args['cid' . $i])) {
        require_once 'CRM/Contact/BAO/Contact/Permission.php';
        if (CRM_Contact_BAO_Contact_Permission::validateChecksumContact($cid, CRM_Core_DAO::$_nullObject)) {
          $id['cid'][$i] = $cid;
          // Identify contact 1 as acting user if not already logged in
          if ($i == 1 && user_is_anonymous()) {
            CRM_Core_DAO::executeQuery('SET @civicrm_user_id = %1', array(1 => array($cid, 'Integer')));
          }
        }
      }
    }
  }
  // If user is logged in, look up the CID
  if (empty($id['cid'][1]) && $settings['contact_matching'] && $data['contact'][1]['contact'][1]['contact_type'] == 'individual' && $cid = webform_civicrm_user_cid()) {
    $id['cid'][1] = $cid;
  }
  if (!empty($args['aid']) && !empty($id['cid']) && is_numeric($args['aid'])) {
    $result = webform_civicrm_api('activity', 'get', array('activity_id' => $args['aid'], 'return.target_contact_id' => 1, 'return.assignee_contact_id' => 1));
    if (isset($result['values'][$args['aid']])) {
      $act = $result['values'][$args['aid']];
      $valid = FALSE;
      // Verify that this activity is the right type and that our contacts have some involvement in it
      if ($act['activity_type_id'] == $data['activity'][1]['activity'][1]['activity_type_id']) {
        foreach ($id['cid'] as $cid) {
          if ($act['source_contact_id'] == $cid || in_array($cid, $act['target_contact_id']) || in_array($cid, $act['assignee_contact_id'])) {
            $activity = array('activity' => array(1 => $result['values'][$args['aid']]));
            $custom = webform_civicrm_get_custom($args['aid'], 'activity');
            $info['activity'] = array(1 => ($activity + $custom));
            $id['act'][1] = $args['aid'];
            break;
          }
        }
      }
    }
  }
  else {
    if (!empty($data['case'][1]['case'][1]['status_id']) && !empty($id['cid'][1])) {
      $result = webform_civicrm_api('case', 'get', array('client_id' => $id['cid'][1]));
      if (!empty($result['values'])) {
        foreach ($result['values'] as $case) {
          if ($case['status_id'] == $data['case'][1]['case'][1]['status_id'] && empty($case['is_deleted']) && $case['case_type_id'] == $data['case'][1]['case'][1]['case_type_id']) {
            $id['case'][1] = $case['id'];
            break;
          }
        }
      }
    }
    if (!empty($data['activity'][1]['existing_activity_status']) && !empty($id['cid'][1])) {
      $params = array(
        'contact_id' => $id['cid'][1],
        'activity_type_id' => $data['activity'][1]['activity'][1]['activity_type_id'],
        'status_id' => $data['activity'][1]['existing_activity_status'],
      );
      if (!empty($id['case'][1])) {
        $params['case_id'] = $id['case'][1];
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
  $enabled = webform_civicrm_enabled_fields($node);

  // Check if events are open to registration and take appropriate action
  $events = array();
  $reg = webform_civicrm_aval($data, 'reg_options', array());
  if (!empty($data['participant_reg_type'])) {
    // Fetch events set in back-end
    $data += array('participant' => array());
    foreach ($data['participant'] as $e => $par) {
      if (!empty($par['participant'])) {
        foreach ($par['participant'] as $n => $p) {
          if (!empty($p['event_id']) && $p['event_id'] != 'create_civicrm_webform_element') {
            list($eid) = explode('-', $p['event_id']);
            if (is_numeric($eid)) {
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
    // Add events exposed to the form
    foreach($enabled as $field => $fid) {
      if (strpos($field, 'participant_event_id')) {
        $options = webform_civicrm_str2array(webform_civicrm_aval($node->webform['components'][$fid], 'extra:items'));
        foreach ($options as $p => $label) {
          list($eid) = explode('-', $p);
          $events[$eid]['ended'] = TRUE;
          $events[$eid]['title'] = $label;
          list(,$e,,$n) = explode('_', $field);
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
          if (!empty($reg['show_remaining']) && empty($form_state['input'])) {
            drupal_set_message(t('Sorry, %event has ended.', array('%event' => $event['title'])), 'warning', FALSE);
          }
        }
        elseif ($event['full']) {
          if (!empty($reg['show_remaining']) && empty($form_state['input'])) {
            drupal_set_message('<em>' . $event['title'] . '</em>: ' . $event['full_message'], 'warning', FALSE);
          }
        }
        else {
          $reg['block_form'] = FALSE;
          if ($event['max_participants'] && empty($form_state['input'])
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
    $info['contact'][$c] = array();
    $cid = 0;
    if (!empty($id['cid'][$c])) {
      $cid = $id['cid'][$c];
    }
    elseif ($contact['contact'][1]['contact_type'] == 'organization') {
      // Populate "current_employer" without setting it in $id
      // (this allows the user to change their current employer on the form)
      $employee = 0;
      for ($i = $c; $i <= $count; ++$i) {
        $con = $data['contact'][$i];
        if (!empty($con['relationship']) && (($con['contact'][1]['contact_type'] == 'individual' && !empty($id['cid'][$i])) || $i == $c)) {
          foreach ($con['relationship'] as $target => $rel) {
            if ($rel['relationship_type_id'] == 'ce_b' && !empty($id['cid'][$target])) {
              $employee = $id['cid'][$target];
            }
            elseif ($rel['relationship_type_id'] == 'ce_a' && $target == $c) {
              $employee = $id['cid'][$i];
            }
            if ($employee) {
              $result = webform_civicrm_api('contact', 'get', array('contact_id' => $employee, 'return.current_employer_id' => 1));
              if (!empty($result['values'][$employee]['current_employer_id'])) {
                $cid = $result['values'][$employee]['current_employer_id'];
                break;
              }
              else {
                $employee = 0;
              }
            }
          }
        }
      }
    }
    if (!$cid) {
      continue;
    }
    foreach (array('contact', 'address', 'email', 'phone', 'website') as $field) {
      if (!empty($contact['number_of_' . $field]) || $field == 'contact') {
        $params = array('contact_id' => $cid);
        if ($field != 'contact') {
          $params['options'] = array('sort' => 'is_primary DESC');
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
                $address = $address + $custom['address'][1];
              }
            }
          }
          $info['contact'][$c][$field] = $result;
        }
      }
    }
    // Get custom contact data if needed
    foreach ($contact as $k => $v) {
      if (substr($k, 0, 12) == 'number_of_cg' && !empty($v)) {
        $custom = webform_civicrm_get_custom($cid);
        $info['contact'][$c] = $info['contact'][$c] + $custom;
        break;
      }
    }
    // Preferred communication method is not fetched by default by the api
    if (isset($enabled['civicrm_' . $c . '_contact_1_contact_preferred_communication_method'])) {
      $result = webform_civicrm_api('contact', 'get', array('contact_id' => $cid, 'return.preferred_communication_method' => 1));
      $info['contact'][$c]['contact'][1] += $result['values'][$cid];
    }
    // Set default values for group field
    if (isset($enabled['civicrm_' . $c . '_contact_1_other_groups'])) {
      $groups = webform_civicrm_api('group_contact', 'get', array('contact_id' => $cid));
      $info['contact'][$c]['other'][1]['groups'] = array();
      foreach ($groups['values'] as $group) {
        $info['contact'][$c]['other'][1]['groups'][] = $group['group_id'];
      }
    }
    // Retrieve participant data
    if ($events && ($c == 1 || $data['participant_reg_type'] == 'separate')) {
      $select = array('id', 'event_id', 'role_id', 'status_id');
      if (in_array('CiviCampaign', $config->enableComponents)) {
        $select[] = 'campaign_id';
      }
      $dao = &CRM_Core_DAO::executeQuery('SELECT ' . implode(',', $select) . " FROM civicrm_participant WHERE contact_id = $cid AND event_id IN (" . implode(',', array_keys($events)) . ")");
      while ($dao->fetch()) {
        $par = array();
        foreach($select as $sel) {
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

  if (!empty($id['cid'][1])) {
    if ($settings['prefix_known']) {
      $form['#prefix'] = webform_civicrm_aval($form, '#prefix', '') . '<div class="webform-civicrm-prefix contact-known">' . nl2br(webform_civicrm_replace_tokens($settings['prefix_known'], $info['contact'][1]['contact'][1])) . '</div>';
    }
    if ($settings['message'] && empty($form_state['input'])) {
      webform_civicrm_set_message($settings['message'], $info['contact'][1]['contact'][1]);
    }
  }

  // Store ids in session
  $_SESSION['webform_civicrm_data'][$node->nid] = $id;

  // Set default values and other attributes for CiviCRM form elements
  // Passing $submitted helps us avoid overwriting values that have been entered on a multi-step form
  $submitted = empty($form_state['values']['submitted']) ? array() : $form_state['values']['submitted'];
  webform_civicrm_fill_values($form['submitted'], $info, $fields, $submitted);
}


/**
 * Alter back-end webform component edit forms.
 * Called by hook_form_alter() whenever editing a webform component.
 */
function _webform_civicrm_webform_component_form_alter(&$form, $form_state) {
  // Is this a CiviCRM-enabled webform?
  $node = node_load($form['nid']['#value']);
  if (!empty($node->webform_civicrm)) {
    civicrm_initialize();
    $fid = $form['form_key']['#default_value'];
    if (!($pieces = webform_civicrm_explode_key($fid))) {
      return;
    }
    list($lobo, $i, $ent, $n, $table, $key) = $pieces;
    $fields = webform_civicrm_get_fields();
    // Is this component a CiviCRM field?
    if (isset($fields[$table . '_' . $key]) ||
      ($lobo == 'civicrm' && $table == 'fieldset')) {
      drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_style.css');

      if (arg(5) != 'clone' || $key == 'fieldset') {
        // Prevent users from editing the form_key and breaking things
        $form['form_key']['#disabled'] = TRUE;
        $form['form_key']['#value'] = $fid;
        $form['form_key']['#description'] = t('Automatically set for use by CiviCRM processing.');
        // Clone an entire contact when cloning their fieldset
        if (arg(5) == 'clone') {
          $form['submit']['#value'] = t('Clone Contact');
          $new = count($node->webform_civicrm['data']['contact']) + 1;
          $form['form_key']['#value'] = implode('_', array($lobo, $new, $ent, $n, $table, $key));
          $form['name']['#default_value'] = str_replace($i, $new, $form['name']['#default_value']);
          array_unshift($form['#submit'], 'webform_civicrm_contact_clone');
          if (empty($form_state['input'])) {
            drupal_set_message(t('Press the button below to clone this contact. A new CiviCRM contact will be added to the form with all the settings for contact !num. All fields from within this fieldset will be cloned (be careful, that may include non-contact !num fields).', array('!num' => $i)), 'status', FALSE);
          }
        }
      }
      elseif (empty($form_state['input'])) {
        // Clone a single CiviCRM field
        drupal_set_message(t('You are cloning a CiviCRM component. Refer to the Webform CiviCRM instructions for how to set the key if you want the new field to be processed by CiviCRM. You can also clone an entire contact by clicking the clone button by their fieldset.'), 'status', FALSE);
      }

      if ($table == 'address' && $key == 'state_province_id') {
        $form['validation']['maxlength']['#type'] = 'hidden';
        $form['validation']['maxlength']['#value'] = 5;
        $form['value']['#description'] = t('To set a default value, enter the state/province abbreviation in all caps.');
      }

      // Here's a much nicer interface for choosing options than the standard textfields
      if (array_key_exists($key, webform_civicrm_get_fields('lists'))) {
        webform_civicrm_add_js('webform_civicrm_options.js');
        $element = $node->webform['components'][$form['cid']['#value']];
        $options = $sort = webform_civicrm_field_options($fid, 'arr', $node->webform_civicrm['data']);
        $defaults_selected = array();
        if (!empty($element['value'])) {
          foreach (explode(',', trim($element['value'])) as $v) {
            $defaults_selected[] = '_web_civi_option_selected_' . $v;
          }
        }
        $form['value']['#type'] = 'hidden';
        $form['civicrm_options_fieldset'] = array(
          '#type' => 'fieldset',
          '#title' => t('Options'),
          '#description' => t('Enable the options you wish for this hidden form element.'),
          '#theme' => 'webform_civicrm_options',
        );
        $option_keys = array();
        foreach ($options as $k => $v) {
          $option_keys['_web_civi_option_selected_' . $k] = '';
          $form['civicrm_options_fieldset']['civicrm_option_name_' . $k] = array(
            WEBFORM_CIVICRM_MARKUP => '<span class="civicrm-option-name">' . $v . '</span>',
          );
        }
        if ($element['type'] != 'hidden') {
          $form['civicrm_options_fieldset']['#description'] = t('Drag the arrows to re-order these options. Click the "enabled" checkbox to show/remove an item from the form. Set the label as you want it to appear on the form. Check the "default" box for an option to be selected by default when a user views the form.');
          $options_selected = webform_civicrm_str2array($element['extra']['items']);
          // Sort weights. Unselected options will be at the bottom.
          $option_keys = $option_selected_keys = array();
          foreach ($options_selected as $k => $v) {
            if (isset($options[$k])) {
              $option_keys['_web_civi_option_selected_' . $k] = '';
              $option_selected_keys[] = '_web_civi_option_selected_' . $k;
              unset($sort[$k]);
            }
          }
          foreach ($sort as $k => $v) {
            $option_keys['_web_civi_option_selected_' . $k] = '';
          }
          $form['extra']['items']['#type'] = 'hidden';
          $form['extra']['items']['#required'] = FALSE;
          $form['extra']['options_source']['#access'] = FALSE;
          $form['civicrm_options_fieldset']['civicrm_options'] = array(
            '#type' => 'checkboxes',
            '#required' => TRUE,
            '#options' => $option_keys,
            '#default_value' => $option_selected_keys,
          );
          $w = 0;
          foreach ($option_keys as $k => $v) {
            $k = str_replace('_web_civi_option_selected_', '', $k);
            $form['civicrm_options_fieldset']['civicrm_option_label_' . $k] = array(
              '#type' => 'textfield',
              '#size' => 30,
              '#default_value' => !empty($options_selected[$k]) ? $options_selected[$k] : $options[$k],
            );
            $form['civicrm_options_fieldset']['civicrm_option_weight_' . $k] = array(
              '#type' => 'textfield',
              '#size' => 3,
              '#default_value' => ++$w,
            );
          }
        }
        $form['civicrm_options_fieldset']['civicrm_defaults'] = array(
          '#type' => 'checkboxes',
          '#options' => $option_keys,
          '#default_value' => $defaults_selected,
        );
        // Auto set multi-value option for single-valued entities
        if (empty($fields[$table . '_' . $key]['extra']['multiple'])
        && $element['type'] != 'hidden' && $key != 'event_id') {
          $form['extra']['multiple']['#type'] = 'hidden';
          $form['extra']['multiple']['#value'] = 0;
          $form['civicrm_options_fieldset']['civicrm_defaults']['#type'] = 'radios';
          $form['civicrm_options_fieldset']['civicrm_defaults']['#options'] += array('' => '');
          $form['civicrm_options_fieldset']['civicrm_defaults']['#default_value'] = $element['value'] ? '_web_civi_option_selected_' . $element['value'] : '';
        }
        array_unshift($form['#submit'], 'webform_civicrm_process_options_selection');
      }
      // Simplify form for hidden fields
      elseif ($key == 'contact_id' || $key == 'external_identifier') {
        $form['value']['#type'] = 'hidden';
        $form['value']['#value'] = 0;
        $form['#prefix'] = '<p>' . t('There are no configuration options for this hidden field. You can use it for post processing, for example to include a link to the CiviCRM contact in an email.') . '</p>';
      }
      // Autocomplete widget for selecting tags
      if ($key == 'tags') {
        $form['value']['#title'] = t('Tags');
        $form['value']['#description'] = t('Enter a comma-separated list of tags to add to contacts who submit this webform.') . '<br />' . t('Use the autocomplete to select existing tags, or you may add new ones.');
        $form['value']['#type'] = 'textfield';
        $form['value']['#size'] = 100;
        $form['value']['#autocomplete_path'] = 'webform-civicrm/js/tags';
      }
    }
  }
}


/**
 * Custom Processing for CiviCRM webform component option lists
 */
function webform_civicrm_process_options_selection($form, &$form_state) {
  $vals = &$form_state['values'];
  $vals['value'] = '';
  if (is_array($vals['civicrm_options_fieldset']['civicrm_defaults'])) {
    foreach ($vals['civicrm_options_fieldset']['civicrm_defaults'] as $k) {
      if ($k) {
        $vals['value'] .= ($vals['value'] ? ',' : '') . str_replace('_web_civi_option_selected_', '', $k);
      }
    }
  }
  else {
    $vals['value'] = str_replace('_web_civi_option_selected_', '', $vals['civicrm_options_fieldset']['civicrm_defaults']);
  }
  if (!empty($vals['civicrm_options_fieldset']['civicrm_options'])) {
    $items = array();
    foreach ($vals['civicrm_options_fieldset']['civicrm_options'] as $k) {
      if ($k) {
        $v = str_replace('_web_civi_option_selected_', '', $k);
        if (!($label = $vals['civicrm_options_fieldset']['civicrm_option_label_' . $v])) {
          $label = $form['civicrm_options_fieldset']['civicrm_option_name_' . $v]['#value'];
        }
        $items[$vals['civicrm_options_fieldset']['civicrm_option_weight_' . $v]] = $v . '|' . $label;
      }
    }
    ksort($items);
    $vals['extra']['items'] = implode("\n", $items);

    // A single radio should be shown as a checkbox
    if (count($items) == 1 && empty($vals['extra']['aslist'])) {
      $vals['extra']['multiple'] = 1;
    }
  }
}


/**
 * Build select all/none js links for a fieldset
 */
function webform_civicrm_js_select($name) {
  return array(WEBFORM_CIVICRM_MARKUP =>
    '<div class="web-civi-js-select">
      <a href="javascript:web_civi_select_reset(\'checked\', ' . "'#$name'" . ')">' . t('Select All') . '</a> |
      <a href="javascript:web_civi_select_reset(\'\', ' . "'#$name'" . ')">' . t('Select None') . '</a> |
      <a href="javascript:web_civi_select_reset(\'reset\', ' . "'#$name'" . ')">' . t('Restore') . '</a>
    </div>',
  );
}
