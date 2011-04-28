<?php

/**
 * @file
 * Webform CiviCRM module's form building, altering, and processing functions.
 */

module_load_include('inc', 'webform_civicrm', 'webform_civicrm_utils');

/**
 * Form to configure CiviCRM options for a Webform
 */
function webform_civicrm_configure_form($form, &$form_state, $node) {
  drupal_add_js(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_forms.js');
  drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_style.css', 'module', 'all', FALSE);
  civicrm_initialize();

  $fields = webform_civicrm_get_fields();
  $settings = $node->webform_civicrm;
  $enabled = webform_civicrm_enabled_fields($node);
  $tokens = '<strong>' . t('Tokens Available:') . '</strong> [' . implode('], [', webform_civicrm_get_fields('tokens')) . '].';
  $acts = webform_civicrm_get_options('activity_type', 'arr', t('- no activity -'));
  $readme_link = array('!link' => 'href="/admin/help/webform_civicrm" target="_blank"');
  $form = array('#prefix' => t('Use the options below to configure CiviCRM processing for this form.'));

  $form['nid'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable CiviCRM Processing'),
    '#default_value' => (bool) $settings,
    '#return_value' => $node->nid,
    '#description' => '<ul><li>' .
      t('CiviCRM contacts will be created or updated when users submit this webform.') . '</li><li>' .
      t('Logged-in users will have their contact info already filled-in for them.') . '</li><li>' .
      t('The form will also be auto-filled for anonymous users if you send them this link using CiviMail:') . '<br />' .
      url('node/' . $node->nid, array('absolute' => TRUE)) . '?cid={contact.contact_id}&amp;{contact_checksum}</li><li>' .
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
    '#title' => t('Message text (displayed as a Drupal notice)'),
    '#default_value' => $settings['message'],
    '#size' => 80,
    '#maxlength' => 255,
    '#description' => t('Enclose your "not you" link text in curly brackets {like this}.') . '<p>' . $tokens . '</p>',
  );
  if (!$settings['message']) {
    $form['st_message']['message']['#default_value'] = t("You are viewing this form as [display name]. Please {click here if that's not you}.");
  }
  $form['act'] = array(
    '#type' => 'fieldset',
    '#title' => t('Create Activity'),
    '#description' => t('Automatically create an activity for contacts when this form is submitted?'),
  );
  $form['act']['activity_type_id'] = array(
    '#type' => 'select',
    '#title' => t('Activity Type'),
    '#options' => $acts,
    '#default_value' => $settings['activity_type_id'],
  );
  $form['act']['activity_subject'] = array(
    '#type' => 'textfield',
    '#title' => t('Activity Subject'),
    '#maxlength' => 255,
    '#default_value' => $settings['activity_subject'],
  );

  $form['fields'] = array(
    '#type' => 'fieldset',
    '#title' => t('Enable Fields'),
    '#description' => '<p class="web-civi-p">' . t('Webform components will be created for you automatically when you check these boxes. You may then configure, arrange, or delete them using the standard Webform interface.') . '</p>',
    '#attributes' => array('class' => array('web-civi-container')),
  );
  $form['fields']['box'] = array(
    '#prefix' => '<div class="scrollme">',
    '#suffix' => '</div>',
  );
  // Three columns for neatness
  $form['fields']['box']['contact'] = array(
    '#prefix' => '<div class="web-civi-3-col"><h4>' . t('Contact Fields') . '</h4><div class="web-civi-description">' . t('First name, Last name, and Email fields are all <em>required</em> by CiviCRM in order to create new contacts.') . '</div>',
    '#suffix' => '</div>',
  );
  $form['fields']['box']['groups'] = array(
    '#prefix' => '<div class="web-civi-3-col"><h4>' . t('Groups and Tags') . '</h4><div class="web-civi-description">' . t('These fields will add contacts who submit this form to groups and tags of your choosing. Configuration is done by editing the webform component.') . '</div>',
    '#suffix' => '</div>',
  );
  $form['fields']['box']['custom'] = array(
    '#prefix' => '<div class="web-civi-3-col"><h4>' . t('Custom Fields') . '</h4><div class="web-civi-description">' . t('Custom fields you have created for contacts in CiviCRM will appear in this list.') . '</div>',
    '#suffix' => '</div>',
  );
  // CiviCRM fields are enabled through these checkboxes
  $c = 'contact';
  $sets = array(
    'civicrm_first_name' => 'Contact',
    'civicrm_street_address' => 'Address',
    'civicrm_phone' => 'Phone',
    'civicrm_groups' => 'Groups',
    'civicrm_tags' => 'Tags',
  );
  foreach ($fields as $id => $field) {
    $f = array(
      '#type' => 'checkbox',
      '#title' => $field['name'],
      '#default_value' => (bool) $enabled[$id],
      '#disabled' => (bool) $enabled[$id],
      '#description' => $field['extra']['description'],
    );
    if ($id == 'civicrm_groups') {
      $c = 'groups';
    }
    if (!strpos($id, 'custom')) {
      // Suggested minimum enabled fields
      if (!$settings && ($id == 'civicrm_first_name' || $id == 'civicrm_last_name' || $id == 'civicrm_email')) {
        $f['#default_value'] = TRUE;
      }
      // Create fieldsets
      if ($sets[$id]) {
        $set = $sets[$id];
        $form['fields']['box'][$c][$set] = array(
          '#type' => 'fieldset',
          '#collapsible' => TRUE,
          '#title' => $set,
        );
      }
    }
    // Fieldsets for custom groups
    else {
      $c = 'custom';
      if (strip_tags($field['custom_group']) != $set) {
        $set = strip_tags($field['custom_group']);
        $form['fields']['box'][$c][$set] = array(
          '#type' => 'fieldset',
          '#collapsible' => TRUE,
          '#title' => $set,
        );
      }
    }
    $form['fields']['box'][$c][$set][$id] = $f;
  }
  if ($c != 'custom') {
    $form['fields']['box']['custom']['#prefix'] .= '<p>' . t('You have no custom fields enabled in CiviCRM.') . '</p>';
  }

  $form['options'] = array(
    '#type' => 'fieldset',
    '#title' => t('Additional Options'),
  );
  $form['options']['confirm_subscription'] = array(
    '#prefix' => '<div class="web-civi-3-col">',
    '#suffix' => '</div>',
    '#type' => 'checkbox',
    '#title' => t('Confirm Subscriptions'),
    '#default_value' => (bool) $settings['confirm_subscription'],
    '#description' => t('Recommended. Send a confirmation email before adding contacts to publicly subscribable mailing list groups.') . '<br />' . t('Your public mailing lists:') . ' <em>' . implode(', ', webform_civicrm_get_options('mailing_lists', 'arr')) . '</em>',
  );
  $form['options']['block_unknown_users'] = array(
    '#prefix' => '<div class="web-civi-3-col">',
    '#suffix' => '</div>',
    '#type' => 'checkbox',
    '#title' => t('Block unknown users'),
    '#default_value' => (bool) $settings['block_unknown_users'],
    '#description' => t('Only allow users to see this form if they are logged in or following a personalized link from CiviMail.'),
  );
  // Set a few defaults
  if (!$settings) {
    $form['act']['activity_subject']['#default_value'] = check_plain($node->title);
    $form['options']['confirm_subscription']['#default_value'] = TRUE;
  }

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
  civicrm_initialize();
  $vals = $form_state['values'];
  $node = node_load($nid = arg(1));
  $fields = webform_civicrm_get_fields();
  $enabled = webform_civicrm_enabled_fields($node);
  $form_state['redirect'] = 'node/' . $nid . '/webform';

  // Disable CiviCRM for this form
  if ($node->webform_civicrm && !$vals['nid']) {
    db_delete('webform_civicrm_forms')
  ->condition('nid', $nid)
  ->execute();
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

    // Add webform components (fields)
    $i = 0;
    $lists = webform_civicrm_get_fields('lists');
    foreach ($fields as $id => $field) {
      if (!$enabled[$id] && $vals[$id]) {
        $field['nid'] = $nid;
        $field['form_key'] = $id;
        $field['weight'] = $i;
        $field['pid'] = 0;
        unset($field['custom_group']);

        if (($field['type'] == 'textfield' || $field['type'] == 'email') && !$field['extra']['width']) {
          $field['extra']['width'] = 20;
        }

        // Set default country
        if ($id == 'civicrm_country_id') {
          $config = CRM_Core_Config::singleton();
          $field['value'] = $config->defaultContactCountry;
        }
        // Retrieve option list
        if (array_key_exists($id, $lists)) {
          $field['extra']['items'] = webform_civicrm_get_options($lists[$id]);
          if (!isset($field['extra']['aslist'])) {
            $field['extra']['aslist'] = 1;
          }
        }
        if (strpos($id, 'custom') === FALSE) {
          unset($field['extra']['description']);
        }
        // Create webform component
        $fid = webform_component_insert($field);

        if ($id == 'civicrm_groups') {
          drupal_set_message(t('Please choose which group(s) you want to be selectable by users of this form.'));
          $form_state['redirect'] = 'node/' . $nid . '/webform/components/' . $fid;
        }
        else {
          $new_fields = TRUE;
        }
      }
      ++$i;
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
        $items .= $gid . '|' . $groups[$gid];
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
 * Matching depends on form keys matching contact keys (i.e. form key 'civicrm_first_name' == contact key 'first_name')
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
    $eid = str_replace('civicrm_', '', $eid);
    if (isset($contact[$eid])) {
      if ($element['#type'] == 'date') {
        $dates = explode('-', $contact[$eid]);
        $contact[$eid] = array(
          'year' => $dates[0],
          'month' => intval($dates[1]),
          'day' => intval($dates[2]),
        );
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
  if ($cid = webform_civicrm_contact_match($node, $submission)) {
    $act = 0;
    if ($type = $node->webform_civicrm['activity_type_id']) {
      require_once 'api/api.php';
      $act_params = array(
        'version' => 3,
        'activity_type_id' => $type,
        'source_contact_id' => $cid,
        'target_contact_id' => $cid,
        'subject' => $node->webform_civicrm['activity_subject'],
        'status_id' => 2,
        'activity_date_time' => date('Ymdhis'),
        'details' => l(t('View Webform Submission'), 'node/' . $node->nid . '/submission/' . $submission->sid),
      );
      $result = civicrm_api('activity', 'create', $act_params);
      $act = $result['id'];
    }
    $record = array('sid' => $submission->sid, 'contact_id' => $cid, 'activity_id' => $act);
    drupal_write_record('webform_civicrm_submissions', $record);
  }
  else {
    watchdog('webform_civicrm', 'An error occured when trying to create a new contact. The contact was not created.', NULL, WATCHDOG_ERROR, l($node->title . ': ' . t('Submission #') . $submission->sid, 'node/' . $node->nid . '/submission/' . $submission->sid));
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
    $key = $form['form_key']['#default_value'];
    // Is this component a CiviCRM field?
    if (array_key_exists($key, $fields)) {
      drupal_add_css(drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_style.css', 'module', 'all', FALSE);

      // Prevent users from editing the form_key and breaking things
      $form['form_key']['#disabled'] = TRUE;
      $form['form_key']['#value'] = $key;
      $form['form_key']['#description'] = t('Automatically set for use by CiviCRM processing.');

      // Adds the ability to reload option lists via AJAX (also works without JS)
      $lists = webform_civicrm_get_fields('lists');
      if (array_key_exists($key, $lists)) {
        unset($form['extra']['options_source']);
        $complete_msg = t('The options have been reloaded.');
        $form['extra']['items']['#description'] = t("These options were automatically selected from the CiviCRM database. You may change their order, or delete unwanted options as you see fit. You may also change the labels (text after the |) to be more descriptive. However, adding new items here that are <em>not</em> in CiviCRM won't work. Instead, create your new options in CiviCRM, then click here to refresh this list:");
        if ($_GET['reset']) {
          $form['extra']['items']['#description'] .= '<div style="color:green; font-weight:bold; margin-top:0.5em;">' . $complete_msg . '</div>';
          $form['extra']['items']['#default_value'] = webform_civicrm_get_options($lists[$key]);
        }
        else {
          $form['extra']['items']['#description'] .= '<div id="civicrm-ajax" style="color:green; font-weight:bold; margin-top:0.5em;">' . l(t('Reload options'), $_GET['q'], array('query' => array('reset' => 1), 'attributes' => array(
              'style' => 'color:blue;',
              'onclick' => "jQuery(this).addClass('views-throbbing');
            jQuery.ajax({
              url: '/webform-civicrm/js/" . arg(1) . "/$key',
              success: function(data) {
                jQuery('#edit-extra-items').val(data);
                jQuery('#civicrm-ajax').html('$complete_msg');
              }
            }); return false;",
            ))) .
          '</div>';
        }
      }
      // Simplify form for hidden fields
      elseif ($key == 'civicrm_contact_id' || $key == 'civicrm_external_identifier') {
        $form['value']['#type'] = 'hidden';
        $form['value']['#value'] = 0;
        $form['#prefix'] = '<p>' . t('There are no configuration options for this hidden field. You can use it for post processing, for example to include a link to the CiviCRM contact in an email.') . '</p>';
      }

      // Special options for CiviCRM groups
      if ($key == 'civicrm_groups' || $key == 'civicrm_groups_hidden') {
        if ($key == 'civicrm_groups') {
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
          '#type' => 'fieldset',
          '#title' => t('Groups'),
          '#description' => t('Which group(s) is the user allowed to join on this form? (Often used for letting people subscribe to mailing lists)'),
        );
        if ($key == 'civicrm_groups') {
          $form['civicrm_groups_fieldset']['civicrm_groups'] = array(
            '#title' => t('Allowed Groups'),
            '#type' => 'checkboxes',
            '#required' => TRUE,
            '#options' => $groups,
            '#default_value' => $selected,
            '#prefix' => '<div class="web-civi-3-col">',
            '#suffix' => '</div>',
          );
        }
        $form['civicrm_groups_fieldset']['civicrm_group_defaults'] = array(
          '#title' => t('Checked by Default on Webform?'),
          '#type' => 'checkboxes',
          '#options' => $groups,
          '#default_value' => $defaults_selected,
        );
        if ($key == 'civicrm_groups') {
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
      elseif ($key == 'civicrm_tags') {
        $form['value']['#title'] = t('Tags');
        $form['value']['#description'] = t('Enter a comma-separated list of tags to add to contacts who submit this webform.') . '<br />' . t('Use the autocomplete to select existing tags, or you may add new ones.');
        $form['value']['#type'] = 'textfield';
        $form['value']['#size'] = 100;
        $form['value']['#autocomplete_path'] = 'webform-civicrm/js/' . $form['nid']['#value'] . '/tags';
      }
      // Auto set multi-value option for other fields based on schema
      elseif ($form['extra']['multiple']) {
        $form['extra']['multiple']['#type'] = 'hidden';
        $form['extra']['multiple']['#value'] = $fields[$key]['extra']['multiple'];
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
  require_once 'api/api.php';

  $node = $form['#node'];
  $settings = $node->webform_civicrm;
  $enabled = webform_civicrm_enabled_fields($node);

  // If this is an edit op, use the original CID to avoid confusion
  if ($submission = $form['#submission']) {
    $cid = $submission->civicrm['contact_id'];
  }
  else {
    // If user is logged in, look up the CID
    global $user;
    if ($user->uid) {
      $result = civicrm_api('uf_match', 'get', array('uf_id' => $user->uid, 'version' => 3));
      $result = array_pop($result['values']);
      $cid = $result['contact_id'];
    }
    // If user is following a hashed link from CiviMail, validate it and set CID appropriately
    elseif ($_GET['cs'] && $_GET['cid']) {
      require_once 'CRM/Contact/BAO/Contact/Utils.php';
      if (CRM_Contact_BAO_Contact_Utils::validChecksum($_GET['cid'], $_GET['cs'])) {
        $_SESSION['webform_civicrm_cid'] = $cid = $_GET['cid'];
        $_SESSION['webform_civicrm_cs'] = $_GET['cs'];
      }
    }
  }
  // Form alterations for unknown contacts
  if (!$cid) {
    $form['#prefix'] .= check_markup($settings['prefix_unknown']);
    if ($settings['block_unknown_users']) {
      $form = array();
    }
    return;
  }
  // Form alterations for known contacts
  $params = array('contact_id' => $cid, 'version' => 3, 'check_permission' => FALSE);
  $contact = civicrm_api('contact', 'get', $params);
  $contact = array_pop($contact['values']);
  $fields = webform_civicrm_get_fields();
  $custom = FALSE;
  foreach ($fields as $id => $f) {
    if (strpos($id, 'custom') || strpos($id, 'preferred_communication_method')) {
      $custom = TRUE;
      $params[str_replace('civicrm_', 'return.', $id)] = 1;
    }
  }
  if ($custom) {
    $custom = civicrm_api('contact', 'get', $params);
    $contact = array_merge($contact, array_pop($custom['values']));
  }

  $form['#prefix'] .= check_markup(webform_civicrm_replace_tokens($settings['prefix_known'], $contact));

  // Do not alter form default values if this is an edit op
  if ($submission) {
    return;
  }

  // Set default values for group field
  if ($enabled['civicrm_groups']) {
    $groups = civicrm_api('group_contact', 'get', array('version' => 3, 'contact_id' => $cid));
    $contact_info['civicrm_groups'] = array();
    foreach ($groups['values'] as $group) {
      $contact_info['civicrm_groups'][] = $group['group_id'];
    }
  }

  // Recurse through FAPI array and set default values for civicrm elements
  webform_civicrm_fill_values($form['submitted'], $contact);

  if ($settings['message']) {
    webform_civicrm_set_message($settings['message'], $contact);
  }
}
