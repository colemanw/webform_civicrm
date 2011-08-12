<?php

/**
 * @file
 * Webform CiviCRM module's form building, altering, and processing functions.
 */

require_once 'webform_civicrm_utils.inc';

/**
 * Form to configure CiviCRM options for a Webform
 */
function webform_civicrm_configure_form($form, &$form_state, $node) {
  drupal_add_js(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_forms.js');
  drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_style.css', array('preprocess' => FALSE));
  civicrm_initialize();

  $fields = webform_civicrm_get_fields();
  $sets = webform_civicrm_get_fields('sets');
  $lists = webform_civicrm_get_fields('lists');
  if (!empty($form_state['values'])) {
    webform_civicrm_process_form_settings($form, $form_state);
    $enabled = $form_state['values'] + webform_civicrm_enabled_fields($node, 'bool');
    $settings = $form_state['values'];
  }
  else {
    $enabled = webform_civicrm_enabled_fields($node, 'bool');
    if (isset($node->webform_civicrm)) {
      $settings = $node->webform_civicrm;
    }
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
        'confirm_subscription' => TRUE,
        'create_fieldsets' => TRUE,
        'new_contact_source' => '',
      );
    }
  }
  $tokens = '<strong>' . t('Tokens Available:') . '</strong> [' . implode('], [', webform_civicrm_get_fields('tokens')) . '].';
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  $readme_link = array('!link' => 'href="/admin/help/webform_civicrm" target="_blank" class="popup-webform-civicrm-help"');
  $form = array('#prefix' => t('Use the options below to configure CiviCRM processing for this form.'));
  $contacts = $settings['data']['contact'];
  $data = $settings['data'];
  $js = 'var webform_civicrm_relationship_data = ' . json_encode(webform_civicrm_get_relationship_types()) . ';';
  drupal_add_js($js, array('type' => 'inline', 'scope' => JS_DEFAULT));

  // Sort fields by set
  foreach ($fields as $fid => $field) {
    list($group, $id) = explode('_', $fid, 2);
    $sets[$group]['fields'][$fid] = $field;
  }

  // AJAXify help text
  if (module_exists('popups')) {
    popups_add_popups(array('.popup-webform-civicrm-help' => array('width' => '90%')));
  }

  $form['nid'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable CiviCRM Processing'),
    '#default_value' => !empty($settings['nid']),
    '#return_value' => $node->nid,
    '#description' => '<ul><li>' .
      t('CiviCRM contacts will be created or updated when users submit this webform.') . '</li><li>' .
      t('Logged-in users will have their contact info already filled-in for them.') . '</li><li>' .
      t('The form will also be auto-filled for anonymous users if you send them this link using CiviMail:') . '<br />' .
      url('node/' . $node->nid) . '?cid1={contact.contact_id}&{contact.checksum}</li><li>' .
      t('<a !link>Read the instructions</a> for more info.', $readme_link) . '</li></ul>',
  );
  $form['prefix'] = array(
    '#type' => 'fieldset',
    '#title' => t('Introduction Text'),
    '#description' => t('This text will appear at the top of the form. You may configure separate messages for known contacts (logged in users, or users following a hashed link from civimail) and unknown (anonymous) users.'),
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
    '#title' => t('User Message'),
    '#description' => t("Useful if you don't want people messing up your database because they aren't the person you think they are (i.e. they are logged in as, or following a hashed link for, someone else)."),
  );
  $form['st_message']['toggle_message'] = array(
    '#type' => 'checkbox',
    '#title' => t('Display message to known contacts?'),
    '#default_value' => (bool) $settings['message'],
  );
  $form['st_message']['message'] = array(
    '#type' => 'textfield',
    '#title' => t('Message text (displayed as a Drupal status message)'),
    '#default_value' => $settings['message'],
    '#size' => 100,
    '#maxlength' => 255,
    '#description' => t('Enclose your "not you" link text in curly brackets {like this}.') . '<p>' . $tokens . '</p>',
  );
  if (!$settings['message']) {
    $form['st_message']['message']['#default_value'] = t("You are viewing this form as [display name]. Please {click here if that's not you}.");
  }
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
      '#ajax' => array(
        'callback' => 'webform_civicrm_configure_form_ajax',
        'pathstr' => 'act.case',
        'wrapper' => 'civicrm-ajax-case',
        'effect' => 'fade',
      ),
    );
  }
  $form['act']['case'] = array(
    '#prefix' => '<div class="civicrm-ajax-wrapper" id="civicrm-ajax-case">',
    '#type' => 'markup',
    '#suffix' => '</div>',
  );
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
    );
    if (isset($data['case'][1]['case'][1]['creator_id'])) {
      $form['act']['case']['case_fieldset']['case_creator_id']['#default_value'] = $data['case'][1]['case'][1]['creator_id'];
    }
    else {
      global $user;
      $result = webform_civicrm_api('uf_match', 'get', array('uf_id' => $user->uid));
      if (!empty($result['values'])) {
        $result = array_pop($result['values']);
        $form['act']['case']['case_fieldset']['case_creator_id']['#default_value'] = $result['contact_id'];
      }
    }
  }
  $act_types = webform_civicrm_get_options('activity_type', $param);
  $form['act']['case']['activity_type_id'] = array(
    '#type' => 'select',
    '#title' => t('Activity Type'),
    '#options' => array(t('- no activity -')) + $act_types,
    '#ajax' => array(
      'callback' => 'webform_civicrm_configure_form_ajax',
      'pathstr' => 'act.case.activity_fields',
      'wrapper' => 'civicrm-ajax-activity-fields',
      'effect' => 'fade',
    ),
  );
  $form['act']['case']['activity_fields'] = array(
    '#prefix' => '<div class="civicrm-ajax-wrapper" id="civicrm-ajax-activity-fields">',
    '#type' => 'markup',
    '#suffix' => '</div>',
  );
  if (isset($data['activity'][1]['activity'][1]['activity_type_id'])) {
    if (array_key_exists($data['activity'][1]['activity'][1]['activity_type_id'], $act_types)) {
      $form['act']['case']['activity_type_id']['#default_value'] = $settings['activity_type_id'] = $data['activity'][1]['activity'][1]['activity_type_id'];
      $act_status_ids = webform_civicrm_get_options('activity_status');
      $form['act']['case']['activity_fields']['existing_activity_status'] = array(
        '#type' => 'select',
        '#title' => t('Update Existing Activity'),
        '#description' => t('If a "@type" activity with this status already exists for this contact, it will be updated. Otherwise a new one will be created', array('@type' => $act_types[$settings['activity_type_id']])),
        '#options' => array(t('- always create new activity -')) + $act_status_ids,
      );
      if (isset($data['activity'][1]['existing_activity_status'])) {
        $form['act']['case']['activity_fields']['existing_activity_status']['#default_value'] = $data['activity'][1]['existing_activity_status'];
      }
      $form['act']['case']['activity_fields']['activity_subject'] = array(
        '#type' => 'textfield',
        '#title' => t('Default Activity Subject'),
        '#maxlength' => 255,
        '#required' => TRUE,
        '#description' => t('You can override this default by allowing users to enter a subject (enable "Activity Subject" field below)'),
      );
      if (isset($data['activity'][1]['activity'][1]['subject'])) {
        $form['act']['case']['activity_fields']['activity_subject']['#default_value'] = $data['activity'][1]['activity'][1]['subject'];
      }
      else {
        $form['act']['case']['activity_fields']['activity_subject']['#default_value'] = check_plain($node->title);
      }
      $form['act']['case']['activity_fields']['activity_status_id'] = array(
        '#type' => 'select',
        '#title' => t('Set Activity Status'),
        '#options' => $act_status_ids,
      );
      if (isset($data['activity'][1]['activity'][1]['status_id'])) {
        $form['act']['case']['activity_fields']['activity_status_id']['#default_value'] = $data['activity'][1]['activity'][1]['status_id'];
      }
      else {
        $form['act']['case']['activity_fields']['activity_status_id']['#default_value'] = 2;
      }
      $form['act']['case']['activity_fields']['activity_link'] = array(
        '#type' => 'checkbox',
        '#title' => t('Include link to webform submission in activity details?'),
        '#default_value' => !(empty($data['activity'][1]['add_link']) && isset($data['activity'][1]['add_link'])),
      );
      foreach ($sets as $sid => $set) {
        if ($set['entity_type'] == 'activity') {
          if (!empty($set['sub_types'])) {
            if (!in_array($settings['activity_type_id'], $set['sub_types'])) {
              continue;
            }
          }
          $form['act']['case']['activity_fields'][$sid] = array(
            '#type' => 'fieldset',
            '#collapsible' => TRUE,
            '#title' => $set['label'],
            '#attributes' => array('class' => array('web-civi-checkbox-set')),
          );
          foreach ($set['fields'] as $fid => $field) {
            $fid = 'civicrm_1_activity_1_' . $fid;
            $form['act']['case']['activity_fields'][$sid][$fid] = array(
              '#type' => 'checkbox',
              '#title' => $field['name'],
              '#description' => isset($field['extra']['description']) ? $field['extra']['description'] : '',
              '#return_value' => 'create_civicrm_webform_element',
              '#default_value' => !empty($enabled[$fid]),
            );
          }
        }
      }
    }
  }
  $nums = array();
  for ($i = 1; $i < 100; ++$i) {
    $nums[$i] = $i;
  }
  $form['number_of_contacts'] = array(
    '#type' => 'select',
    '#title' => t('Number of Contacts'),
    '#default_value' => count($contacts),
    '#options' => $nums,
    '#description' => t('How many contacts would you like on this form?'),
    '#attributes' => array('class' => array('number-of')),
  );
  $form['change_number_of_contacts'] = array(
    '#type' => 'submit',
    '#value' => t('Change Form Settings'),
    '#description' => t('You do not have Javascript enabled, so you will need to click this button after changing any option.'),
  );
  $form['contacts'] = array(
    '#prefix' => '<div class="civicrm-ajax-wrapper" id="civicrm-ajax-contact-sets">',
    '#type' => 'markup',
    '#suffix' => '</div>',
  );
  foreach ($contacts as $n => $c) {
    $form['contacts']['contact_' . $n] = array(
      '#type' => 'fieldset',
      '#title' => t('Contact !num', array('!num' => $n)),
    );
    $form['contacts']['contact_' . $n][$n . '_activity_target'] = array(
      '#type' => 'checkbox',
      '#title' => 'Activity Participant?',
      '#description' => 'Should this contact be listed as part of the civicrm activity?',
      '#default_value' => (bool) $c['activity_target'],
      '#prefix' => '<div class="web-civi-act-part">',
      '#suffix' => '</div>',
    );
    $form['contacts']['contact_' . $n][$n . '_contact_type'] = array(
      '#type' => 'select',
      '#title' => t('Contact Type'),
      '#default_value' => $c['contact'][1]['contact_type'],
      '#options' => $contact_types,
      '#ajax' => array(
        'callback' => 'webform_civicrm_configure_form_ajax',
        'pathstr' => 'contacts.contact_' . $n . '.contact_subtype_wrapper',
        'wrapper' => 'civicrm-ajax-contact-subtype-wrapper-' . $n,
        'effect' => 'fade',
      ),
    );
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper'] = array(
      '#prefix' => '<div class="civicrm-ajax-wrapper" id="civicrm-ajax-contact-subtype-wrapper-' . $n . '">',
      '#type' => 'markup',
      '#suffix' => '</div>',
    );
    if (!empty($sub_types[$c['contact'][1]['contact_type']])) {
      $subs = array(
        'create_civicrm_webform_element' => t('-user select-'),
        0 => $contact_types[$c['contact'][1]['contact_type']],
      ) + $sub_types[$c['contact'][1]['contact_type']];
    }
    else {
      $subs = array($contact_types[$c['contact'][1]['contact_type']]);
    }
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_' . $n . '_contact_1_contact_contact_sub_type'] = array(
      '#type' => 'select',
      '#title' => t('Type of !contact', array('!contact' => $contact_types[$c['contact'][1]['contact_type']])),
      '#options' => $subs,
      '#description' => t('You may optionally select a more specific type for this contact. Choose "user select" to create a form element with these options.'),
      '#default_value' => 0,
      '#ajax' => array(
        'callback' => 'webform_civicrm_configure_form_ajax',
        'pathstr' => 'contacts.contact_' . $n . '.contact_subtype_wrapper.contact_custom_wrapper',
        'wrapper' => 'civicrm-ajax-contact-custom-wrapper-' . $n,
        'effect' => 'fade',
      ),
    );
    if (empty($sub_types[$c['contact'][1]['contact_type']])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_' . $n . '_contact_1_contact_contact_sub_type']['#prefix'] = '<div class="hidden">';
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_' . $n . '_contact_1_contact_contact_sub_type']['#suffix'] = '</div>';
    }
    elseif (isset($enabled['civicrm_' . $n . '_contact_1_contact_contact_sub_type'])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_' . $n . '_contact_1_contact_contact_sub_type']['#default_value'] = $enabled['civicrm_' . $n . '_contact_1_contact_contact_sub_type'];
    }
    elseif (isset($c['contact'][1]['contact_sub_type'])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_' . $n . '_contact_1_contact_contact_sub_type']['#default_value'] = $c['contact'][1]['contact_sub_type'];
    }
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['contact_custom_wrapper'] = array(
      '#prefix' => '<div class="civicrm-ajax-wrapper" id="civicrm-ajax-contact-custom-wrapper-' . $n . '">',
      '#type' => 'markup',
      '#suffix' => '</div>',
    );
    foreach ($sets as $sid => $set) {
      $hide = FALSE;
      if ($set['entity_type'] != 'contact') {
        continue;
      }
      if ($sid == 'relationship') {
        if ($n == 1) {
          continue;
        }
        $set['max_instances'] = $n - 1;
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
        $pos = &$form['contacts']['contact_' . $n]['contact_subtype_wrapper']['contact_custom_wrapper'];
        $path = 'contacts.contact_' . $n . '.contact_subtype_wrapper.contact_custom_wrapper.';
      }
      elseif (!empty($set['contact_type']) || $sid == 'contact') {
        $pos = &$form['contacts']['contact_' . $n]['contact_subtype_wrapper'];
        $path = 'contacts.contact_' . $n . '.contact_subtype_wrapper.';
      }
      else {
        $pos = &$form['contacts']['contact_' . $n];
        $path = 'contacts.contact_' . $n . '.';
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
          '#attributes' => array('class' => array('number-of')),
          '#ajax' => array(
            'callback' => 'webform_civicrm_configure_form_ajax',
            'pathstr' => $path . $n . $sid . '_wrapper',
            'wrapper' => 'civicrm-ajax-contact-multi-wrapper-' . $n . $sid,
            'effect' => 'fade',
          ),
        );
        if ($hide) {
          $pos[$n . '_number_of_' . $sid]['#prefix'] = '<div class="hidden">';
          $pos[$n . '_number_of_' . $sid]['#suffix'] = '</div>';
        }
        $pos[$n . $sid . '_wrapper'] = array(
          '#prefix' => '<div class="civicrm-ajax-wrapper" id="civicrm-ajax-contact-multi-wrapper-' . $n . $sid . '">',
          '#type' => 'markup',
          '#suffix' => '</div>',
        );
      }
      else {
        $c['number_of_' . $sid] = 1;
      }
      for ($i = 1; $i <= $c['number_of_' . $sid] && !$hide; ++$i) {
        $fieldset = array(
          '#type' => 'fieldset',
          '#title' => $set['label'] . (((isset($set['max_instances']) && $set['max_instances'] > 1) || $sid == 'relationship') ? ' ' . $i : ''),
          '#attributes' => array('class' => array('web-civi-checkbox-set')),
        );
        foreach ($set['fields'] as $fid => $field) {
          if ($fid == 'contact_contact_sub_type' || ($fid == 'address_master_id' && count($contacts) == 1)) {
            continue;
          }
          list($ftable, $fname) = explode('_', $fid, 2);
          $fid = 'civicrm_' . $n . '_contact_' . $i . '_' . $fid;
          if (array_key_exists('contact_type', $field)) {
            if ($field['contact_type'] != $c['contact'][1]['contact_type']) {
              continue;
            }
          }
          if ($ftable == 'relationship') {
            $field['name'] = str_replace('#', $i, $field['name']);
          }
          else {
            $field['name'] = str_replace(' #', '', $field['name']);
          }
          // Create dropdown list
          if (!empty($field['expose_list'])) {
            // Populate option list as needed (always reset for relationship_type_id)
            if (!is_array($lists[$fname]) || $fname == 'relationship_type_id') {
              $lists[$fname] = array('create_civicrm_webform_element' => t('-user select-')) + webform_civicrm_field_options($fid, 'arr', $data);
            }
            $fieldset[$fid] = array(
              '#type' => 'select',
              '#title' => $field['name'],
              '#options' => $lists[$fname],
            );
            if (isset($enabled[$fid])) {
              $fieldset[$fid]['#default_value'] = $enabled[$fid];
            }
            elseif (isset($c[$ftable][$i][$fname])) {
              $fieldset[$fid]['#default_value'] = $c[$ftable][$i][$fname];
            }
            else {
              $options = array_keys($lists[$fname]);
              $fieldset[$fid]['#default_value'] = $options[1];
            }
          }
          // Create checkbox
          else {
            $fieldset[$fid] = array(
              '#type' => 'checkbox',
              '#title' => $field['name'],
              '#return_value' => 'create_civicrm_webform_element',
              '#default_value' => !empty($enabled[$fid]),
            );
          }
          if (isset($field['extra']['description'])) {
            $fieldset[$fid]['#description'] = $field['extra']['description'];
          }
        }
        if (array_key_exists('max_instances', $set)) {
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
    '#attributes' => array('class' => array('web-civi-checkbox-set')),
  );
  $form['options']['confirm_subscription'] = array(
    '#type' => 'checkbox',
    '#title' => t('Confirm Subscriptions'),
    '#default_value' => (bool) $settings['confirm_subscription'],
    '#description' => t('Recommended. Send a confirmation email before adding contacts to publicly subscribable mailing list groups.') . '<br />' . t('Your public mailing lists:') . ' <em>' . implode(', ', webform_civicrm_get_options('mailing_lists')) . '</em>',
  );
  $form['options']['block_unknown_users'] = array(
    '#type' => 'checkbox',
    '#title' => t('Block unknown users'),
    '#default_value' => !empty($settings['block_unknown_users']),
    '#description' => t('Only allow users to see this form if they are logged in or following a personalized link from CiviMail.'),
  );
  $form['options']['create_fieldsets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Create Fieldsets'),
    '#default_value' => (bool) $settings['create_fieldsets'],
    '#description' => t('Create a fieldset around each contact. (Nice if you have more than one contact on this form, but not required; all CiviCRM fields will be processed correctly regardless of what fieldset you place them in.'),
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
 * Build the $data array for webform settings; called during ajax callbacks and post-processing of the configure form.
 */
function webform_civicrm_process_form_settings($form, &$form_state) {
  $vals = $form_state['values'];
  $data = array('contact' => array());
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  for ($n = 1; $n <= $vals['number_of_contacts']; ++$n) {
    if (isset($vals[$n . '_contact_type'])) {
      $data['contact'][$n] = array(
        'activity_target' => $vals[$n . '_activity_target'],
      );
      $data['contact'][$n]['contact'][1]['contact_type'] = $vals[$n . '_contact_type'];
      $data['contact'][$n]['contact'][1]['contact_sub_type'] = NULL;
      if (array_key_exists('civicrm_' . $n . '_contact_1_contact_contact_sub_type', $vals)) {
        $sub_type = $vals['civicrm_' . $n . '_contact_1_contact_contact_sub_type'];
        if (array_key_exists($sub_type, $sub_types[$vals[$n . '_contact_type']])) {
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
      $data['activity'][1]['activity'][1]['status_id'] = $vals['activity_status_id'];
      $data['activity'][1]['add_link'] = $vals['activity_link'];
      $data['activity'][1]['existing_activity_status'] = $vals['existing_activity_status'];
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
  $form_state['values']['data'] = $data;
}


/**
 * Submission handler, saves CiviCRM options for a Webform node
 */
function webform_civicrm_configure_form_submit($form, &$form_state) {
  if ($form_state['clicked_button']['#id'] == 'edit-change-number-of-contacts') {
    $form_state['rebuild'] = TRUE;
    return;
  }
  civicrm_initialize();
  webform_civicrm_process_form_settings($form, $form_state);
  $vals = $form_state['values'];
  $node = node_load($nid = arg(1));
  $fields = webform_civicrm_get_fields();
  $delete_me = $enabled = webform_civicrm_enabled_fields($node);
  $sets = webform_civicrm_get_fields('sets');
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  $form_state['redirect'] = 'node/' . $nid . '/webform';
  unset($form_state['storage']);

  // Disable CiviCRM for this form
  if (isset($node->webform_civicrm) && !$vals['nid']) {
    db_delete('webform_civicrm_forms')
    ->condition('nid', $nid)
    ->execute();
    drupal_set_message(t('CiviCRM processing for this form is now disabled. You may delete any fields you no longer want.'));
  }
  // CiviCRM enabled
  elseif (!empty($vals['nid'])) {
    if (!$vals['toggle_message']) {
      $vals['message'] = '';
    }
    // Fields to delete
    foreach ($enabled as $key => $val) {
      if (array_key_exists($key, $vals)) {
        if ($vals[$key] === 'create_civicrm_webform_element') {
          unset($delete_me[$key]);
        }
      }
      // Don't delete fieldsets
      elseif (strpos($key, 'fieldset') !== FALSE) {
        unset($delete_me[$key]);
      }
    }
    // Delete fields TODO: There should be a confirmation or warning
    foreach ($delete_me as $id) {
      drupal_set_message(t('Deleted field: %name', array('%name' => $node->webform['components'][$id]['name'])));
      webform_component_delete($node, $node->webform['components'][$id]);
    }
    $i = 0;
    $lists = webform_civicrm_get_fields('lists');
    foreach ($vals as $key => $val) {
      if (substr($key, 0, 7) == 'civicrm') {
        ++$i;
        list($lobo, $c, $ent, $n, $table, $name) = explode('_', $key, 6);
        // Add webform components (fields)
        if (!array_key_exists($key, $enabled) && $val === 'create_civicrm_webform_element') {
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
          if (array_key_exists($name, $lists)) {
            $field['extra']['items'] = webform_civicrm_field_options($key, 'str', $vals['data']);
            if (!isset($field['extra']['aslist'])) {
              $field['extra']['aslist'] = 1;
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
          if (strpos($table, 'cg') === FALSE) {
            unset($field['extra']['description']);
          }
          // Create fieldsets
          if ($ent != 'activity' && !empty($vals['create_fieldsets'])) {
            if (array_key_exists('civicrm_' . $c . '_contact_1_fieldset_fieldset', $enabled)) {
              $set = $enabled['civicrm_' . $c . '_contact_1_fieldset_fieldset'];
            }
            else {
              $new_set = array(
                'nid' => $nid,
                'form_key' => 'civicrm_' . $c . '_contact_1_fieldset_fieldset',
                'type' => 'fieldset',
                'name' => t('Contact') . ' ' . $c,
                'pid' => 0,
                'weight' => $i,
                'extra' => array(),
              );
              $enabled['civicrm_' . $c . '_contact_1_fieldset_fieldset'] = $set = webform_component_insert($new_set);
            }
            $field['pid'] = $set;
          }
          // Save webform component
          $field['extra'] = isset($field['extra']) ? $field['extra'] : array();
          $fid = webform_component_insert($field);

          $new_fields = TRUE;
        }
        // Handle exposed contact settings that don't create form elements
        elseif (isset($fields[$table . '_' . $name]['expose_list'])) {
          $vals['data'][$ent][$c][$table][$n][$name] = $val;
        }
      }
    }
    if (!empty($new_fields)) {
      drupal_set_message(t('You may now customize your new fields however you wish.'));
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
  $node = $form['#node'];
  $settings = $node->webform_civicrm;
  $data = $settings['data'];
  global $user;
  $id = array();
  // If this is an edit op, use the original IDs to avoid confusion
  if (!empty($form['#submission'])) {
    $submission = $form['#submission'];
    $id['cid'] = $submission->civicrm['contact_id'];
    $id['act'][1] = $submission->civicrm['activity_id'];
  }
  else {
    require_once 'CRM/Contact/BAO/Contact/Permission.php';
    $count = count($data['contact']);
    for ($i = 1; $i <= $count; ++$i) {
      if (!empty($_GET['cid' . $i])) {
        if (is_numeric($cid = $_GET['cid' . $i])) {
          if (CRM_Contact_BAO_Contact_Permission::validateChecksumContact($cid, CRM_Core_DAO::$_nullObject)) {
            $id['cid'][$i] = $cid;
          }
        }
      }
    }
    // If user is logged in, look up the CID
    if (empty($id['cid'][1]) && $user->uid && $data['contact'][1]['contact'][1]['contact_type'] == 'individual') {
      $result = webform_civicrm_api('uf_match', 'get', array('uf_id' => $user->uid));
      if (!empty($result['values'])) {
        $result = array_pop($result['values']);
        $id['cid'][1] = $result['contact_id'];
      }
    }
    if (!empty($_GET['aid'])) {
      if (is_numeric($_GET['aid'])) {
        $id['act'][1] = $_GET['aid'];
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
        $id['act'][1] = webform_civicrm_activity_find($params);
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
    }
    return;
  }
  // Form alterations for known contacts
  require_once 'api/api.php';
  $enabled = webform_civicrm_enabled_fields($node);
  $contacts = $data['contact'];
  $info = array('contact' => array());
  foreach ($contacts as $c => $contact) {
    $info['contact'][$c] = array();
    if (!empty($id['cid'][$c])) {
      foreach (array('contact', 'address', 'email', 'phone', 'website') as $field) {
        if (!empty($contact['number_of_' . $field]) || $field == 'contact') {
          $params = array('contact_id' => $id['cid'][$c]);
          if ($field != 'contact') {
            $params['options'] = array('sort' => 'is_primary DESC');
          }
          $result = webform_civicrm_api($field, 'get', $params);
          if (!empty($result['values'])) {
            $result = array_merge(array(0), array_values($result['values']));
            unset($result[0]);
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
          $custom = webform_civicrm_get_custom($id['cid'][$c]);
          $info['contact'][$c] = $info['contact'][$c] + $custom;
          break;
        }
      }
      // Preferred communication method is not fetched by default by the api
      if (!empty($enabled['civicrm_' . $c . '_contact_1_contact_preferred_communication_method'])) {
        $result = webform_civicrm_api('contact', 'get', array('contact_id' => $id['cid'][$c], 'return.preferred_communication_method' => 1));
        if (!empty($result['values'][$id['cid'][$c]]['preferred_communication_method'])) {
          $info['contact'][$c]['contact'][1]['preferred_communication_method'] = $result['values'][$id['cid'][$c]]['preferred_communication_method'];
        }
      }
      // Set default values for group field
      if (array_key_exists('civicrm_' . $c . '_contact_1_other_groups', $enabled)) {
        $groups = webform_civicrm_api('group_contact', 'get', array('contact_id' => $id['cid'][$c]));
        $info['contact'][$c]['other'][1]['groups'] = array();
        foreach ($groups['values'] as $group) {
          $info['contact'][$c]['other'][1]['groups'][] = $group['group_id'];
        }
      }
    }
  }

  if (!empty($id['act'][1])) {
    $result = webform_civicrm_api('activity', 'get', array('activity_id' => $id['act'][1], 'return.target_contact_id' => 1, 'return.assignee_contact_id' => 1));
    if (isset($result['values'][$id['act'][1]])) {
      $act = $result['values'][$id['act'][1]];
      $valid = FALSE;
      // Verify that this activity is the right type and that our contacts have some involvement in it
      if ($act['activity_type_id'] == $data['activity'][1]['activity'][1]['activity_type_id']) {
        foreach ($id['cid'] as $cid) {
          if ($act['source_contact_id'] == $cid || in_array($cid, $act['target_contact_id']) || in_array($cid, $act['assignee_contact_id'])) {
            $valid = TRUE;
          }
        }
      }
      if ($valid) {
        $activity = array('activity' => array(1 => $result['values'][$id['act'][1]]));
        $custom = webform_civicrm_get_custom($id['act'][1], 'activity');
        $info['activity'] = array(1 => ($activity + $custom));
      }
      else {
        unset($id['act']);
      }
    }
  }

  if ($settings['prefix_known']) {
    $form['#prefix'] = nl2br(webform_civicrm_replace_tokens($settings['prefix_known'], $info['contact'][$c]['contact'][1]));
  }

  if (!empty($id)) {
    $_SESSION['webform_civicrm_data'][$node->nid] = $id;
  }
  elseif (isset($_SESSION['webform_civicrm_data'][$node->nid])) {
    unset($_SESSION['webform_civicrm_data'][$node->nid]);
    if (empty($_SESSION['webform_civicrm_data'])) {
      unset($_SESSION['webform_civicrm_data']);
    }
  }

  // Do not alter form default values if this is an edit op
  if (isset($submission)) {
    return;
  }

  // Set default values for CiviCRM form elements
  webform_civicrm_fill_values($form['submitted'], $info);

  if ($settings['message']) {
    webform_civicrm_set_message($settings['message'], $info['contact'][$c]['contact'][1]);
  }
}


/**
 * Alter back-end webform component edit forms.
 * Called by hook_form_alter() whenever editing a webform component.
 */
function _webform_civicrm_webform_component_form_alter(&$form) {
  // Is this a CiviCRM-enabled webform?
  if (db_query('SELECT nid FROM {webform_civicrm_forms} WHERE nid = :nid', array(':nid' => $form['nid']['#value']))->fetchField()) {
    civicrm_initialize();
    $fields = webform_civicrm_get_fields();
    if (!($pieces = webform_civicrm_explode_key($form['form_key']['#default_value']))) {
      return;
    }
    list($lobo, $i, $ent, $n, $table, $key) = $pieces;
    // Is this component a CiviCRM field?
    if (array_key_exists($table . '_' . $key, $fields) ||
        ($lobo == 'civicrm' && $table == 'fieldset')) {
      drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_style.css', array('preprocess' => FALSE));

      // Prevent users from editing the form_key and breaking things
      $form['form_key']['#disabled'] = TRUE;
      $fid = $form['form_key']['#value'] = $form['form_key']['#default_value'];
      $form['form_key']['#description'] = t('Automatically set for use by CiviCRM processing.');

      // Here's a much nicer interface for choosing options than the standard textfields
      if (array_key_exists($key, webform_civicrm_get_fields('lists'))) {
        $node = node_load($form['nid']['#value']);
        $element = $node->webform['components'][$form['cid']['#value']];
        $options = $sort = webform_civicrm_field_options($fid, 'arr', $node->webform_civicrm['data']);
        $defaults_selected = array();
        foreach (explode(',', $element['value']) as $v) {
          $defaults_selected[] = '_web_civi_option_selected_' . $v;
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
            '#type' => 'markup',
            '#markup' => '<span class="civicrm-option-name">' . $v . '</span>',
          );
        }
        if ($element['type'] != 'hidden') {
          $form['civicrm_options_fieldset']['#description'] = t('Drag the arrows to re-order these options. Click the "enabled" checkbox to show/remove an item from the form. Set the label as you want it to appear on the form. Check the "default" box for an option to be selected by default when a user views the form.');
          $options_selected = webform_civicrm_str2array($element['extra']['items']);
          // Sort weights. Unselected options will be at the bottom.
          $option_keys = $option_selected_keys = array();
          foreach ($options_selected as $k => $v) {
            if (array_key_exists($k, $options)) {
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
        // Auto set multi-value option for fields based on schema
        if (!empty($form['extra']['multiple'])) {
          $form['extra']['multiple']['#type'] = 'hidden';
          $form['extra']['multiple']['#value'] = !empty($fields[$table . '_' . $key]['extra']['multiple']);
          if (empty($fields[$table . '_' . $key]['extra']['multiple'])) {
            $form['civicrm_options_fieldset']['civicrm_defaults']['#type'] = 'radios';
            if ($element['value'] !== '') {
              $form['civicrm_options_fieldset']['civicrm_defaults']['#default_value'] = '_web_civi_option_selected_' . $element['value'];
            }
            else {
              unset($form['civicrm_options_fieldset']['civicrm_defaults']['#default_value']);
            }
          }
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
 * Theme function to format civicrm options form as a table
 */
function theme_webform_civicrm_options($variables) {
  $element = $variables['element'];
  $table = array(
    'rows' => array(),
    'attributes' => array('id' => 'civicrm-options-table'),
  );
  if (empty($element['civicrm_options'])) {
    $table['header'] = array(t('Item'), t('Selected'));
  }
  else {
    $table['header'] = array(t('Item'), t('Weight'), t('Enabled'), t('Label'), t('Default'));
    drupal_add_tabledrag('civicrm-options-table', 'order', 'self', 'civicrm-option-weight');
    drupal_add_js(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_options.js');
  }
  foreach (element_children($element['civicrm_defaults']) as $k) {
    $v = str_replace('_web_civi_option_selected_', '', $k);
    $row = array(drupal_render($element['civicrm_option_name_' . $v]));
    if (!empty($element['civicrm_options'])) {
      $element['civicrm_option_weight_' . $v]['#attributes']['class'] = array('civicrm-option-weight');
      $element['civicrm_options'][$k]['#attributes']['class'] = array('civicrm-enabled');
      $element['civicrm_option_label_' . $v]['#attributes']['class'] = array('civicrm-label');
      $row[] = drupal_render($element['civicrm_option_weight_' . $v]);
      $row[] = drupal_render($element['civicrm_options'][$k]);
      $row[] = drupal_render($element['civicrm_option_label_' . $v]);
    }
    $element['civicrm_defaults'][$k]['#attributes']['class'] = array('civicrm-default');
    $row[] = drupal_render($element['civicrm_defaults'][$k]);
    $table['rows'][] = array(
      'data' => $row,
      'class' => array('draggable'),
    );
  }
  drupal_render_children($element);
  return theme('table', $table);
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
