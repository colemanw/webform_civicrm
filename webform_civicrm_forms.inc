<?php

/**
 * @file
 * Webform CiviCRM module's form building, altering, and processing functions.
 */

require_once 'webform_civicrm_utils.inc';

/**
 * Form to configure CiviCRM options for a Webform
 */
function webform_civicrm_configure_form(&$form_state, $node) {
  drupal_add_js(drupal_get_path('module', 'webform_civicrm') .'/webform_civicrm_forms.js', 'module', 'header', FALSE);
  drupal_add_css(drupal_get_path('module', 'webform_civicrm') .'/webform_civicrm_style.css', 'module', 'all', FALSE);
  civicrm_initialize();

  $fields = webform_civicrm_get_fields();
  $sets = webform_civicrm_get_fields('sets');
  $lists = webform_civicrm_get_fields('lists');
  if (isset($form_state['values'])) {
    $enabled = array_merge(webform_civicrm_enabled_fields($node, 'bool'), $form_state['values']);
    $settings = $form_state['values'];
  }
  else {
    $enabled = webform_civicrm_enabled_fields($node, 'bool');
    if (isset($node->webform_civicrm)) {
	    $settings = $node->webform_civicrm;
    }
    else {
      $settings = array(
        'nid' => FALSE,
        'contacts' => array(
          1 => array(
            'contact_type' => 'Individual',
            'activity_target' => 1,
            'number_of_email' => 1,
          ),
        ),
        'activity_type_id' => 0,
        'prefix_known' => '',
        'prefix_unknown' => '',
        'message' => '',
        'confirm_subscription' => TRUE,
        'block_unknown_users' => FALSE,
        'create_fieldsets' => TRUE,
      );
    }
  }
  $tokens = '<strong>'. t('Tokens Available:') .'</strong> ['. implode('], [', webform_civicrm_get_fields('tokens')) .'].';
  $acts = webform_civicrm_get_options('activity_type', 'arr', t('- no activity -'));
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  $readme_link = array('!link' => 'href="/admin/help/webform_civicrm" target="_blank" class="popup-webform-civicrm-help"');
  $form = array('#prefix' => t('Use the options below to configure CiviCRM processing for this form.'));
  $contacts = $settings['contacts'];

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
      '#default_value' => (bool) $settings['nid'],
      '#return_value' => $node->nid,
      '#description' => '<ul><li>'.
      t('CiviCRM contacts will be created or updated when users submit this webform.') .'</li><li>'.
      t('Logged-in users will have their contact info already filled-in for them.') .'</li><li>'.
      t('The form will also be auto-filled for anonymous users if you send them this link using CiviMail:') .'<br />'.
      url('node/'. $node->nid) .'?cid1={contact.contact_id}&{contact.checksum}</li><li>'.
      t('<a !link>Read the instructions</a> for more info.', $readme_link) .'</li></ul>'
  );
  $form['prefix'] = array(
    '#type'  => 'fieldset',
    '#title' => t('Introduction Text'),
    '#description' => t('This text will appear at the top of the form. You may configure separate messages for known contacts (logged in users, or users following a hashed link from civimail) and unknown (anonymous) users.')
  );
  $form['prefix']['prefix_known'] = array(
    '#type' => 'textarea',
    '#title' => t('Introduction text for known contacts'),
    '#default_value' => $settings['prefix_known'],
    '#description' => $tokens
  );
  $form['prefix']['prefix_unknown'] = array(
    '#type' => 'textarea',
    '#title' => t('Introduction text for unknown contacts'),
    '#default_value' => $settings['prefix_unknown'],
    '#description' => t('No tokens available for unknown contacts.')
  );
  $form['st_message'] = array(
    '#type'  => 'fieldset',
    '#title' => t('User Message'),
    '#description' => t("Useful if you don't want people messing up your database because they aren't the person you think they are (i.e. they are logged in as, or following a hashed link for, someone else).")
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
    '#description' => t('Enclose your "not you" link text in curly brackets {like this}.') .'<p>'. $tokens .'</p>'
  );
  if (!$settings['message']) {
    $form['st_message']['message']['#default_value'] = t("You are viewing this form as [display name]. Please {click here if that's not you}.");
  }
  $form['act'] = array(
    '#type'  => 'fieldset',
    '#title' => t('Create Activity'),
    '#description' => t('Create an activity for contacts when this form is submitted?')
  );
  $form['act']['activity_type_id'] = array(
    '#type' => 'select',
    '#title' => t('Activity Type'),
    '#options' => $acts,
    '#default_value' => $settings['activity_type_id'],
    '#ahah' => array(
      'path' => 'webform-civicrm/js/' . $node->nid . '/civicrm_ahah.act.activity_fields',
      'wrapper' => 'civicrm-ahah-activity-fields',
      'effect' => 'fade',
    ),
  );
  $form['act']['activity_fields'] = array(
    '#prefix' => '<div class="civicrm-ahah-wrapper" id="civicrm-ahah-activity-fields">',
    '#value' => ' ',
    '#suffix' => '</div>',
  );
  if ($settings['activity_type_id']) {
    $form['act']['activity_fields']['activity_subject'] = array(
      '#type' => 'textfield',
      '#title' => t('Default Activity Subject'),
      '#maxlength' => 255,
      '#default_value' => $settings['activity_subject'],
      '#description' => t('You can override this default by allowing users to enter a subject (enable "Activity Subject" field below)'),
    );
    if (isset($settings['activity_status_id'])) {
      $form['act']['activity_fields']['activity_subject']['#default_value'] = $settings['activity_subject'];
    }
    else {
      $form['act']['activity_fields']['activity_subject']['#default_value'] = check_plain($node->title);
    }
    $form['act']['activity_fields']['activity_status_id'] = array(
      '#type' => 'select',
      '#title' => t('Activity Status'),
      '#options' => webform_civicrm_get_options('activity_status', 'arr'),
    );
    if (isset($settings['activity_status_id'])) {
      $form['act']['activity_fields']['activity_status_id']['#default_value'] = $settings['activity_status_id'];
    }
    else {
      $form['act']['activity_fields']['activity_status_id']['#default_value'] = 2;
    }
    $form['act']['activity_fields']['activity_link'] = array(
      '#type' => 'checkbox',
      '#title' => t('Include link to webform submission in activity details?'),
    );
    if (isset($settings['activity_link'])) {
      $form['act']['activity_fields']['activity_link']['#default_value'] = (bool) $settings['activity_link'];
    }
    else {
      $form['act']['activity_fields']['activity_link']['#default_value'] = TRUE;
    }
    foreach ($sets as $sid => $set) {
      if ($set['entity_type'] == 'Activity') {
        if ($set['sub_types']) {
          if (!in_array($settings['activity_type_id'], $set['sub_types'])) {
            continue;
          }
        }
        $form['act']['activity_fields'][$sid] = array(
          '#type' => 'fieldset',
          '#collapsible' => TRUE,
          '#title' => $set['label'],
          '#attributes' => array('class' => 'web-civi-checkbox-set'),
        );
        foreach ($set['fields'] as $fid => $field) {
          $fid = 'civicrm_1_1_' . $fid;
          $form['act']['activity_fields'][$sid][$fid] = array(
            '#type' => 'checkbox',
            '#title' => $field['name'],
            '#description' => $field['extra']['description'],
            '#return_value' => 'create_civicrm_webform_element',
          );
          if (isset($enabled[$fid])) {
            $form['act']['activity_fields'][$sid][$fid]['#default_value'] = (bool) $enabled[$fid];
          }
          if (isset($field['extra']['description'])) {
            $form['act']['activity_fields'][$sid][$fid]['#description'] = $field['extra']['description'];
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
  );
  $form['change_number_of_contacts'] = array(
    '#type' => 'submit',
    '#value' => t('Change Number of Contacts'),
  );
  $form['contacts'] = array(
    '#prefix' => '<div class="civicrm-ahah-wrapper" id="civicrm-ahah-contact-sets">',
    '#value' => ' ',
    '#suffix' => '</div>',
  );
  foreach ($contacts as $n => $c) {
    $form['contacts']['contact_' . $n] = array(
      '#type'  => 'fieldset',
      '#title' => t('Contact') .' '. $n,
    );
    $form['contacts']['contact_' . $n][$n .'_activity_target'] = array(
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
      '#default_value' => $c['contact_type'],
      '#options' => $contact_types,
      '#ahah' => array(
        'path' => 'webform-civicrm/js/' . $node->nid . '/civicrm_ahah.contacts.contact_'. $n .'.contact_subtype_wrapper',
        'wrapper' => 'civicrm-ahah-contact-subtype-wrapper-'. $n,
        'effect' => 'fade',
      ),
    );
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper'] = array(
      '#prefix' => '<div class="civicrm-ahah-wrapper" id="civicrm-ahah-contact-subtype-wrapper-'. $n .'">',
      '#value' => ' ',
      '#suffix' => '</div>',
    );
    if (!empty($sub_types[$c['contact_type']])) {
      $subs = array_merge(array(t('-none-'), 'create_civicrm_webform_element' => t('-user select-')), $sub_types[$c['contact_type']]);
    }
    else {
      $subs = array(t('-none-'));
    }
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_1_contact_contact_sub_type'] = array(
      '#type' => 'select',
      '#title' => t('Specific Type'),
      '#options' => $subs,
      '#description' => t('You may optionally select a more specific type for this contact. Choose "user select" to create a form element with these options.'),
      '#ahah' => array(
        'path' => 'webform-civicrm/js/' . $node->nid . '/civicrm_ahah.contacts.contact_'. $n .'.contact_subtype_wrapper.contact_custom_wrapper',
        'wrapper' => 'civicrm-ahah-contact-custom-wrapper-'. $n,
        'effect' => 'fade',
      ),
    );
    if (empty($sub_types[$c['contact_type']])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_1_contact_contact_sub_type']['#prefix'] = '<div class="hidden">';
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_1_contact_contact_sub_type']['#suffix'] = '</div>';
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_1_contact_contact_sub_type']['#default_value'] = 0;
    }
    elseif (isset($c['contact_sub_type'])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_1_contact_contact_sub_type']['#default_value'] = $c['contact_sub_type'];
    }
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['contact_custom_wrapper'] = array(
      '#prefix' => '<div class="civicrm-ahah-wrapper" id="civicrm-ahah-contact-custom-wrapper-'. $n .'">',
      '#value' => ' ',
      '#suffix' => '</div>',
    );
    foreach ($sets as $sid => $set) {
      $hide = FALSE;
      if ($set['entity_type'] != 'Contact') {
        continue;
      }
      if ($set['contact_type'] && $set['contact_type'] != $c['contact_type']) {
        $hide = TRUE;
      }
      if (isset($set['sub_types'])) {
        if (!in_array($c['contact_sub_type'], $set['sub_types'])) {
          $hide = TRUE;
        }
        $pos =& $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['contact_custom_wrapper'];
        $path = 'webform-civicrm/js/' . $node->nid . '/civicrm_ahah.contacts.contact_'. $n .'.contact_subtype_wrapper.contact_custom_wrapper.';
      }
      elseif ($set['contact_type'] || $sid == 'contact') {
        $pos =& $form['contacts']['contact_' . $n]['contact_subtype_wrapper'];
        $path = 'webform-civicrm/js/' . $node->nid . '/civicrm_ahah.contacts.contact_'. $n .'.contact_subtype_wrapper.';
      }
      else {
        $pos =& $form['contacts']['contact_' . $n];
        $path = 'webform-civicrm/js/' . $node->nid . '/civicrm_ahah.contacts.contact_'. $n .'.';
      }
      if ($set['max_instances']) {
        $numbers = array();
        for ($i = 0; $i <= $set['max_instances']; ++$i) {
          $numbers[$i] = $i;
        }
        $pos[$n. '_number_of_' .$sid] = array(
          '#type' => 'select',
          '#title' => t('Number of %type Fields', array('%type' => $set['label'])),
          '#default_value' => $c['number_of_'. $sid],
          '#options' => $numbers,
          '#ahah' => array(
            'path' => $path . $n . $sid .'_wrapper',
            'wrapper' => 'civicrm-ahah-contact-multi-wrapper-'. $n . $sid,
            'effect' => 'fade',
          ),
        );
        if ($hide) {
          $pos[$n. '_number_of_' .$sid]['#prefix'] = '<div class="hidden">';
          $pos[$n. '_number_of_' .$sid]['#suffix'] = '</div>';
        }
        $pos[$n . $sid .'_wrapper'] = array(
          '#prefix' => '<div class="civicrm-ahah-wrapper" id="civicrm-ahah-contact-multi-wrapper-'. $n . $sid .'">',
          '#value' => ' ',
          '#suffix' => '</div>',
        );
      }
      else {
        $c['number_of_'. $sid] = 1;
      }
      for ($i = 1; $i <= $c['number_of_'. $sid] && !$hide; ++$i) {
        $fieldset = array(
          '#type' => 'fieldset',
          '#title' => $set['label'] . ($set['max_instances'] > 1 ? ' '. $i : ''),
          '#attributes' => array('class' => 'web-civi-checkbox-set'),
        );
        foreach ($set['fields'] as $fid => $field) {
          if ($fid == 'contact_contact_sub_type') {
            continue;
          }
          $fid = 'civicrm_'. $n .'_'. $i .'_' . $fid;
          if (array_key_exists('contact_type', $field)) {
            if($field['contact_type'] != $c['contact_type']) {
              continue;
            }
          }
          // Create dropdown list
          if (isset($field['expose_list'])) {
            $field_parts = explode('_', $fid, 5);
            $fname = $field_parts[4];
	          // Populate option list as needed
	          if (!is_array($lists[$fname])) {
	            $lists[$fname] = array_merge(array('create_civicrm_webform_element' => t('-user select-')), webform_civicrm_get_options($lists[$fname], 'arr'));
	          }
            $fieldset[$fid] = array(
	            '#type' => 'select',
	            '#title' => $field['name'],
	            '#options' => $lists[$fname],
	          );
	          if (isset($enabled[$fid])) {
	            $fieldset[$fid]['#default_value'] = $enabled[$fid];
	          }
          }
          // Create checkbox
          else {
	          $fieldset[$fid] = array(
	            '#type' => 'checkbox',
	            '#title' => $field['name'],
	            '#return_value' => 'create_civicrm_webform_element',
	          );
	          if (isset($enabled[$fid])) {
	            $fieldset[$fid]['#default_value'] = (bool) $enabled[$fid];
	          }
          }
          if (isset($field['extra']['description'])) {
            $fieldset[$fid]['#description'] = $field['extra']['description'];
          }
        }
        if (array_key_exists('max_instances', $set)) {
          $pos[$n . $sid .'_wrapper'][$n . $sid . $i . '_fieldset'] = $fieldset;
        }
        else {
          $pos[$n . $sid . $i . '_fieldset'] = $fieldset;
        }
      }
    }
  }
  $form['options'] = array(
    '#type'  => 'fieldset',
    '#title' => t('Additional Options'),
    '#attributes' => array('class' => 'web-civi-checkbox-set'),
  );
  $form['options']['confirm_subscription'] = array(
    '#type' => 'checkbox',
    '#title' => t('Confirm Subscriptions'),
    '#default_value' => (bool) $settings['confirm_subscription'],
    '#description' => t('Recommended. Send a confirmation email before adding contacts to publicly subscribable mailing list groups.') .'<br />'. t('Your public mailing lists:') .' <em>'. implode(', ', webform_civicrm_get_options('mailing_lists', 'arr')) .'</em>'
  );
  $form['options']['block_unknown_users'] = array(
    '#type' => 'checkbox',
    '#title' => t('Block unknown users'),
    '#default_value' => (bool) $settings['block_unknown_users'],
    '#description' => t('Only allow users to see this form if they are logged in or following a personalized link from CiviMail.')
  );
  $form['options']['create_fieldsets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Create Fieldsets'),
    '#default_value' => (bool) $settings['create_fieldsets'],
    '#description' => t('Create a fieldset around each contact. (Nice if you have more than one contact on this form, but not required; all CiviCRM fields will be processed correctly regardless of what fieldset they are in.')
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save Settings'),
  );
  return $form;
}

