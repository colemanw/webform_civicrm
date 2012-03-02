<?php

/**
 * @file
 * Webform CiviCRM module's administrative functions.
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
  $form_state['storage']['nid'] = $node->nid;

  // Display confirmtion message before deleting fields
  if (!empty($form_state['storage']['msg'])) {
    $form['#prefix'] = $form_state['storage']['msg'];
    $form['cancel'] = $form['no_delete'] = $form['confirm'] = array('#type' => 'submit');
    $form['confirm']['#value'] = t('Remove Fields and Save Settings');
    $form['no_delete']['#value'] = t('Leave Fields and Save Settings');
    $form['cancel']['#value'] = t('Cancel (go back)');
    return $form;
  }
  webform_civicrm_add_js('webform_civicrm_admin.js');
  drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_admin.css');
  civicrm_initialize();
  require_once 'CRM/Core/BAO/Setting.php';
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
                  'contact_sub_type' => array(),
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
    '#suffix' => '<div>' . t('You have Javascript disabled. You will need to click this button after changing any option to see the result.') . '</div></div>',
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
        if (!array_intersect($c['contact'][1]['contact_sub_type'], $set['sub_types'])) {
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
        $set['label'] = $sid == 'relationship' ? t('Relationship') : $set['label'];
        $selector = array(
          '#type' => 'select',
          '#default_value' => $c['number_of_' . $sid],
        );
        if ($set['max_instances'] > 1) {
          $selector['#options'] = range(0, $set['max_instances']);
          $selector['#title'] = t('Number of %type Fields', array('%type' => $set['label']));
        }
        else {
          $selector['#options'] = array(t('No'), t('Yes'));
          $selector['#title'] = t('Enable %type Fields', array('%type' => $set['label']));
        }
        if ($hide) {
          $selector['#prefix'] = '<div class="hidden">';
          $selector['#suffix'] = '</div>';
        }
        $pos['contact_' . $n . '_number_of_' . $sid] = $selector;
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
    '#title' => t('"Not You?" Message'),
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
  if (in_array('CiviCase', $config->enableComponents, TRUE)
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
    $case_type = $form['act']['case_type_id']['#default_value'] = $data['case'][1]['case'][1]['case_type_id'];
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
    foreach ($sets as $sid => $set) {
      $sid .= '_fieldset';
      if ($set['entity_type'] == 'case') {
        if (!empty($set['sub_types']) && !in_array($case_type, $set['sub_types'])) {
          continue;
        }
        $form['act']['case'][$sid] = array(
          '#type' => 'fieldset',
          '#title' => $set['label'],
          '#attributes' => array('id' => $sid, 'class' => webform_civicrm_fapi_class('web-civi-checkbox-set')),
          'js_select' => webform_civicrm_js_select($sid),
        );
        foreach ($set['fields'] as $fid => $field) {
          $fid = 'civicrm_1_case_1_' . $fid;
          $form['act']['case'][$sid][$fid] = webform_civicrm_configure_form_item($fid, $field, $settings);
        }
      }
    }
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
      '#options' => array(t('- Webform Contacts -')) + webform_civicrm_get_options('group'),
      '#default_value' => webform_civicrm_aval($data, 'activity:1:assignee_group'),
    );
    webform_civicrm_ajax_form_item($form, 'act:case:activity_fields', 'activity_assignee_group', 'assignee');
    // Activity assignee
    $fid = 'civicrm_1_activity_1_activity_assignee_contact_id';
    $form['act']['case']['activity_fields']['assignee'][$fid] = webform_civicrm_configure_form_item($fid, $fields['activity_assignee_contact_id'], $settings);
    if (CRM_Core_BAO_Setting::getItem(CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME, 'activity_assignee_notification')) {
      $form['act']['case']['activity_fields']['assignee'][$fid]['#description'] = t('A copy of this activity will be emailed to the assignee.');
    }
    $form['act']['case']['activity_fields']['assignee']['#suffix'] .= '<div class="clear-block"></div>';
    $form['act']['case']['activity_fields']['existing_activity_status'] = array(
      '#type' => 'select',
      '#title' => t('Update Existing Activity'),
      '#description' => t('If a "@type" activity with this status already exists for contact 1, it will be updated. Otherwise a new one will be created.', array('@type' => $act_types[$settings['activity_type_id']])),
      '#options' => webform_civicrm_get_options('activity_status'),
      '#default_value' => webform_civicrm_aval($data, 'activity:1:existing_activity_status', array()),
      '#multiple' => TRUE,
    );
    $form['act']['case']['activity_fields']['activity_subject'] = array(
      '#type' => 'textfield',
      '#title' => t('Default Activity Subject'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#description' => t('You can override this default by allowing users to enter a subject (enable "Activity Subject" field below)'),
      '#default_value' => webform_civicrm_aval($data, 'activity:1:activity:1:subject', check_plain($node->title)),
    );
    $form['act']['case']['activity_fields']['activity_details'] = array(
      '#type' => 'checkboxes',
      '#options' => array(
        'entire_result' => t('Include <em>entire</em> webform submission in activity details'),
        'view_link' => t('Include link to <em>view</em> webform submission in activity details'),
        'edit_link' => t('Include link to <em>edit</em> webform submission in activity details'),
      ),
      '#default_value' => webform_civicrm_aval($data, 'activity:1:details', array('view_link')),
    );
    foreach ($data['contact'] as $n => $c) {
      $form['act']['case']['activity_fields']['activity_targets']['#options'][$n] = t('Contact !num', array('!num' => $n));
      if (!isset($data['activity']) || !empty($c['activity_target'])) {
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
          if ($fid != 'activity_assignee_contact_id') {
            $fid = 'civicrm_1_activity_1_' . $fid;
            $form['act']['case']['activity_fields'][$sid][$fid] = webform_civicrm_configure_form_item($fid, $field, $settings);
          }
        }
      }
    }
  }

  // Event participant settings
  if (in_array('CiviEvent', $config->enableComponents, TRUE)) {
    $form['event'] = array(
      '#type' => 'fieldset',
      '#title' => t('Event Registration'),
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
      $particpant_extensions = array(
        1 => 'role_id',
        2 => 'event_id',
        3 => 'event_type'
      );
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
              $role_id = webform_civicrm_aval($data, "participant:$n:particpant:$e:role_id", '');
              $event_id = webform_civicrm_aval($data, "participant:$n:particpant:$e:event_id", '');
              $event_type = webform_civicrm_aval($data, 'reg_options:event_type', '');
              if ($event_id && $event_id !== 'create_civicrm_webform_element') {
                list($event_id, $event_type) = explode('-', $event_id);
              }
              $ext = $particpant_extensions[$set['extension_of']];
              if (!in_array($$ext, $set['sub_types'])) {
                $class .= ' hidden';
              }
              $class .= ' extends-condition ' . str_replace('_', '', $ext) . '-' . implode('-', $set['sub_types']);
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
    '<br />' . url('node/' . $node->nid, array('absolute' => TRUE)) . '?cid1={contact.contact_id}&amp;{contact.checksum}</p>',
  );
  $form['options']['contact_matching'] = array(
    '#type' => 'checkbox',
    '#title' => t('Autofill Contact 1 with Current User'),
    '#default_value' => $settings['contact_matching'],
    '#description' => '<span id="civi-contact-match-on">' . t('Enable this option if users will fill out this form on behalf of themselves. Disable it to allow a logged-in user to enter someone else as contact 1. (This is the same behavior as when visiting the form with cid1=0 in the url)') . '</span> <span id="civi-contact-match-off">' . t('This option is disabled when contact 1 is not an individual.') . '</span>',
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
    if (count($ml) > 4) {
      $ml = array_slice($ml, 0, 4);
      $ml[] = t('etc.');
    }
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
          'contact_sub_type' => array(),
        )),
        'activity_target' => isset($vals['activity_targets'][$n]) ? $vals['activity_targets'][$n] : 1,
      );
      if ($sub_type = webform_civicrm_aval($vals, 'civicrm_' . $n . '_contact_1_contact_contact_sub_type')) {
        $allowed = $sub_types[$vals[$n . '_contact_type']];
        foreach($sub_type as $sub) {
          if (isset($allowed[$sub])) {
            $data['contact'][$n]['contact'][1]['contact_sub_type'][$sub] = $sub;
          }
        }
      }
    }
    else {
      $data['contact'][$n] = array(
        'contact' => array(1 => array(
            'contact_type' => 'individual',
            'contact_sub_type' => array(),
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
      $data['activity'][1]['details'] = $vals['activity_details'];
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
  $lists = webform_civicrm_get_fields('lists');
  list($lobo, $c, $ent, $n, $table, $name) = explode('_', $fid, 6);
  $field['name'] = str_replace('#', $table == 'relationship' ? $n : '', $field['name']);

  // Create dropdown list
  if (!empty($field['expose_list'])) {
    // Retrieve option list
    $options = array('create_civicrm_webform_element' => t('-user select-'));
    if (!empty($field['empty_option'])) {
      $options += array($field['empty_option']);
    }
    $options += webform_civicrm_field_options($fid, 'config_form', $settings['data']);
    $item = array(
      '#type' => 'select',
      '#title' => $field['name'],
      '#options' => $options,
      '#multiple' => !empty($field['extra']['multiple']),
      '#default_value' => !empty($field['empty_option']) ? 0 : NULL,
    );
    if (isset($settings[$fid])) {
      $item['#default_value'] = $settings[$fid];
    }
    elseif (isset($settings['data'][$ent][$c][$table][$n][$name])) {
      $item['#default_value'] = $settings['data'][$ent][$c][$table][$n][$name];
    }
    elseif (empty($field['extra']['multiple'])) {
      $options = array_keys($options);
      $item['#default_value'] = $options[1];
    }
    if (!empty($field['extra']['multiple'])) {
      $item['#description'] = t('You may choose options here and/or add this element to the webform. If you do both, options selected here will not appear on the form.');
      $item['#default_value'] = (array) $item['#default_value'];
      if (isset($settings[$fid]) && !is_array($settings[$fid])
      && isset($settings['data'][$ent][$c][$table][$n][$name])) {
        $item['#default_value'] += $settings['data'][$ent][$c][$table][$n][$name];
      }
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
  if ($d = webform_civicrm_aval($field, 'extra:description')) {
    $item['#description'] = strlen($d) > 75 ? substr($d, 0, 75) . '...' : $d;
  }
  return $item;
}


/**
 * Submission handler, saves CiviCRM options for a Webform node
 */
