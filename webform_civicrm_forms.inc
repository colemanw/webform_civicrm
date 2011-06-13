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
  if (!empty($form_state['values'])) {
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
              'contact' => array(1 => array('contact_type' => 'individual')),
              'activity_target' => 1,
              'number_of_email' => 1,
            ),
          ),
        ),
        'prefix_known' => '',
        'prefix_unknown' => '',
        'message' => '',
        'confirm_subscription' => TRUE,
        'create_fieldsets' => TRUE,
      );
    }
  }
  $tokens = '<strong>'. t('Tokens Available:') .'</strong> ['. implode('], [', webform_civicrm_get_fields('tokens')) .'].';
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  $readme_link = array('!link' => 'href="/admin/help/webform_civicrm" target="_blank" class="popup-webform-civicrm-help"');
  $form = array('#prefix' => t('Use the options below to configure CiviCRM processing for this form.'));
  $contacts = $settings['data']['contact'];
  $data = $settings['data'];

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
    '#options' => webform_civicrm_get_options('activity_type', 'arr', t('- no activity -')),
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
  if (isset($data['activity'][1]['activity'][1]['activity_type_id'])) {
    $form['act']['activity_type_id']['#default_value'] = $settings['activity_type_id'] = $data['activity'][1]['activity'][1]['activity_type_id'];
    $form['act']['activity_fields']['activity_subject'] = array(
      '#type' => 'textfield',
      '#title' => t('Default Activity Subject'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#description' => t('You can override this default by allowing users to enter a subject (enable "Activity Subject" field below)'),
    );
    if (isset($data['activity'][1]['activity'][1]['subject'])) {
      $form['act']['activity_fields']['activity_subject']['#default_value'] = $data['activity'][1]['activity'][1]['subject'];
    }
    else {
      $form['act']['activity_fields']['activity_subject']['#default_value'] = check_plain($node->title);
    }
    $form['act']['activity_fields']['activity_status_id'] = array(
      '#type' => 'select',
      '#title' => t('Activity Status'),
      '#options' => webform_civicrm_get_options('activity_status', 'arr'),
    );
    if (isset($data['activity'][1]['activity'][1]['status_id'])) {
      $form['act']['activity_fields']['activity_status_id']['#default_value'] = $data['activity'][1]['activity'][1]['status_id'];
    }
    else {
      $form['act']['activity_fields']['activity_status_id']['#default_value'] = 2;
    }
    $form['act']['activity_fields']['activity_link'] = array(
      '#type' => 'checkbox',
      '#title' => t('Include link to webform submission in activity details?'),
      '#default_value' => !(empty($data['activity'][1]['add_link']) && isset($data['activity'][1]['add_link']))
    );
    foreach ($sets as $sid => $set) {
      if ($set['entity_type'] == 'activity') {
        if (!empty($set['sub_types'])) {
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
          $fid = 'civicrm_1_activity_1_' . $fid;
          $form['act']['activity_fields'][$sid][$fid] = array(
            '#type' => 'checkbox',
            '#title' => $field['name'],
            '#description' => $field['extra']['description'],
            '#return_value' => 'create_civicrm_webform_element',
            '#default_value' => !empty($enabled[$fid]),
          );
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
      '#default_value' => $c['contact'][1]['contact_type'],
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
    if (!empty($sub_types[$c['contact'][1]['contact_type']])) {
      $subs = array('create_civicrm_webform_element' => t('-user select-'), 0 => $contact_types[$c['contact'][1]['contact_type']]) + $sub_types[$c['contact'][1]['contact_type']];
    }
    else {
      $subs = array($contact_types[$c['contact'][1]['contact_type']]);
    }
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_contact_1_contact_contact_sub_type'] = array(
      '#type' => 'select',
      '#title' => t('Type of').' '.$contact_types[$c['contact'][1]['contact_type']],
      '#options' => $subs,
      '#description' => t('You may optionally select a more specific type for this contact. Choose "user select" to create a form element with these options.'),
      '#default_value' => 0,
      '#ahah' => array(
        'path' => 'webform-civicrm/js/' . $node->nid . '/civicrm_ahah.contacts.contact_'. $n .'.contact_subtype_wrapper.contact_custom_wrapper',
        'wrapper' => 'civicrm-ahah-contact-custom-wrapper-'. $n,
        'effect' => 'fade',
      ),
    );
    if (empty($sub_types[$c['contact'][1]['contact_type']])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_contact_1_contact_contact_sub_type']['#prefix'] = '<div class="hidden">';
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_contact_1_contact_contact_sub_type']['#suffix'] = '</div>';
    }
    elseif (isset($enabled['civicrm_'. $n .'_contact_1_contact_contact_sub_type'])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_contact_1_contact_contact_sub_type']['#default_value'] = $enabled['civicrm_'. $n .'_contact_1_contact_contact_sub_type'];
    }
    elseif (isset($c['contact'][1]['contact_sub_type'])) {
      $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['civicrm_'. $n .'_contact_1_contact_contact_sub_type']['#default_value'] = $c['contact'][1]['contact_sub_type'];
    }
    $form['contacts']['contact_' . $n]['contact_subtype_wrapper']['contact_custom_wrapper'] = array(
      '#prefix' => '<div class="civicrm-ahah-wrapper" id="civicrm-ahah-contact-custom-wrapper-'. $n .'">',
      '#value' => ' ',
      '#suffix' => '</div>',
    );
    foreach ($sets as $sid => $set) {
      $hide = FALSE;
      if ($set['entity_type'] != 'contact') {
        continue;
      }
      if ($sid == 'relationship') {
        //TODO
        continue;
      }
      if (!empty($set['contact_type'])) {
        if ($set['contact_type'] != $c['contact'][1]['contact_type']) {
          $hide = TRUE;
        }
      }
      if (!empty($set['sub_types'])) {
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
      if (!empty($set['max_instances'])) {
        if (!isset($c['number_of_'. $sid])) {
          $c['number_of_'. $sid] = 0;
        }
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
          list($ftable, $fname) = explode('_', $fid, 2);
          $fid = 'civicrm_'. $n .'_contact_'. $i .'_' . $fid;
          if (array_key_exists('contact_type', $field)) {
            if($field['contact_type'] != $c['contact'][1]['contact_type']) {
              continue;
            }
          }
          // Create dropdown list
          if (!empty($field['expose_list'])) {
            // Populate option list as needed
            if (!is_array($lists[$fname])) {
              $lists[$fname] =  array('create_civicrm_webform_element' => t('-user select-')) + webform_civicrm_get_options($lists[$fname], 'arr');
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
    '#default_value' => !empty($settings['block_unknown_users']),
    '#description' => t('Only allow users to see this form if they are logged in or following a personalized link from CiviMail.')
  );
  $form['options']['create_fieldsets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Create Fieldsets'),
    '#default_value' => (bool) $settings['create_fieldsets'],
    '#description' => t('Create a fieldset around each contact. (Nice if you have more than one contact on this form, but not required; all CiviCRM fields will be processed correctly regardless of what fieldset you place them in.')
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save Settings'),
  );
  return $form;
}

/**
 * Submission handler, saves CiviCRM options for a Webform node
 * Also called for AHAH form callbacks
 */
function webform_civicrm_configure_form_submit($form, &$form_state) {
  civicrm_initialize();
  $vals =& $form_state['values'];
  if ($form_state['clicked_button']['#id'] != 'edit-change-number-of-contacts'  || !empty($form_state['ahah_submission'])) {
    $vals['number_of_contacts'] = $form['number_of_contacts']['#default_value'];
  }
  $vals['data'] = array('contact' => array());
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  for ($n = 1; $n <= $vals['number_of_contacts']; ++$n) {
    if (isset($vals[$n . '_contact_type'])) {
      $vals['data']['contact'][$n] = array(
        'activity_target' => $vals[$n . '_activity_target'],
      );
      $vals['data']['contact'][$n]['contact'][1]['contact_type'] = $vals[$n . '_contact_type'];
      if (array_key_exists('civicrm_'. $n .'_contact_1_contact_contact_sub_type', $vals)) {
        $sub_type = $vals['civicrm_'. $n .'_contact_1_contact_contact_sub_type'];
        if (array_key_exists($sub_type, $sub_types[$vals[$n . '_contact_type']])) {
          $vals['data']['contact'][$n]['contact'][1]['contact_sub_type'] = $sub_type;
        }
      }
    }
    else {
      $vals['data']['contact'][$n] = array(
        'contact' => array(1 => array('contact_type' => 'individual')),
        'activity_target' => 1,
      );
    }
  }
  foreach ($vals as $key => $val) {
    if (strpos($key, '_number_of_') !== FALSE) {
      list($n, $k) = explode('_', $key, 2);
      if (isset($vals['data']['contact'][$n])) {
        $vals['data']['contact'][$n][$k] = $val;
      }
    }
  }
  if (!empty($vals['activity_type_id'])) {
    $vals['data']['activity'] = array(1 => array('activity' => array(1 => array('activity_type_id' => $vals['activity_type_id']))));
    if (isset($vals['activity_subject'])) {
      $vals['data']['activity'][1]['activity'][1]['subject'] = $vals['activity_subject'];
      $vals['data']['activity'][1]['activity'][1]['status_id'] = $vals['activity_status_id'];
      $vals['data']['activity'][1]['add_link'] = $vals['activity_link'];
    }
  }
  if ($form_state['clicked_button']['#id'] == 'edit-change-number-of-contacts' || !empty($form_state['ahah_submission'])) {
    $form_state['rebuild'] = TRUE;
    return;
  }
  $node = node_load($nid = arg(1));
  $fields = webform_civicrm_get_fields();
  $delete_me = $enabled = webform_civicrm_enabled_fields($node);
  $sets = webform_civicrm_get_fields('sets');
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  $form_state['redirect'] = 'node/'. $nid .'/webform';
  unset($form_state['storage']);

  // Disable CiviCRM for this form
  if (isset($node->webform_civicrm) && !$vals['nid']) {
    db_query('DELETE FROM {webform_civicrm_forms} WHERE nid = %d', $nid);
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
    foreach($delete_me as $id) {
      drupal_set_message(t('Deleted field:').' '.$node->webform['components'][$id]['name']);
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
          $field = $fields[$table .'_'. $name];
          $field['nid'] = $nid;
          $field['form_key'] = $key;
          $field['weight'] = $i;
          $field['pid'] = 0;
          if ($n > 1) {
            $field['name'] .= ' ' . $n;
          }
          if ($name == 'contact_sub_type') {
            $field['name'] .= ' ' . $contact_types[$vals['data']['contact'][$c]['contact'][1]['contact_type']];
            $field['extra']['items'] = '0|'.$contact_types[$vals['data']['contact'][$c]['contact'][1]['contact_type']];
            foreach ($sub_types[$vals['data']['contact'][$c]['contact'][1]['contact_type']] as $k => $v) {
              $field['extra']['items'] .= "\n" . $k .'|'. $v;
            }
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
            $field['extra']['items'] = webform_civicrm_get_options($lists[$name]);
            if (!isset($field['extra']['aslist'])) {
              $field['extra']['aslist'] = 1;
            }
          }
          if (strpos($table, 'cg') === FALSE) {
            unset($field['extra']['description']);
          }
          // Create fieldsets
          if ($ent != 'activity' && !empty($vals['create_fieldsets'])) {
            if (array_key_exists('civicrm_'.$c.'_contact_1_fieldset_fieldset', $enabled)) {
              $set = $enabled['civicrm_'.$c.'_contact_1_fieldset_fieldset'];
            }
            else {
              $new_set = array(
                'nid' => $nid,
                'form_key' => 'civicrm_'.$c.'_contact_1_fieldset_fieldset',
                'type' => 'fieldset',
                'name' => t('Contact') .' '. $c,
                'pid' => 0,
                'weight' => $i,
              );
              $enabled['civicrm_'.$c.'_contact_1_fieldset_fieldset'] = $set = webform_component_insert($new_set);
            }
            $field['pid'] = $set;
          }
          // Create webform component
          $fid = webform_component_insert($field);

          $new_fields = TRUE;
        }
        // Handle exposed contact settings that don't create form elements
        elseif (isset($fields[$table .'_'. $name]['expose_list'])) {
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
    if (!empty($form_state['values']['value'])) {
      $form_state['values']['value'] .= ',';
    }
    $form_state['values']['value'] .= $gid;
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
    if (!($pieces = webform_civicrm_explode_key($form['form_key']['#default_value']))) {
      return;
    }
    list($lobo, $i, $ent, $n, $table, $key) = $pieces;
    // Is this component a CiviCRM field?
    if (array_key_exists($table .'_'. $key, $fields) || 
        ($lobo == 'civicrm' && $table == 'fieldset')) {
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
        if (!empty($_GET['reset'])) {
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
      elseif (!empty($form['extra']['multiple'])) {
        $form['extra']['multiple']['#type'] = 'hidden';
        $form['extra']['multiple']['#value'] = !empty($fields[$table .'_'. $key]['extra']['multiple']);
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
function _webform_civicrm_webform_frontend_form_alter(&$form, &$form_state) {
  civicrm_initialize();
  $node = $form['#node'];
  $settings = $node->webform_civicrm;

  $id = array();
  // If this is an edit op, use the original IDs to avoid confusion
  if (!empty($form['#submission'])) {
    $submission = $form['#submission'];
    $id['cid'] = explode('-', trim($submission->civicrm['contact_id'], '-'));
    array_unshift($id['cid'], 0);
    $id['act'] = $submission->civicrm['activity_id'];
  }
  else {
    require_once 'CRM/Contact/BAO/Contact/Permission.php';
    $count = count($settings['data']['contact']);
    for ($i = 1; $i <= $count; ++$i) {
      if (!empty($_GET['cid' . $i])) {
        if (is_numeric($cid = $_GET['cid' . $i])) {
          if (CRM_Contact_BAO_Contact_Permission::validateChecksumContact($cid, CRM_Core_DAO::$_nullObject)) {
            $id['cid'][$i] = $cid;
          }
        }
      }
    }
    if (empty($id['cid'][1])) {
      // If user is logged in, look up the CID
      global $user;
      if ($user->uid && $settings['data']['contact'][1]['contact'][1]['contact_type'] == 'individual') {
        $result = webform_civicrm_api('uf_match', 'get', array('uf_id' => $user->uid));
        $result = array_pop($result['values']);
        $id['cid'][1] = $result['contact_id'];
      }
    }
    if (!empty($_GET['aid'])) {
      if (is_numeric($_GET['aid'])) {
        $id['act'][1] = $_GET['aid'];
      }
    }
  }

  // Form alterations for unknown contacts
  if (!$id['cid'][1]) {
    if ($settings['prefix_unknown']) {
      $form['#prefix'] = filter_filter('process', 1, NULL, $settings['prefix_unknown']);
    }
    if ($settings['block_unknown_users']) {
      $form = array();
      drupal_set_message(t('Sorry, you do not have permission to access this form.'));
    }
    return;
  }
  // Form alterations for known contacts
  require_once 'api/api.php';
  $enabled = webform_civicrm_enabled_fields($node);
  $contacts = $settings['data']['contact'];
  $data = array('contact' => array());
  $core = array('contact', 'address', 'email', 'phone', 'website');
  foreach ($contacts as $c => $contact) {
    $data['contact'][$c] = array();
    if (!empty($id['cid'][$c])) {
      foreach ($core as $field) {
        if (!empty($contact['number_of_' . $field]) || $field == 'contact') {
          $result = webform_civicrm_api($field, 'get', array('contact_id' => $id['cid'][$c]));
          if ($result['count']) {
            $result = array_merge(array(0), array_values($result['values']));
            unset($result[0]);
            // Load address custom data
            if ($field == 'address') {
              foreach($result as &$address) {
                $custom = webform_civicrm_get_custom($address['id'], 'address');
                if (!empty($custom['address'])) {
                  $address = $address + $custom['address'][1];
                }
              }
            }
            $data['contact'][$c][$field] = $result;
          }
        }
      }
      // Get custom contact data
      $custom = webform_civicrm_get_custom($id['cid'][$c]);
      $data['contact'][$c] = $data['contact'][$c] + $custom;
      // Set default values for group field
      if (array_key_exists('civicrm_'.$c.'_contact_1_other_groups', $enabled)) {
        $groups = webform_civicrm_api('group_contact', 'get', array('contact_id' => $id['cid'][$c]));
        $data['contact'][$c]['other'][1]['groups'] = array();
        foreach ($groups['values'] as $group) {
          $data['contact'][$c]['other'][1]['groups'][] = $group['group_id'];
        }
      }
    }
  }

  if (!empty($id['act'][1])) {
    $result = webform_civicrm_api('activity', 'get', array('activity_id' => $id['act'][1], 'return.target_contact_id' => 1, 'return.assignee_contact_id' => 1));
    if(isset($result['values'][$id['act'][1]])) {
      $act = $result['values'][$id['act'][1]];
      $valid = FALSE;
      // Verify that this activity is the right type and that our contacts have some involvement in it
      if ($act['activity_type_id'] == $settings['data']['activity'][1]['activity'][1]['activity_type_id']) {
        foreach ($id['cid'] as $cid) {
          if ($act['source_contact_id'] == $cid || in_array($cid, $act['target_contact_id']) || in_array($cid, $act['assignee_contact_id'])) {
            $valid = TRUE;
          }
        }
      }
      if ($valid) {
        $activity = array('activity' => array(1 => $result['values'][$id['act'][1]]));
        $custom = webform_civicrm_get_custom($id['act'][1], 'activity');
        $data['activity'] = array(1 => ($activity + $custom));
      }
      else {
        unset($id['act']);
      }
    }
  }

  if ($settings['prefix_known']) {
    $form['#prefix'] = filter_filter('process', 1, NULL, webform_civicrm_replace_tokens($settings['prefix_known'], $contact));
  }

  if (!empty($id)) {
    $_SESSION['webform_civicrm_data'][$node->nid] = $id;
  }
  elseif (isset($_SESSION['webform_civicrm_data'][$node->nid])) {
    unset($_SESSION['webform_civicrm_data'][$node->nid]);
  }

  // Do not alter form default values if this is an edit op
  if (isset($submission)) {
    return;
  }

  // Set default values for CiviCRM form elements
  webform_civicrm_fill_values($form['submitted'], $data);

  if ($settings['message']) {
    webform_civicrm_set_message($settings['message'], $contact);
  }
}