/**
 * Submission handler, saves CiviCRM options for a Webform node
 */
function webform_civicrm_configure_form_submit($form, &$form_state) {
  $vals =& $form_state['values'];
  if ($form_state['clicked_button']['#id'] != 'edit-change-number-of-contacts'  || !empty($form_state['ahah_submission'])) {
    $vals['number_of_contacts'] = $form['number_of_contacts']['#default_value'];
  }
  $contacts = array();
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  for ($n = 1; $n <= $vals['number_of_contacts']; ++$n) {
    if (isset($vals[$n . '_contact_type'])) {
      $contacts[$n] = array(
        'contact_type' => $vals[$n . '_contact_type'],
        'activity_target' => $vals[$n . '_activity_target'],
      );
      if (array_key_exists('civicrm_'. $n .'_1_contact_contact_sub_type', $vals)) {
	      if (array_key_exists($vals['civicrm_'. $n .'_1_contact_contact_sub_type'], $sub_types[$vals[$n . '_contact_type']])) {
	        $contacts[$n]['contact_sub_type'] = $vals['civicrm_'. $n .'_1_contact_contact_sub_type'];
	      }
      }
      foreach ($vals as $k => $v) {
        if (strpos($k, $n. '_number_of_') !== FALSE) {
          $contacts[$n][str_replace($n. '_', '', $k)] = $v;
        }
      }
    }
    else {
      $contacts[$n] = array(
        'contact_type' => 'Individual',
        'activity_target' => 1,
      );
    }
  }
  $vals['contacts'] = $contacts;
  if ($form_state['clicked_button']['#id'] == 'edit-change-number-of-contacts' || !empty($form_state['ahah_submission'])) {
    $form_state['rebuild'] = TRUE;
    return;
  }
  civicrm_initialize();
  $node = node_load($nid = arg(1));
  $fields = webform_civicrm_get_fields();
  $delete_me = $enabled = webform_civicrm_enabled_fields($node);
  $sets = webform_civicrm_get_fields('sets');
  $form_state['redirect'] = 'node/'. $nid .'/webform';
  unset($form_state['storage']);

  // Disable CiviCRM for this form
  if (isset($node->webform_civicrm) && !$vals['nid']) {
    db_query('DELETE FROM {webform_civicrm_forms} WHERE nid = %d', $nid);
    drupal_set_message(t('CiviCRM processing for this form is now disabled. You may delete any fields you no longer want.'));
  }
  // CiviCRM enabled
  else {
    if (!$vals['toggle_message']) {
      $vals['message'] = '';
    }
    // Write/update record
    if (!$node->webform_civicrm) {
      drupal_write_record('webform_civicrm_forms', $vals);
      drupal_set_message(t('CiviCRM processing for this form is now enabled.'));
    }
    else {
      drupal_write_record('webform_civicrm_forms', $vals, 'nid');
      drupal_set_message(t('Your CiviCRM form settings have been updated.'));
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
    // Delete fields TODO: There should be a confirmation
    foreach($delete_me as $key => $val) {
      list($lobo, $c, $n, $name) = explode('_', $key, 4);
      $field = $fields[$name];
      $field['cid'] = $val;
      webform_component_delete($node, $field);
    }
    $new_fields = FALSE;
    // Add webform components (fields)
    $i = 0;
    $lists = webform_civicrm_get_fields('lists');
    foreach ($vals as $key => $val) {
      if (substr($key, 0, 7) == 'civicrm') {
        ++$i;
      }
      else {
        continue;
      }
      if (!array_key_exists($key, $enabled) && $val === 'create_civicrm_webform_element') {
        list($lobo, $c, $n, $table, $name) = explode('_', $key, 5);
        $field = $fields[$table .'_'. $name];
        $field['nid'] = $nid;
        $field['form_key'] = $key;
        $field['weight'] = $i;
        $field['pid'] = 0;
        if ($n > 1) {
          $field['name'] .= ' ' . $n;
        }

        if (($field['type'] == 'textfield' || $field['type'] == 'email') && !isset($field['extra']['width'])) {
          $field['extra']['width'] = 20;
        }

        // Set default country
        if ($name == 'country_id') {
          $config = CRM_Core_Config::singleton();
          $field['value'] = $config->defaultContactCountry;
        }
        // Retrieve option list
        if (array_key_exists($name, $lists)) {
          $field['extra']['items'] = webform_civicrm_get_options($lists[$name]);
          if (!isset($field['extra']['aslist'])) {
            $field['extra']['aslist'] = 1;
          }
        }
        if (strpos($table, 'cg') === FALSE) {
          unset($field['extra']['description']);
        }
        // Create fieldsets
        if ($sets[$table]['entity_type'] != 'Activity' && $vals['create_fieldsets']) {
          if (array_key_exists('civicrm_contact_'.$c.'_fieldset', $enabled)) {
            $set = $enabled['civicrm_contact_'.$c.'_fieldset'];
          }
          else {
            $new_set = array(
              'nid' => $nid,
              'form_key' => 'civicrm_contact_'.$c.'_fieldset',
              'type' => 'fieldset',
              'name' => t('Contact') .' '. $c,
              'pid' => 0,
              'weight' => $i,
            );
            $enabled['civicrm_contact_'.$c.'_fieldset'] = $set = webform_component_insert($new_set);
          }
          $field['pid'] = $set;
        }
        // Create webform component
        $fid = webform_component_insert($field);

        $new_fields = TRUE;
      }
    }
    if ($new_fields) {
      drupal_set_message(t('You may now customize your new fields however you wish.'));
    }
  }
}

/**
 * Custom Processing for CiviCRM groups form
 */
function webform_civicrm_process_group_selection($form, &$form_state) {
  if ($form_state['values']['civicrm_groups_fieldset']['civicrm_groups']) {
    civicrm_initialize();
    $groups = webform_civicrm_get_options('groups', 'arr');
    $items = '';
    foreach ($form_state['values']['civicrm_groups_fieldset']['civicrm_groups'] as $gid) {
      if ($gid) {
        if ($items) {
          $items .= "\n";
        }
        $items .= $gid .'|'. $groups[$gid];
      }
    }
    $form_state['values']['extra']['items'] = $items;
  }
  $form_state['values']['value'] = '';
  foreach ($form_state['values']['civicrm_groups_fieldset']['civicrm_group_defaults'] as $gid) {
    if (!$gid) {
      continue;
    }
    if ($form_state['values']['value']) {
      $form_state['values']['value'] .= ',';
    }
    $form_state['values']['value'] .= $gid;
  }
}


/**
 * Recursively walk through form array and set default values for fields based on CiviCRM contact data
 * Called by _webform_civicrm_webform_frontend_form_alter() when webform is being viewed by a known contact
 * @Param $elements: FAPI array
 * @Param $contact: Array of CiviCRM contact data
 * Matching depends on form keys being the same as contact array keys
 */
function webform_civicrm_fill_values(&$elements, $contact) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  foreach ($elements as $eid => &$element) {
    if (substr($eid, 0, 1) == '#' || !is_array($element)) {
      continue;
    }
    if ($element['#type'] == 'fieldset') {
      // Recurse through fieldsets
      webform_civicrm_fill_values($element, $contact);
      continue;
    }
    if (isset($contact[$eid])) {
      if ($element['#type'] == 'date') {
        $dates = explode('-', $contact[$eid]);
        $contact[$eid] = array('year' => $dates[0], 'month' => intval($dates[1]), 'day' => intval($dates[2]));
      }
      elseif (($element['#type'] == 'checkboxes' || $element['#multiple']) && !is_array($contact[$eid])) {
        $contact[$eid] = explode($sp, trim($contact[$eid], $sp));
      }
      if ($element['#type'] == 'hidden') {
        $element['#value'] = $contact[$eid];
      }
      $element['#default_value'] = $contact[$eid];
    }
  }
}


