<?php
// $Id$

/**
 * @file
 * Webform CiviCRM module's form building, altering, and processing functions.
 */

require_once 'webform_civicrm_utils.inc';

/**
 * Form to configure CiviCRM options for a Webform
 */
function webform_civicrm_configure_form(&$form_state, $node) {
  drupal_add_js(drupal_get_path('module', 'webform_civicrm') .'/webform_civicrm_forms.js', 'module', 'header');
  civicrm_initialize();
  $fields = webform_civicrm_get_fields();
  $settings = $node->webform_civicrm;
  $enabled = webform_civicrm_enabled_fields($node);
  $tokens = '<strong>'. t('Tokens Available:') .'</strong> ['. implode('], [', webform_civicrm_get_fields('tokens')) .'].';
  $acts = webform_civicrm_get_options('activity_type', 'arr', t('- no activity -'));
  $readme_link = 'href="/admin/help/webform_civicrm" target="_blank" class="popup-webform-civicrm-help"';
  $form = array('#prefix' => t('Use the options below to configure CiviCRM processing for this form.'));

  // AJAXify help text
  if (module_exists('popups')) {
    popups_add_popups(array(".popup-webform-civicrm-help" => array('width' => '90%')));
  }

  $form['nid'] = array(
    '#type' => 'checkbox',
      '#title' => t('Enable CiviCRM Processing'),
      '#default_value' => (bool)$settings,
      '#return_value' => $node->nid,
      '#suffix' => '<ul id="civicrm-instructions"><li>'.
      t('CiviCRM contacts will be created or updated when users submit this webform.') .'</li><li>'.
      t('Logged-in users will have their contact info already filled-in for them.') .'</li><li>'.
      t('The form will also be auto-filled for anonymous users if you send them this link using CiviMail:') .'<br />'. 
      url('node/'. $node->nid, array('query' => 'cid={contact.contact_id}&{contact.checksum}')) .'</li><li>'.
      t('<a !link>Read the instructions</a> for more info.', array('!link' => $readme_link)) .'</li></ul>'
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
    '#default_value' => (bool)$settings['message'],
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
    '#description' => t('Automatically create an activity for contacts when this form is submitted?')
  );
  $form['act']['activity_type_id'] = array(
    '#type' => 'select',
    '#title' => t('Activity Type'),
    '#options' => $acts,
    '#default_value' => $settings['activity_type_id']
  );
  $form['act']['activity_subject'] = array(
    '#type' => 'textfield',
    '#title' => t('Activity Subject'),
    '#maxlength' => 255,
    '#default_value' => $settings['activity_subject']
  );
  if (!$settings) {
    $form['act']['activity_subject']['#default_value'] = $node->title;
  }

  $form['fields'] = array(
    '#type'  => 'fieldset',
    '#title' => t('Enable Fields'),
    '#description' => t('Webform fields will be created for you automatically when you check these boxes. You may then configure, arrange, or delete them using the standard Webform interface.') .'<br /><strong>'.
    t('IMPORTANT NOTES:') .'</strong><ul><li>'.
    t('First name, Last name, and Email fields are all <em>required</em> by CiviCRM in order to create new contacts.') .'</li><li>'.
    t('Correct handling of custom fields requires proper permission configuration.') .' '.
    t('<a !link>Read the instructions</a> for more info.', array('!link' => $readme_link)) .'</li><ul>'
  );
  // CiviCRM fields can be enabled through these checkboxes
  // TODO: Organize columns by type, and perhaps remove inline css?
  $c = 1;
  $t = count($fields);
  $div = 1;
  foreach ($fields as $id => $field) {
    $form['fields'][$id] = array(
      '#type' => 'checkbox',
      '#title' => $field['name'],
      '#default_value' => $enabled[$id],
      '#disabled' => (bool)$enabled[$id],
      '#description' => $field['extra']['description']
    );
    // Suggested minimum enabled fields
    if (!$settings && ($id=='civicrm_first_name' || $id=='civicrm_last_name' || $id=='civicrm_email'))
      $form['fields'][$id]['#default_value'] = TRUE;

    // Create 3 columns for neatness
    if ($c == 1 || ($c > $t/3 && $div == 1) || ($c > 2*$t/3 && $div == 2)) {
      if ($c != 1) {
        ++$div;
        $form['fields'][$id]['#prefix'] = '</div>';
      }
      $form['fields'][$id]['#prefix'] .= '<div style="float:left; width:32%;">';
    }
    ++$c;
  }
  $form['fields'][$id]['#suffix'] = '</div>';

  $form['options'] = array(
    '#type'  => 'fieldset',
    '#title' => t('Additional Options')
  );
    $form['options']['block_unknown_users'] = array(
      '#type' => 'radios',
      '#title' => t('Block unknown users'),
      '#default_value' => (int)$settings['block_unknown_users'],
      '#options' => array(0 => t('No'), 1 => t('Yes')),
      '#description' => t('Selecting this option will only allow users to see this form if they are either logged in or following a personalized link from CiviMail.')
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
  civicrm_initialize();
  $vals = $form_state['values'];
  $node = node_load($nid=arg(1));
  $fields = webform_civicrm_get_fields();
  $enabled = webform_civicrm_enabled_fields($node);
  $form_state['redirect'] = 'node/'. $nid .'/webform';

  // Disable CiviCRM for this form
  if ($node->webform_civicrm && !$vals['nid']) {
    db_query('DELETE FROM {webform_civicrm_forms} WHERE nid = %d', $nid);
    drupal_set_message(t('CiviCRM processing for this form is now disabled. You may delete any fields you no longer want.'));
  }
  // CiviCRM enabled
  else{
    if (!$vals['toggle_message'])
      $vals['message'] = '';
    // Write/update record
    if (!$node->webform_civicrm) {
      drupal_write_record('webform_civicrm_forms', $vals);
      drupal_set_message(t('CiviCRM processing for this form is now enabled.'));
    }
    else{
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

        if (($field['type'] == 'textfield' || $field['type'] == 'email') && !$field['extra']['width'])
          $field['extra']['width'] = 20;

        // Set default country
        if ($id == 'civicrm_country_id') {
          $config = webform_civicrm_get_config();
          $field['value'] = $config['defaultContactCountry'];
        }
        // Retrieve option list
        if (array_key_exists($id, $lists)) {
          $field['extra']['items'] = webform_civicrm_get_options($lists[$id]);
          if (!isset($field['extra']['aslist']))
            $field['extra']['aslist'] = 1;
        }
        // Create webform component
        $fid = webform_component_insert($field);

        if ($id == 'civicrm_groups') {
          drupal_set_message(t('Please choose which group(s) you want to be selectable by users of this form.'));
          $form_state['redirect'] = 'node/'. $nid .'/webform/components/'. $fid;
        }
        else
          $new_fields = TRUE;
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
        if ($items)
          $items .= "\n";
        $items .= $gid .'|'. $groups[$gid];
      }
    }
    $form_state['values']['extra']['items'] = $items;
  }
  $form_state['values']['value'] = '';
  foreach ($form_state['values']['civicrm_groups_fieldset']['civicrm_group_defaults'] as $gid) {
    if (!$gid) continue;
    if ($form_state['values']['value'])
      $form_state['values']['value'] .= ',';
    $form_state['values']['value'] .= $gid;
  }
}