function webform_civicrm_configure_form_submit($form, &$form_state) {
  $button = $form_state['clicked_button']['#id'];
  $node = node_load($nid = $form_state['storage']['nid']);
  $vals = webform_civicrm_aval($form_state, 'storage:vals', $form_state['values']);

  if ((empty($node->webform_civicrm) && !$vals['nid']) || $button == 'edit-cancel') {
    $form_state['rebuild'] = TRUE;
    unset($form_state['storage']['msg']);
    return;
  }
  unset($form_state['storage']);

  civicrm_initialize();
  $delete_me = $enabled = webform_civicrm_enabled_fields($node);
  $created = $deleted = 0;
  $fields = webform_civicrm_get_fields();
  $sets = webform_civicrm_get_fields('sets');

  // Fields to delete
  foreach ($enabled as $key => $val) {
    $val = (array) webform_civicrm_aval($vals, $key);
    if ((in_array('create_civicrm_webform_element', $val, TRUE) && $vals['nid'])
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
  $form_state['redirect'] = 'node/' . $nid . '/webform';

  if ($delete_me && $button == 'edit-confirm') {
    // Delete fields
    foreach ($delete_me as $id) {
      drupal_set_message(t('Deleted field: %name', array('%name' => $node->webform['components'][$id]['name'])));
      webform_component_delete($node, $node->webform['components'][$id]);
      ++$deleted;
    }
  }

  // Disable CiviCRM for this form
  if (!$vals['nid']) {
    webform_civicrm_disable($nid);
    drupal_set_message(t('CiviCRM processing for this form is now disabled.'));
  }
  // CiviCRM enabled
  else {
    webform_ensure_record($node);
    webform_civicrm_process_form_settings($vals);
    list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
    if (!$vals['toggle_message']) {
      $vals['message'] = '';
    }

    $i = 0;
    $lists = webform_civicrm_get_fields('lists');
    foreach ($vals as $key => $val) {
      if (substr($key, 0, 7) == 'civicrm') {
        ++$i;
        list($lobo, $c, $ent, $n, $table, $name) = explode('_', $key, 6);
        $aval = (array) $val;
        if (is_array($val)) {
          unset($val['create_civicrm_webform_element']);
        }
        // Add webform components (fields)
        if (!isset($enabled[$key]) && in_array('create_civicrm_webform_element', $aval, TRUE)) {
          $field = $fields[$table . '_' . $name];
          $field['nid'] = $nid;
          $field['form_key'] = $key;
          $field['weight'] = $i;
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

          // Retrieve option list
          if (isset($lists[$name])) {
            $field['extra']['items'] = webform_civicrm_field_options($key, 'component_insert', $vals['data']);
            if (!isset($field['extra']['aslist']) && empty($field['extra']['multiple'])) {
              $field['extra']['aslist'] = 1;
            }
            if (is_numeric($lists[$name])) {
              // Lookup defaults for custom fields
              $dao = &CRM_Core_DAO::executeQuery('SELECT value FROM civicrm_option_value
                WHERE is_default AND is_active AND option_group_id = ' . $lists[$name]);
              $field['value'] = '';
              while ($dao->fetch()) {
                $field['value'] .= ($field['value'] ? ',' : '') . $dao->value;
              }
            }
          }
          if ($table == 'other' || $field['type'] == 'hidden') {
            unset($field['extra']['description']);
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
                'weight' => $i,
              );
              webform_component_defaults($new_set);
              $enabled['civicrm_' . $c . '_contact_1_fieldset_fieldset'] = webform_component_insert($new_set);
            }
            $field['pid'] = $enabled['civicrm_' . $c . '_contact_1_fieldset_fieldset'];
          }
          // Save webform component
          webform_component_defaults($field);
          $fid = webform_component_insert($field);
          ++$created;
        }
        // Store exposed contact settings that don't create form elements
        elseif (isset($fields[$table . '_' . $name]['expose_list']) && !empty($val)) {
          $vals['data'][$ent][$c][$table][$n][$name] = $val;
        }
      }
    }
    if ($created == 1) {
      drupal_set_message(t('Created field: %name', array('%name' => $field['name'])));
    }
    elseif ($created) {
      drupal_set_message(t('Created !num new fields.', array('!num' => $created)));
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
  if ($deleted || $created) {
    // Make sure the updated webform is visible to anonymous users.
    cache_clear_all();
  }
  // Clear the entity cache if Entity Cache module is installed.
  if (module_exists('entitycache')) {
    cache_clear_all($nid, 'cache_entity_node');
  }
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
      drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_admin.css');

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
          if (empty($form_state[WEBFORM_CIVICRM_FAPI_INPUT])) {
            drupal_set_message(t('Press the button below to clone this contact. A new CiviCRM contact will be added to the form with all the settings for contact !num. All fields from within this fieldset will be cloned (be careful, that may include non-contact !num fields).', array('!num' => $i)), 'status', FALSE);
          }
        }
      }
      elseif (empty($form_state[WEBFORM_CIVICRM_FAPI_INPUT])) {
        // Clone a single CiviCRM field
        drupal_set_message(t('You are cloning a CiviCRM component. Refer to the Webform CiviCRM instructions for how to set the key if you want the new field to be processed by CiviCRM. You can also clone an entire contact by clicking the clone button by their fieldset.'), 'status', FALSE);
      }

      if ($table == 'address' && $key == 'state_province_id') {
        $form['validation']['maxlength']['#type'] = 'hidden';
        $form['validation']['maxlength']['#value'] = 5;
        $form['value']['#description'] = t('To set a default value, enter the state/province abbreviation in all caps.');
      }

      // Here's a much nicer interface for managing options than the standard textfields
      if (array_key_exists($key, webform_civicrm_get_fields('lists'))) {
        webform_civicrm_add_js('webform_civicrm_options.js');
        $element = $node->webform['components'][$form['cid']['#value']];
        $options = $sort = webform_civicrm_field_options($fid, 'component_edit', $node->webform_civicrm['data']);
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
        $form['value']['#value'] = '';
        $form['value']['#type'] = 'textfield';
        $form['value']['#disabled'] = TRUE;
        $form['#prefix'] = '<p>'
        . t('There are no configuration options for this hidden field. You can use it for post processing, for example to include a link to the CiviCRM contact in an email.')
        . '</p><p>'
        . t('The webform token for this field is:')
        . '<br /><strong>' . webform_civicrm_component_token($node, $form['cid']['#value'])
        . '</strong><br /><em>'
        . t('Note: this token will change if you move the field in or out of a fieldset.')
        . '</em></p>';
      }
      // Customize form for checksum element
      elseif ($key == 'cs') {
        $form['value']['#title'] = t('Checksum Lifespan');
        $form['value']['#required'] = TRUE;
        $form['value']['#type'] = 'textfield';
        $form['value']['#description'] = t('Enter the number of days before checksum will expire (non-integers are allowed, i.e. 1.5). Enter 0 to never expire.');
        $form['#validate'][] = 'webform_civicrm_cs_validate';
        $enabled = webform_civicrm_enabled_fields($node);
        $form['#prefix'] = '<p>'
        . t('This field will generate a checksum for contact !num after the form is submitted. You could use it to send a personalized link for anonymous users.', array('!num' => $i))
        . '</p><p>';
        if ($cid_field = webform_civicrm_aval($enabled, 'civicrm_' . $i . '_contact_1_contact_contact_id')) {
          $form['#prefix'] .= t('Example: to link users back to this form, place this line in a webform-generated email:')
          . '<br /><em>'
          . url('node/' . $node->nid, array('absolute' => TRUE))
          . '?cid' . $i . '=' . webform_civicrm_component_token($node, $cid_field)
          . '&amp;cs=' . webform_civicrm_component_token($node, $form['cid']['#value'])
          . '</em></p>';
        }
        else {
          $form['#prefix'] .= t('Consider enabling the hidden contact_id field - hashed links usually require this value.') . '</p>';
        }
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
      <a href="javascript:web_civi_select_reset(' . "'all', '#$name'" . ')">' . t('Select All') . '</a> |
      <a href="javascript:web_civi_select_reset(' . "'none', '#$name'" . ')">' . t('Select None') . '</a> |
      <a href="javascript:web_civi_select_reset(' . "'reset', '#$name'" . ')">' . t('Restore') . '</a>
    </div>',
  );
}


/**
 * Look-up the webform token for a field
 */
function webform_civicrm_component_token($node, $cid) {
  module_load_include('inc', 'webform', 'includes/webform.components');
  $component = $node->webform['components'][$cid];
  $parents = webform_component_parent_keys($node, $component);
  return '%value[' . implode('][', $parents) . ']';
}


/**
 * Validate checksum lifespan
 */
function webform_civicrm_cs_validate($form, &$form_state) {
  if (!is_numeric($form_state['values']['value']) || $form_state['values']['value'] < 0) {
    form_error($form['value'], t('Please enter a valid number of days.'));
  }
}