/**
 * Called from hook_webform_submission_insert()
 * Process CiviCRM contact.
 * Create activity record.
 */
function webform_civicrm_process_submission($node, $submission) {
  if ($cid = webform_civicrm_contact_match($node, $submission)){
    $act = 0;
    if ($type = $node->webform_civicrm['activity_type_id']) {
      require_once 'api/v2/Activity.php';
      $act_params = array(
        'activity_type_id' => $type,
        'source_contact_id' => $cid,
        'target_contact_id' => $cid,
        'subject' => $node->webform_civicrm['activity_subject'],
        'status_id' => 2,
        'activity_date_time' => date('Ymdhis'),
        'details' => l(t('View Webform Submission'), 'node/'. $node->nid .'/submission/'. $submission->sid)
      );
      $result = civicrm_activity_create($act_params);
      $act = $result['id'];
    }
    db_query('INSERT INTO {webform_civicrm_submissions} SET sid = %d, contact_id = %d, activity_id = %d', $submission->sid, $cid, $act);
  }
  else {
    watchdog('webform_civicrm', 'An error occured when trying to create a new contact. The contact was not created.', NULL, WATCHDOG_ERROR, l($node->title .': '. t('Submission #') . $submission->sid, 'node/'. $node->nid .'/submission/'. $submission->sid));
  }
}