/**
 * Recursively walk through form array and set default values for fields based on CiviCRM contact data
 * Called by _webform_civicrm_webform_frontend_form_alter() when webform is being viewed by a known contact
 * @Param $elements: FAPI array
 * @Param $contact: Array of CiviCRM contact data
 * Matching is done through 
 */
function webform_civicrm_fill_values(&$elements, $contact) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  foreach ($elements as $eid => &$element) {
    if (substr($eid, 0, 1) == '#' || !is_array($element))
      continue;
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

  $cid = webform_civicrm_contact_match($node, $submission);

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


/**
 * Alter back-end webform component edit forms.
 * Called by hook_form_alter() whenever editing a webform component.
 */
function _webform_civicrm_webform_component_form_alter(&$form) {
  // Is this a CiviCRM-enabled webform?
  if (db_result(db_query('SELECT nid FROM {webform_civicrm_forms} WHERE nid = %d', $form['nid']['#value']))) {
    civicrm_initialize();
    $fields = webform_civicrm_get_fields();
    $key = $form['form_key']['#default_value'];
    // Is this component a CiviCRM field?
    if (array_key_exists($key, $fields)) {

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
        if ($_GET['reload-data']) {
          $form['extra']['items']['#description'] .= '<div style="color:green; font-weight:bold; margin-top:0.5em;">'. $complete_msg .'</div>';
          $form['extra']['items']['#default_value'] = webform_civicrm_get_options($key);

        }
        else $form['extra']['items']['#description'] .= '<div id="civicrm-ajax" style="color:green; font-weight:bold; margin-top:0.5em;">'. l(t('Reload options'), $_GET['q'], array('query' => 'reload-data=1', 'attributes' => array('style' => 'color:blue;',
        'onclick' => "$(this).addClass('views-throbbing');
          $.ajax({
            url: '/webform-civicrm/js/". arg(1) ."/$key',
            success: function(data) {
              $('#edit-extra-items').val(data);
              $('#civicrm-ajax').html('$complete_msg');
            }
          }); return false;"))) .'</div>';
      }
      elseif ($key == 'civicrm_contact_id') {
        $form['value']['#type'] = 'hidden';
        $form['value']['#value'] = 0;
        $form['#prefix'] = '<p>' .t('There are no configuration options for this hidden field. You can use it for post processing, for example to include a link to the CiviCRM contact in an email.') .'</p>';
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
        $defaults = $groups;

        $form['civicrm_groups_fieldset'] = array(
          '#type'  => 'fieldset',
          '#title' => t('Groups'),
          '#description' => t('Which group(s) is the user allowed to join on this form? (Often used for letting people subscribe to mailing lists)'),
        );
        if ($key == 'civicrm_groups') {
          $form['civicrm_groups_fieldset']['civicrm_groups'] = array(
            '#title' => t('Allowed Groups'),
            '#type'  => 'checkboxes',
            '#required' => TRUE,
            '#options' => $groups,
            '#default_value' => $selected,
            '#prefix' => '<div style="float:left;">',
            '#suffix' => '</div>',
          );
          foreach ($defaults as &$v) {
            $v = '';
          }
        }
        $form['civicrm_groups_fieldset']['civicrm_group_defaults'] = array(
          '#title' => t('Checked by Default on Webform?'),
          '#type'  => 'checkboxes',
          '#options' => $defaults,
          '#default_value' => $defaults_selected,
        );
        if ($key == 'civicrm_groups') {
          $form['civicrm_groups_fieldset']['civicrm_group_defaults']['#prefix'] = '<div style="float:left;">';
          $form['civicrm_groups_fieldset']['civicrm_group_defaults']['#suffix'] = '</div>';
        }
        else {
          $form['civicrm_groups_fieldset']['#description'] = t('To which group(s) should users be automatically added when submitting this form?');
          $form['civicrm_groups_fieldset']['civicrm_group_defaults']['#title'] = t('Add to Group');
        }
        array_unshift($form['#submit'], 'webform_civicrm_process_group_selection');
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
 * Alter front-end of webforms: Called by hook_form_alter() whenever viewing a webform
 * Add custom prefix.
 * Display message.
 * Block unknown users.
 * Set webform default values.
 */
function _webform_civicrm_webform_frontend_form_alter(&$form) {
  $node = $form['#node'];
  if ($settings = $node->webform_civicrm) {
    civicrm_initialize();
    $enabled = webform_civicrm_enabled_fields($node);

    // If this is an edit op, use the original CID to avoid confusion
    if ($submission = $form['#submission']) {
      $cid = $submission->civicrm['contact_id'];
    }
    else {
      // If user is logged in, look up their CID
      global $user;
      if ($user->uid) {
        require_once 'CRM/Core/BAO/UFMatch.php';
        $cid = CRM_Core_BAO_UFMatch::getContactId($user->uid);
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
      $form['#prefix'] .= filter_filter('process', 1, NULL, $settings['prefix_unknown']);
      if ($settings['block_unknown_users']) {
        $form = array();
      }
      return;
    }
    require_once 'api/v2/Contact.php';
    $params = array('contact_id' => $cid);
    $contact = civicrm_contact_get($params);
    $fields = webform_civicrm_get_fields();
    $custom = FALSE;
    foreach ($fields as $id => $f) {
      if (strpos($id, 'custom')) {
        $custom = TRUE;
        $params[str_replace('civicrm', 'return', $id)] = 1;
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
    if ($submission) {
      return;
    }

    // Some translation of array key strings is necessary due to irregulatities in CiviCRM API V2
    $contact_info = array();
    foreach ($enabled as $id => $val) {
      $new_id = str_replace(array('civicrm_', 'prefix', 'suffix'), array('', 'individual_prefix', 'individual_suffix'), $id);
      if (($value = $contact[$new_id]) || isset($contact[$new_id])) {
        $contact_info[$id] = $value;
      }
    }

    // Set default values for group field
    if ($enabled['civicrm_groups']) {
      require_once 'api/v2/GroupContact.php';
      $params = array('contact_id' => $cid);
      $groups = civicrm_group_contact_get($params);
      $contact_info['civicrm_groups'] = array();
      foreach ($groups as $group) {
        $contact_info['civicrm_groups'][] = $group['group_id'];
      }
    }
    // Recurse through FAPI array and set default values
    webform_civicrm_fill_values($form['submitted'], $contact_info);

    if ($settings['message']) {
      webform_civicrm_set_message($settings['message'], $contact);
    }
  }
}