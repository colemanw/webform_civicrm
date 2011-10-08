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
 */
function webform_civicrm_configure_form_handler($form, &$form_state, $node) {
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
        'prefix_known' => '',
        'prefix_unknown' => '',
        'message' => '',
        'confirm_subscription' => 1,
        'create_fieldsets' => 1,
        'new_contact_source' => '',
        'contact_matching' => 1,
      );
    }
  }
  // On rebuilding the form
  else {
    $settings = $form_state['values'];
    webform_civicrm_process_form_settings($settings);
  }
  $settings += webform_civicrm_enabled_fields($node, 'bool');
  $tokens = '<strong>' . t('Contact 1 Tokens:') . '</strong> [' . implode('], [', webform_civicrm_get_fields('tokens')) . '].';
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  $data = $settings['data'];
  $js = 'var webform_civicrm_relationship_data = ' . json_encode(webform_civicrm_get_relationship_types()) . ';';
  webform_civicrm_add_js($js, 'inline');

  // Sort fields by set
  foreach ($fields as $fid => $field) {
    list($group, $id) = explode('_', $fid, 2);
    $sets[$group]['fields'][$fid] = $field;
  }

  $form['#prefix'] = t('Use the options below to configure CiviCRM processing for this form.');
  $form['nid'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable CiviCRM Processing'),
    '#default_value' => !empty($settings['nid']),
    '#return_value' => $node->nid,
    '#description' => '<ul><li>' .
      t('CiviCRM contacts will be created or updated when users submit this webform.') . '</li><li>' .
      t('The form can be auto-filled for logged-in users, or people following this link from CiviMail:') . '<br />' .
      url('node/' . $node->nid, array('absolute' => TRUE)) . '?cid1={contact.contact_id}&{contact.checksum}</li><li>' .
      t('<a !link>Read the instructions</a> for more info.', array('!link' => 'href="/admin/help/webform_civicrm" target="_blank"')) . '</li></ul>',
  );
  $form['prefix'] = array(
    '#type' => 'fieldset',
    '#title' => t('Introduction Text'),
    '#description' => t('This text will appear at the top of the form. You may configure separate messages for known contacts (logged in users, or users following a hashed link from civimail) and unknown (anonymous) users.'),
    '#collapsible' => TRUE,
    '#collapsed' => !$settings['prefix_known'] && !$settings['prefix_unknown'],
  );
  $form['prefix']['prefix_known'] = array(
    '#type' => 'textarea',
    '#title' => t('Introduction text for known contacts'),
    '#default_value' => $settings['prefix_known'],
    '#description' => $tokens,
  );
  $form['prefix']['prefix_unknown'] = array(
    '#type' => 'textarea',
    '#title' => t('Introduction text for unknown contacts'),
    '#default_value' => $settings['prefix_unknown'],
    '#description' => t('No tokens available for unknown contacts.'),
  );
  $form['st_message'] = array(
    '#type' => 'fieldset',
    '#title' => t('Contact 1 Logout Message'),
    '#description' => t('Prompt for users who are logged in as, or following a hashed link for, someone else.'),
  );
  $form['st_message']['toggle_message'] = array(
    '#type' => 'checkbox',
    '#title' => t('Display message to known contacts?'),
    '#default_value' => (bool) $settings['message'],
  );
  $form['st_message']['message'] = array(
    '#type' => 'textfield',
    '#title' => t('Message text (displayed as a Drupal status message)'),
    '#default_value' => $settings['message'] ? $settings['message'] : t("You are viewing this form as [display name]. Please {click here if that's not you}."),
    '#size' => 100,
    '#maxlength' => 255,
    '#description' => t('Enclose your "not you" link text in curly brackets {like this}.') . '<p>' . $tokens . '</p>',
  );
  $form['act'] = array(
    '#type' => 'fieldset',
    '#title' => t('Create Activity'),
    '#description' => t('Create an activity for contacts when this form is submitted?'),
  );
  if ($case_types = webform_civicrm_get_options('case_type')) {
    $form['act']['case_type_id'] = array(
      '#type' => 'select',
      '#title' => t('CiviCase Type'),
      '#description' => t('Is this activity part of a case?'),
      '#options' => array(t('- no case -')) + $case_types,
    );
  }
  webform_civicrm_ajax_form_item($form, 'act', 'case_type_id', 'case');
  $param = NULL;
  if (isset($data['case'][1]['case'][1]['case_type_id'])) {
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
    );
    if (isset($data['case'][1]['case'][1]['status_id'])) {
      $form['act']['case']['case_fieldset']['case_status_id']['#default_value'] = $data['case'][1]['case'][1]['status_id'];
    }
    $form['act']['case']['case_fieldset']['case_medium_id'] = array(
      '#type' => 'select',
      '#title' => t('Medium'),
      '#description' => t('Medium for activities added to cases from this webform.'),
      '#options' => webform_civicrm_get_options('encounter_medium'),
    );
    if (isset($data['case'][1]['case'][1]['medium_id'])) {
      $form['act']['case']['case_fieldset']['case_medium_id']['#default_value'] = $data['case'][1]['case'][1]['medium_id'];
    }
    $form['act']['case']['case_fieldset']['case_subject'] = array(
      '#type' => 'textfield',
      '#title' => t('Case Subject'),
      '#description' => t('Optional subject for "open case" activity for new cases.'),
    );
    if (isset($data['case'][1]['case'][1]['subject'])) {
      $form['act']['case']['case_fieldset']['case_subject']['#default_value'] = $data['case'][1]['case'][1]['subject'];
    }
    else {
      $form['act']['case']['case_fieldset']['case_subject']['#default_value'] = check_plain($node->title);
    }
    $form['act']['case']['case_fieldset']['case_creator_id'] = array(
      '#type' => 'textfield',
      '#size' => 5,
      '#required' => TRUE,
      '#title' => t('Case Manager ID'),
      '#description' => t('Contact ID of "case manager" for newly created cases.'),
      '#default_value' => isset($data['case'][1]['case'][1]['creator_id']) ? $data['case'][1]['case'][1]['creator_id'] : webform_civicrm_user_cid(),
    );
  }
  $act_types = webform_civicrm_get_options('activity_type', $param);
  $form['act']['case']['activity_type_id'] = array(
    '#type' => 'select',
    '#title' => t('Activity Type'),
    '#options' => array(t('- no activity -')) + $act_types,
  );
  webform_civicrm_ajax_form_item($form, 'act.case', 'activity_type_id', 'activity_fields');
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
    );
    webform_civicrm_ajax_form_item($form, 'act.case.activity_fields', 'activity_assignee_group', 'assignee');
    // Activity assignee
    $fid = 'civicrm_1_activity_1_activity_assignee_contact_id';
    $form['act']['case']['activity_fields']['assignee'][$fid] = webform_civicrm_configure_form_item($fid, $fields['activity_assignee_contact_id'], $settings, t('- no one -'));
    if (!empty($config->activityAssigneeNotification)) {
      $form['act']['case']['activity_fields']['assignee'][$fid]['#description'] = t('A copy of this activity will be emailed to the assignee.');
    }
    $form['act']['case']['activity_fields']['existing_activity_status'] = array(
      '#type' => 'select',
      '#title' => t('Update Existing Activity'),
      '#description' => t('If a "@type" activity with this status already exists for contact 1, it will be updated. Otherwise a new one will be created.', array('@type' => $act_types[$settings['activity_type_id']])),
      '#options' => array(t('- always create new activity -')) + webform_civicrm_get_options('activity_status'),
    );
    $form['act']['case']['activity_fields']['activity_subject'] = array(
      '#type' => 'textfield',
      '#title' => t('Default Activity Subject'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#description' => t('You can override this default by allowing users to enter a subject (enable "Activity Subject" field below)'),
    );
    $form['act']['case']['activity_fields']['activity_link'] = array(
      '#type' => 'checkbox',
      '#title' => t('Include link to webform submission in activity details'),
      '#default_value' => !(empty($data['activity'][1]['add_link']) && isset($data['activity'][1]['add_link'])),
    );
    // Set default values
    if (isset($data['activity'][1]['activity'][1]['subject'])) {
      $form['act']['case']['activity_fields']['activity_subject']['#default_value'] = $data['activity'][1]['activity'][1]['subject'];
      $form['act']['case']['activity_fields']['existing_activity_status']['#default_value'] = $data['activity'][1]['existing_activity_status'];
      $form['act']['case']['activity_fields']['activity_assignee_group']['#default_value'] = $data['activity'][1]['assignee_group'];
    }
    else {
      $form['act']['case']['activity_fields']['activity_subject']['#default_value'] = check_plain($node->title);
    }
    foreach($data['contact'] as $n => $c) {
      $form['act']['case']['activity_fields']['activity_targets']['#options'][$n] = t('Contact !num', array('!num' => $n));
      if (!isset($settings['activity_subject']) || !empty($c['activity_target'])) {
        $form['act']['case']['activity_fields']['activity_targets']['#default_value'][] = $n;
      }
    }
    foreach ($sets as $sid => $set) {
      if ($set['entity_type'] == 'activity') {
        if (!empty($set['sub_types']) && !in_array($settings['activity_type_id'], $set['sub_types'])) {
          continue;
        }
        $form['act']['case']['activity_fields'][$sid] = array(
          '#type' => 'fieldset',
          '#collapsible' => TRUE,
          '#title' => $set['label'],
          '#attributes' => array('class' => webform_civicrm_fapi_class('web-civi-checkbox-set')),
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
  $nums = array();
  for ($i = 1; $i < 30; ++$i) {
    $nums[$i] = $i;
  }
  $form['number_of_contacts'] = array(
    '#type' => 'select',
    '#title' => t('Number of Contacts'),
    '#default_value' => $contacts = count($data['contact']),
    '#options' => $nums,
    '#description' => t('How many contacts would you like on this form?'),
  );
  $form['change_form_settings'] = array(
    '#type' => 'button',
    '#value' => t('Change Form Settings'),
    '#prefix' => '<div id="no-js-button-wrapper" class="messages warning">',
    '#suffix' => '<div>' . t('You have Javascript disabled, so you will need to click this button after changing any option to see the result.') . '</div></div>',
  );

  // Generate fieldset for each contact
  foreach ($data['contact'] as $n => $c) {
    $form['contact_' . $n] = array(
      '#type' => 'fieldset',
      '#title' => t('Contact !num', array('!num' => $n)),
      '#description' => $n > 1 ? NULL : t('Primary contact. Usually assumed to be the person filling out the form.'),
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
    $form['contact_' . $n]['contact_subtype_wrapper'][$fid]['#title'] .= ' ' .  $contact_types[$c['contact'][1]['contact_type']];
    $form['contact_' . $n]['contact_subtype_wrapper'][$fid]['#description'] = t('You may optionally select a more specific type for this contact. Choose "user select" to create a form element with these options.');
    webform_civicrm_ajax_form_item($form, 'contact_' . $n . '.contact_subtype_wrapper', $fid, 'contact_custom_wrapper');

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
        $path = 'contact_' . $n . '.contact_subtype_wrapper.contact_custom_wrapper';
      }
      elseif (!empty($set['contact_type']) || $sid == 'contact') {
        $pos = &$form['contact_' . $n]['contact_subtype_wrapper'];
        $path = 'contact_' . $n . '.contact_subtype_wrapper';
      }
      else {
        $pos = &$form['contact_' . $n];
        $path = 'contact_' . $n;
      }
      if (!empty($set['max_instances'])) {
        if (!isset($c['number_of_' . $sid])) {
          $c['number_of_' . $sid] = 0;
        }
        $numbers = array();
        for ($i = 0; $i <= $set['max_instances']; ++$i) {
          $numbers[$i] = $i;
        }
        $pos[$n . '_number_of_' . $sid] = array(
          '#type' => 'select',
          '#title' => t('Number of %type Fields', array('%type' => $sid == 'relationship' ? t('Relationship') : $set['label'])),
          '#default_value' => $c['number_of_' . $sid],
          '#options' => $numbers,
        );
        if ($hide) {
          $pos[$n . '_number_of_' . $sid]['#prefix'] = '<div class="hidden">';
          $pos[$n . '_number_of_' . $sid]['#suffix'] = '</div>';
        }
        webform_civicrm_ajax_form_item($form, $path, $n . '_number_of_' . $sid, $n . $sid . '_wrapper');

      }
      else {
        $c['number_of_' . $sid] = 1;
      }
      for ($i = 1; $i <= $c['number_of_' . $sid] && !$hide; ++$i) {
        $fieldset = array(
          '#type' => 'fieldset',
          '#title' => $set['label'],
          '#attributes' => array('class' => webform_civicrm_fapi_class('web-civi-checkbox-set')),
          'js_select' => webform_civicrm_js_select($n . $sid . $i . '_fieldset'),
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
  $form['options'] = array(
    '#type' => 'fieldset',
    '#title' => t('Additional Options'),
    '#attributes' => array('class' => webform_civicrm_fapi_class('web-civi-checkbox-set')),
    '#collapsible' => TRUE,
  );
  $form['options']['contact_matching'] = array(
    '#type' => 'checkbox',
    '#title' => t('Autofill Contact 1 with Current User'),
    '#default_value' => $settings['contact_matching'],
    '#description' => '<span id = "civi-contact-match-on">' . t('Enable this option if users will fill out this form on behalf of themselves. Disable it to allow a logged-in user to enter someone else as contact 1.') . '</span> <span id = "civi-contact-match-off">' . t('This option is disabled when contact 1 is not an individual.') . '</span>',
  );
  $form['options']['create_fieldsets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Create Fieldsets'),
    '#default_value' => (bool) $settings['create_fieldsets'],
    '#description' => t('Create a fieldset around each contact. (Nice if you have more than one contact on this form, but not required; all CiviCRM fields will be processed correctly regardless of how they are arranged).'),
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
  return $form;
}


/**
 * Build the $data array for webform settings; called while rebuilding or post-processing the configure form.
 */
function webform_civicrm_process_form_settings(&$vals) {
  $data = array('contact' => array());
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
    }
  }
  foreach ($vals as $key => $val) {
    if (strpos($key, '_number_of_') !== FALSE) {
      list($n, $k) = explode('_', $key, 2);
      if (isset($data['contact'][$n])) {
        $data['contact'][$n][$k] = $val;
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
  $vals['data'] = $data;
}


/**
 * Build a field item for the configure form
 */
function webform_civicrm_configure_form_item($fid, $field, $settings, $empty_option = '') {
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
      if ($empty_option) {
        $lists[$name] += array($empty_option);
      }
      $lists[$name] += webform_civicrm_field_options($fid, 'arr', $settings['data']);
    }
    $item = array(
      '#type' => 'select',
      '#title' => $field['name'],
      '#options' => $lists[$name],
      '#default_value' => $empty_option ? 0 : NULL,
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

  if (!empty($form_state['storage']['vals'])) {
    $form_state['values'] = $form_state['storage']['vals'];
  }
  unset($form_state['storage']);
  $vals = $form_state['values'];

  if ((empty($node->webform_civicrm) && !$vals['nid']) || $button == 'edit-cancel') {
    $form_state['rebuild'] = TRUE;
    return;
  }

  civicrm_initialize();
  $delete_me = $enabled = webform_civicrm_enabled_fields($node);
  $fields = webform_civicrm_get_fields();
  $sets = webform_civicrm_get_fields('sets');

  // Fields to delete
  foreach ($enabled as $key => $val) {
    if ((isset($vals[$key]) && $vals[$key] === 'create_civicrm_webform_element' && $vals['nid']) || strpos($key, 'fieldset') !== FALSE) {
      unset($delete_me[$key]);
    }
  }

  // Display a confirmation before deleting fields
  if ($delete_me && $button == 'edit-submit') {
    $msg = '<p>' . t('These existing fields are no longer needed for CiviCRM processing based on your new form settings.') . '</p><ul>';
    foreach ($delete_me as $key => $id) {
      list($lobo, $c, $ent, $n, $table, $name) = explode('_', $key, 6);
      $info = '';
      if ($ent == 'contact') {
        $info = '<em>' . t('Contact !num', array('!num' => $c));
      }
      if ($info && isset($sets[$table]['max_instances'])) {
        $info .= ' ' . $sets[$table]['label'] . ' ' . $n;
      }
      $info .= $info ? ':</em> ' : '';
      $msg .= '<li>' . $info . $node->webform['components'][$id]['name'] . '</li>';
    }
    $msg .= '</ul><p>' . t('Would you like them to be automatically removed from the webform? This is recommended unless you need webform-results reporting for these fields. They can still be deleted manually later if you choose not to remove them now.') . '</p><p><em>' . t('Note: Deleting webform components cannot be undone, and will result in the loss of webform-results data for those elements. Data in the CiviCRM database will not be affected.') . '</em></p>';
    $form_state['storage']['msg'] = $msg;
    $form_state['storage']['vals'] = $vals;
    $form_state['rebuild'] = TRUE;
    return;
  }

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
        // Create fieldsets
        if ($ent != 'activity' && !empty($vals['create_fieldsets'])) {
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
      // Handle exposed contact settings that don't create form elements
      elseif (isset($fields[$table . '_' . $name]['expose_list'])) {
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
 * Display message.
 * Block unknown users.
 * Set webform default values.
 */
function _webform_civicrm_webform_frontend_form_alter(&$form, &$form_state) {
  civicrm_initialize();
  require_once 'api/api.php';
  $node = $form['#node'];
  $settings = $node->webform_civicrm;
  $data = $settings['data'];
  $fields = webform_civicrm_get_fields();
  $id = $info = array();
  // If this is an edit op, use the original IDs to avoid confusion
  if (!empty($form['#submission'])) {
    if (isset($form['#submission']->civicrm)) {
      $id['cid'] = $form['#submission']->civicrm['contact_id'];
      $id['act'][1] = $form['#submission']->civicrm['activity_id'];
    }
  }
  // Do form alterations unless going back to a previous page
  elseif (!(!empty($form_state['clicked_button']['#id']) && $form_state['clicked_button']['#id'] == 'edit-previous')) {
    
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
        $form['#prefix'] = nl2br($settings['prefix_unknown']);
      }
      if ($settings['block_unknown_users']) {
        $form['#access'] = FALSE;
        drupal_set_message(t('Sorry, you do not have permission to access this form.'), 'warning');
        return;
      }
    }
    // Form alterations for known contacts
    $enabled = webform_civicrm_enabled_fields($node);
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
            foreach($con['relationship'] as $target => $rel) {
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
            // Load address custom data
            if ($field == 'address') {
              foreach ($result as &$address) {
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
    }
  
    if (!empty($id['cid'][1])) {
      if ($settings['prefix_known']) {
        $form['#prefix'] = nl2br(webform_civicrm_replace_tokens($settings['prefix_known'], $info['contact'][1]['contact'][1]));
      }
      if ($settings['message']) {
        webform_civicrm_set_message($settings['message'], $info['contact'][1]['contact'][1]);
      }
    }
  }

  // Store ids in session
  $_SESSION['webform_civicrm_data'][$node->nid] = $id;

  // Set default values and other attributes for CiviCRM form elements
  // Passing $submitted helps us avoid overwriting values that have been entered on a multi-step form
  $submitted = empty($form_state['values']['submitted']) ? array() : $form_state['values']['submitted'];
  webform_civicrm_fill_values($form['submitted'], $info, $fields, $submitted);

  // Add custom validation handler
  if (isset($form['actions']['submit'])) {
    $form['actions']['submit']['#validate'][] = 'webform_civicrm_form_validate';
  }
  if (isset($form['actions']['next'])) {
    $form['actions']['next']['#validate'][] = 'webform_civicrm_form_validate';
  }
}


/**
 * Alter back-end webform component edit forms.
 * Called by hook_form_alter() whenever editing a webform component.
 */
function _webform_civicrm_webform_component_form_alter(&$form) {
  // Is this a CiviCRM-enabled webform?
  $node = node_load($form['nid']['#value']);
  if (!empty($node->webform_civicrm)) {
    civicrm_initialize();
    if (!($pieces = webform_civicrm_explode_key($form['form_key']['#default_value']))) {
      return;
    }
    list($lobo, $i, $ent, $n, $table, $key) = $pieces;
    $fields = webform_civicrm_get_fields();
    // Is this component a CiviCRM field?
    if (isset($fields[$table . '_' . $key]) ||
      ($lobo == 'civicrm' && $table == 'fieldset')) {
      drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_style.css');

      // Prevent users from editing the form_key and breaking things
      $form['form_key']['#disabled'] = TRUE;
      $fid = $form['form_key']['#value'] = $form['form_key']['#default_value'];
      $form['form_key']['#description'] = t('Automatically set for use by CiviCRM processing.');

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
              '#default_value' => $options[$k],
            );
            if (!empty($options_selected[$k])) {
              $form['civicrm_options_fieldset']['civicrm_option_label_' . $k]['#default_value'] = $options_selected[$k];
            }
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
        if (empty($fields[$table . '_' . $key]['extra']['multiple'])) {
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
        $form['value']['#autocomplete_path'] = 'webform-civicrm/js/' . $form['nid']['#value'] . '/tags';
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
  }
}


/**
 * Build select all/none js links for a fieldset
 */
function webform_civicrm_js_select($name) {
  $name = str_replace('_', '-', $name);
  return array(WEBFORM_CIVICRM_MARKUP =>
    '<div class="web-civi-js-select" id="js-select-'.$name.'">
      <a href="javascript:web_civi_select_reset(\'checked\', ' . "'$name'" . ')">' . t('Select All') . '</a> |
      <a href="javascript:web_civi_select_reset(\'\', ' . "'$name'" . ')">' . t('Select None') . '</a> |
      <a href="javascript:web_civi_select_reset(\'reset\', ' . "'$name'" . ')">' . t('Restore') . '</a>
    </div>',
  );
}