/**
 * Alter back-end webform component edit forms.
 * Called by hook_form_alter() whenever editing a webform component.
 */
function _webform_civicrm_webform_component_form_alter(&$form) {
  // Is this a CiviCRM-enabled webform?
  if (db_result(db_query('SELECT nid FROM {webform_civicrm_forms} WHERE nid = %d', $form['nid']['#value']))) {
    civicrm_initialize();
    $fields = webform_civicrm_get_fields();
    list($lobo, $i, $n, $table, $key) = explode('_', $form['form_key']['#default_value'], 5);
    // Is this component a CiviCRM field?
    if (array_key_exists($table .'_'. $key, $fields) || 
        ($lobo == 'civicrm' && $i == 'contact' && $table == 'fieldset')) {
      drupal_add_css(drupal_get_path('module', 'webform_civicrm') .'/webform_civicrm_style.css', 'module', 'all', FALSE);

      // Prevent users from editing the form_key and breaking things
      $form['form_key']['#disabled'] = TRUE;
      $form['form_key']['#value'] = $form['form_key']['#default_value'];
      $form['form_key']['#description'] = t('Automatically set for use by CiviCRM processing.');

      // Adds the ability to reload option lists via AJAX (also works without JS)
      $lists = webform_civicrm_get_fields('lists');
      if (array_key_exists($key, $lists)) {
        unset($form['extra']['options_source']);
        $complete_msg = t('The options have been reloaded.');
        $form['extra']['items']['#description'] = t("These options were automatically selected from the CiviCRM database. You may change their order, or delete unwanted options as you see fit. You may also change the labels (text after the |) to be more descriptive. However, adding new items here that are <em>not</em> in CiviCRM won't work. Instead, create your new options in CiviCRM, then click here to refresh this list:");
        if ($_GET['reset']) {
          $form['extra']['items']['#description'] .= '<div style="color:green; font-weight:bold; margin-top:0.5em;">'. $complete_msg .'</div>';
          $form['extra']['items']['#default_value'] = webform_civicrm_get_options($lists[$key]);

        }
        else {
          $form['extra']['items']['#description'] .= '<div id="civicrm-ajax" style="color:green; font-weight:bold; margin-top:0.5em;">'. l(t('Reload options'), $_GET['q'], array('query' => 'reset=1', 'attributes' => array('style' => 'color:blue;',
          'onclick' => "$(this).addClass('views-throbbing');
            $.ajax({
              url: '/webform-civicrm/js/". arg(1) ."/$key',
              success: function(data) {
                $('#edit-extra-items').val(data);
                $('#civicrm-ajax').html('$complete_msg');
              }
            }); return false;"))) .
          '</div>';
        }
      }
      // Simplify form for hidden fields
      elseif ($key == 'contact_id' || $key == 'external_identifier') {
        $form['value']['#type'] = 'hidden';
        $form['value']['#value'] = 0;
        $form['#prefix'] = '<p>'. t('There are no configuration options for this hidden field. You can use it for post processing, for example to include a link to the CiviCRM contact in an email.') .'</p>';
      }

      // Special options for CiviCRM groups
      if ($key == 'groups' || $key == 'groups_hidden') {
        if ($key == 'groups') {
          $form['extra']['items']['#type'] = 'hidden';
          $form['extra']['items']['#required'] = FALSE;
          unset($form['extra']['options_source']);
          $selected = array();
          $defaults = explode("\n", trim($form['extra']['items']['#default_value']));
          foreach ($defaults as $d) {
            list($k, $v) = explode('|', $d);
            $selected[] = trim($k);
          }
          $form['extra']['items']['#default_value'] = '';
        }
        $groups = webform_civicrm_get_options('groups', 'arr');
        $form['value']['#type'] = 'hidden';
        $defaults_selected = explode(',', $form['value']['#default_value']);

        $form['civicrm_groups_fieldset'] = array(
          '#type'  => 'fieldset',
          '#title' => t('Groups'),
          '#description' => t('Which group(s) is the user allowed to join on this form? (Often used for letting people subscribe to mailing lists)'),
        );
        if ($key == 'groups') {
          $form['civicrm_groups_fieldset']['civicrm_groups'] = array(
            '#title' => t('Allowed Groups'),
            '#type'  => 'checkboxes',
            '#required' => TRUE,
            '#options' => $groups,
            '#default_value' => $selected,
            '#prefix' => '<div class="web-civi-3-col">',
            '#suffix' => '</div>',
          );
        }
        $form['civicrm_groups_fieldset']['civicrm_group_defaults'] = array(
          '#title' => t('Checked by Default on Webform?'),
          '#type'  => 'checkboxes',
          '#options' => $groups,
          '#default_value' => $defaults_selected,
        );
        if ($key == 'groups') {
          $form['civicrm_groups_fieldset']['civicrm_group_defaults']['#prefix'] = '<div class="web-civi-3-col">';
          $form['civicrm_groups_fieldset']['civicrm_group_defaults']['#suffix'] = '</div>';
        }
        else {
          $form['civicrm_groups_fieldset']['#description'] = t('To which group(s) should users be automatically added when submitting this form?');
          $form['civicrm_groups_fieldset']['civicrm_group_defaults']['#title'] = t('Add to Group');
        }
        array_unshift($form['#submit'], 'webform_civicrm_process_group_selection');
      }
      // Autocomplete widget for selecting tags
      elseif ($key == 'tags') {
        $form['value']['#title'] = t('Tags');
        $form['value']['#description'] = t('Enter a comma-separated list of tags to add to contacts who submit this webform.') .'<br />'. t('Use the autocomplete to select existing tags, or you may add new ones.');
        $form['value']['#type'] = 'textfield';
        $form['value']['#size'] = 100;
        $form['value']['#autocomplete_path'] = 'webform-civicrm/js/'. $form['nid']['#value'] .'/tags';
      }
      // Auto set multi-value option for other fields based on schema
      elseif ($form['extra']['multiple']) {
        $form['extra']['multiple']['#type'] = 'hidden';
        $form['extra']['multiple']['#value'] = $fields[$table .'_'. $key]['extra']['multiple'];
      }
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
function _webform_civicrm_webform_frontend_form_alter(&$form) {
  civicrm_initialize();
  $node = $form['#node'];
  $settings = $node->webform_civicrm;
  $enabled = webform_civicrm_enabled_fields($node);
  $cid = array();

  // If this is an edit op, use the original CIDs to avoid confusion
  if (array_key_exists('#submission', $form)) {
    $submission = $form['#submission'];
    $cid = explode('-', trim($submission->civicrm['contact_id'], '-'));
    array_unshift($cid, 0);
  }
  elseif (user_access('access CiviCRM')) {
    for ($i = 1; $i <= 99; ++$i) {
      if (isset($_GET['cid' . $i])) {
        if (is_numeric($_GET['cid' . $i]) && $_GET['cid' . $i]) {
          $cid[$i] = $_GET['cid' . $i];
        }
      }
    }
    if ($cid && isset($_GET['act'])) {
      if (is_numeric($_GET['act']) && $_GET['act']) {
          $act = $_GET['act'];
        }
    }
  }
  if (!$cid) {
    // If user is logged in, look up the CID
    global $user;
    if ($user->uid && $settings['contacts'][1]['contact_type'] == 'Individual') {
      require_once 'CRM/Core/BAO/UFMatch.php';
      $cid[1] = CRM_Core_BAO_UFMatch::getContactId($user->uid);
    }
    // If user is following a hashed link from CiviMail, validate it and set CID appropriately
    elseif (isset($_GET['cs']) && isset($_GET['cid1'])) {
      require_once 'CRM/Contact/BAO/Contact/Utils.php';
      if (CRM_Contact_BAO_Contact_Utils::validChecksum($_GET['cid1'], $_GET['cs'])) {
        $_SESSION['webform_civicrm_cid1'] = $cid[1] = $_GET['cid1'];
        $_SESSION['webform_civicrm_cs'] = $_GET['cs'];
      }
    }
  }
  // Form alterations for unknown contacts
  if (!$cid) {
    $form['#prefix'] = filter_filter('process', 1, NULL, $settings['prefix_unknown']);
    if ($settings['block_unknown_users']) {
      $form = array();
    }
    return;
  }
  // Form alterations for known contacts
  require_once 'api/api.php';
  $contacts = $settings['contacts'];
  $fields = webform_civicrm_get_fields();
  $custom = FALSE;
  foreach ($fields as $id => $f) {
    if (strpos($id, 'custom') || strpos($id, 'preferred_communication_method')) {
      $custom = TRUE;
      $params[str_replace('civicrm_', 'return.', $id)] = 1;
    }
  }
  if ($custom) {
    $custom = civicrm_contact_get($params);
    $contact = array_merge($contact[$cid], $custom[$cid]);
  }
  else {
    $contact = $contact[$cid];
  }

  $form['#prefix'] .= filter_filter('process', 1, NULL, webform_civicrm_replace_tokens($settings['prefix_known'], $contact));

  // Do not alter form default values if this is an edit op
  if (isset($submission)) {
    return;
  }

  // Some translation of array key strings is necessary due to irregulatities in CiviCRM API v2
  $contact_info = array();
  foreach ($enabled as $id => $val) {
    $new_id = str_replace(array('civicrm_', 'prefix', 'suffix'), array('', 'individual_prefix', 'individual_suffix'), $id);
    if (($value = $contact[$new_id]) || isset($contact[$new_id])) {
      $contact_info[$id] = $value;
    }
  }

  // Set default values for group field
  if (array_key_exists('civicrm_1_1_other_groups', $enabled)) {
    $groups = civicrm_api('group_contact', 'get', array('check_permissions' => FALSE, 'version' => 3, 'contact_id' => $cid));
    $contact['groups'] = array();
    foreach ($groups['values'] as $group) {
      $contact['groups'][] = $group['group_id'];
    }
  }
  // Recurse through FAPI array and set default values for civicrm elements
  webform_civicrm_fill_values($form['submitted'], $contact_info);

  if ($settings['message']) {
    webform_civicrm_set_message($settings['message'], $contact);
  }
}
