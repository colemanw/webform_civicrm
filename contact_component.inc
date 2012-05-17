<?php

/**
 * @file
 * CiviCRM contact webform component.
 */

module_load_include('inc', 'webform_civicrm', 'webform_civicrm_utils');

/**
 * Implements _webform_defaults_component().
 */
function _webform_defaults_civicrm_contact() {
  return array(
    'name' => '',
    'form_key' => NULL,
    'pid' => 0,
    'weight' => 0,
    'value' => '',
    'mandatory' => 0,
    'extra' => array(
      'items' => '',
      'width' => '',
      'field_prefix' => '',
      'field_suffix' => '',
      'search_prompt' => t('Search for an existing contact'),
      'none_prompt' => t('Create new contact'),
      'allow_create' => TRUE,
      'widget' => 'autocomplete',
      'unique' => 0,
      'title_display' => 0,
      'description' => '',
      'post_behavior' => 'hide',
      'attributes' => array(),
      'private' => FALSE,
      'filters' => array(
        'sub_type' => array(),
      ),
    ),
  );
}

/**
 * Implements _webform_edit_component().
 */
function _webform_edit_civicrm_contact($component) {
  civicrm_initialize();
  // Check for required library
  $path = libraries_get_path('tokeninput');
  if (!is_file(DRUPAL_ROOT . '/' . $path . '/src/jquery.tokeninput.js')) {
    drupal_set_message(t('Could not find the jQuery TokenInput library. Please <a href="http://loopj.com/jquery-tokeninput">download it</a> and extract as the folder "sites/all/libraries/tokeninput". This is required for the autocomplete to function.'), 'warning');
  }
  $node = node_load($component['nid']);
  if (empty($node->webform_civicrm)) {
    drupal_set_message(t('CiviCRM processing is not enabled for this webform.'), 'error');
    return;
  }
  $data = $node->webform_civicrm['data'];
  list(, $c, $ent, $n, $table, $name) = explode('_', $component['form_key'], 6);
  $contact_type = $data['contact'][$c]['contact'][1]['contact_type'];
  $form = array();
  $form['post_behavior'] = array(
    '#type' => 'select',
    '#title' => t('Selection Behavior'),
    '#default_value' => $component['extra']['post_behavior'],
    '#description' => t('If other contact fields (name, address, email, etc.) are exposed for this contact, how should they be handled when an existing contact is chosen?'),
    '#options' => array('hide' => t('Hide Fields'), 'disable' => t('Disable Fields')),
    '#weight' => -1,
    '#parents' => array('extra', 'post_behavior'),
  );
  $form['display']['widget'] = array(
    '#type' => 'select',
    '#title' => t('Widget Style'),
    '#default_value' => $component['extra']['widget'],
    '#description' => t('Note: Select lists cannot handle very large numbers of contacts and should only be used with heavy filtering.'),
    '#options' => array('autocomplete' => t('Autocomplete'), 'select' => t('Select List')),
    '#weight' => -2,
    '#parents' => array('extra', 'widget'),
  );
  $form['display']['search_prompt'] = array(
    '#type' => 'textfield',
    '#title' => t('Search Prompt'),
    '#default_value' => $component['extra']['search_prompt'],
    '#description' => t('Text the user will see when selecting a contact.'),
    '#size' => 60,
    '#maxlength' => 1024,
    '#weight' => -1,
    '#required' => TRUE,
    '#parents' => array('extra', 'search_prompt'),
  );
  $form['display']['none_prompt'] = array(
    '#type' => 'textfield',
    '#title' => t('Create/Not Found Prompt'),
    '#default_value' => $component['extra']['none_prompt'],
    '#description' => t('If you have exposed a name or email field, this text should prompt the user to create a new contact. Otherwise this text should tell the user that no search results were found.'),
    '#size' => 60,
    '#maxlength' => 1024,
    '#weight' => -1,
    '#required' => TRUE,
    '#parents' => array('extra', 'none_prompt'),
  );
  $form['validation']['unique'] = array(
    '#type' => 'checkbox',
    '#title' => t('Unique'),
    '#return_value' => 1,
    '#description' => t('Require this field to be unique for every submission. The same contact may not be entered twice.'),
    '#weight' => 1,
    '#default_value' => $component['extra']['unique'],
    '#parents' => array('extra', 'unique'),
  );
  $form['extra']['allow_create'] = array(
    '#type' => 'hidden',
    '#value' => webform_civicrm_name_field_exists(webform_civicrm_enabled_fields($node), $c),
  );
  $form['filters'] = array(
    '#type' => 'fieldset',
    '#title' => t('Filters'),
    '#description' => t('Use filters to limit the list of existing contacts.'),
    '#parents' => array('extra', 'filters'),
    '#tree' => TRUE,
    '#collapsible' => TRUE,
  );
  list($contact_types, $sub_types) = webform_civicrm_get_contact_types();
  if (!empty($sub_types[$contact_type])) {
    $form['filters']['sub_type'] = array(
      '#type' => 'select',
      '#title' => t('Type of @contact', array('@contact' => $contact_types[$contact_type])),
      '#multiple' => TRUE,
      '#options' => $sub_types[$contact_type],
      '#default_value' => $component['extra']['filters']['sub_type'],
    );
  }
  return $form;
}

/**
 * Implements _webform_render_component().
 */
function _webform_render_civicrm_contact($component, $value = NULL, $filter = TRUE) {
  $node = isset($component['nid']) ? node_load($component['nid']) : NULL;

  $element = array(
    '#title' => $filter ? _webform_filter_xss($component['name']) : $component['name'],
    '#title_display' => $component['extra']['title_display'] ? $component['extra']['title_display'] : 'before',
    '#required' => $component['mandatory'],
    '#weight' => $component['weight'],
    '#description' => $filter ? _webform_filter_descriptions($component['extra']['description'], $node) : $component['extra']['description'],
    '#attributes' => $component['extra']['attributes'],
    '#theme_wrappers' => array('webform_element'),
    '#translatable' => array('title', 'description'),
  );
  $element['#attributes']['id'] = $id = $component['form_key'];
  list(, $c,) = explode('_', $id, 3);

  if ($component['extra']['widget'] == 'autocomplete') {
    $element['#type'] = 'textfield';
    if ($node) {
      $path = libraries_get_path('tokeninput');
      $element['#attached']['js'][] = $path . '/src/jquery.tokeninput.js';
      $element['#attached']['css'][] = $path . '/styles/token-input.css';
      $element['#attached']['css'][] = drupal_get_path('module', 'webform_civicrm') . '/webform_civicrm_forms.css';
      $settings = '{
        queryParam: "str",
        hintText: "' . $component['extra']['search_prompt'] . '",
        noResultsText: "' . $component['extra']['none_prompt'] . '",
        searchingText: "' . t('Searching...') . '",
        tokenLimit: 1,
        onAdd: function(item) {webformCivicrmExistingSelect(' . $c . ', "' . $component['extra']['post_behavior'] . '", item.id);},
        onDelete: function(item) {webformCivicrmExistingSelect(' . $c . ', "' . $component['extra']['post_behavior'] . '", "");},
        onReady: function() {webformCivicrmExistingInit(' . $c . ', "' . $component['extra']['post_behavior'] . '");}
      }';
      $path = url('webform-civicrm/js/' . $node->nid . '-' . $component['cid'], array('alias' => TRUE));
      $js = '
      jQuery(document).ready(function() {
        jQuery("#' . $id . '").tokenInput("' . $path . '", ' . $settings . ');
      });';
      $element['#attached']['js'][$js] = array('type' => 'inline');
    }
  }
  else {
    $element['#type'] = 'select';
    $element['#empty_option'] = $component['extra']['search_prompt'];
    if ($node) {
      $element['#options'] = webform_civicrm_existing_contact_search($node, $component);
      $js = '
        jQuery(document).ready(function() {
          jQuery("#' . $id . '").change(function() {
            webformCivicrmExistingSelect(' . $c . ', "' . $component['extra']['post_behavior'] . '", jQuery(this).val());
          });
          webformCivicrmExistingInit(' . $c . ', "' . $component['extra']['post_behavior'] . '");
        });';
      $element['#attached']['js'][$js] = array('type' => 'inline');
    }
  }
  // Enforce uniqueness.
  if ($component['extra']['unique']) {
    $element['#element_validate'][] = 'webform_validate_unique';
  }

  if (isset($value)) {
    $element['#default_value'] = $value[0];
  }

  return $element;
}

/**
 * Implements _webform_display_component().
 */
function _webform_display_civicrm_contact($component, $value, $format = 'html') {
  return array(
    '#title' => $component['name'],
    '#weight' => $component['weight'],
    '#theme' => 'webform_display_textfield',
    '#theme_wrappers' => $format == 'html' ? array('webform_element') : array('webform_element_text'),
    '#field_prefix' => '',
    '#field_suffix' => '',
    '#format' => $format,
    '#value' => empty($value[0]) ? '' : webform_civicrm_display_name($value[0]),
    '#translatable' => array('title'),
  );
}

/**
 * Implements _webform_table_component().
 */
function _webform_table_civicrm_contact($component, $value) {
  return empty($value[0]) ? '' : check_plain(webform_civicrm_display_name($value[0]));
}

/**
 * Implements _webform_csv_headers_component().
 */
function _webform_csv_headers_civicrm_contact($component, $export_options) {
  $header = array();
  $header[0] = '';
  $header[1] = '';
  $header[2] = $component['name'];
  return $header;
}

/**
 * Implements _webform_csv_data_component().
 */
function _webform_csv_data_civicrm_contact($component, $export_options, $value) {
  return empty($value[0]) ? '' : webform_civicrm_display_name($value[0]);
}

/**
 * Returns a list of contacts based on component settings and filters.
 */
function webform_civicrm_existing_contact_search($node, $component, $str = NULL) {
  civicrm_initialize();
  if (empty($node->webform_civicrm)) {
    return array();
  }
  $data = $node->webform_civicrm['data'];
  list(, $c, ) = explode('_', $component['form_key'], 3);
  $contact_type = $data['contact'][$c]['contact'][1]['contact_type'];
  $filters = $component['extra']['filters'];
  $context = $component['extra']['widget'];
  $limit = $context == 'autocomplete' ? 20 : 200;
  $vars = $ret = array();
  $v = 1;
  $sql = "SELECT id, display_name FROM civicrm_contact WHERE contact_type = '$contact_type' AND is_deleted = 0";
  if ($str) {
    $sql .= " AND display_name LIKE %$v";
    $vars[$v++] = array("%$str%", 'String');
  }
  if ($sub_types = webform_civicrm_aval($filters, 'sub_type')) {
    $sp = CRM_Core_DAO::VALUE_SEPARATOR;
    $sql .= ' AND (';
    foreach(array_values($sub_types) as $i => $sub_type) {
      $sql .= ($i ? ' OR ' : '') . "contact_sub_type LIKE %$v";
      $vars[$v++] = array("%$sp$sub_type$sp%", 'String');
    }
    $sql .= ')';
  }
  $sql .= ' ORDER BY sort_name ';
  $sql .= " LIMIT $limit";
  $dao = &CRM_Core_DAO::executeQuery($sql, $vars);
  if ($context == 'autocomplete') {
    while ($dao->fetch()) {
      $ret[] = array('id' => $dao->id, 'name' => $dao->display_name);
    }
    $create = array('id' => 0, 'name' => $component['extra']['none_prompt']);
  }
  else {
    while ($dao->fetch()) {
      $ret[$dao->id] = $dao->display_name;
    }
    $create = $component['extra']['none_prompt'];
  }
  if (count($ret) < $limit) {
    
  }
  return $ret;
}

/**
 * Callback to serve AJAX requests.
 */
function webform_civicrm_js_options($key, $input = '') {
  // Populate state/prov lists on the fly
  if ($key == 'state_province') {
    if (!$input || (intval($input) != $input && $input != 'default')) {
      exit(drupal_json_output(array('' => t('- first choose a country -'))));
    }
    civicrm_initialize();
    exit(drupal_json_output(webform_civicrm_get_options('state_province', $input)));
  }
  // Populate contact autocomplete
  elseif (strpos($key, '-')) {
    if (empty($_GET['str'])) {
      exit();
    }
    list($nid, $cid) = explode('-', $key);
    $node = node_load($nid);
    if (!webform_civicrm_autocomplete_access($node, $cid)) {
      return drupal_access_denied();
    }
    $component = $node->webform['components'][$cid];
    if ($component['type'] == 'civicrm_contact') {
      exit(drupal_json_output(webform_civicrm_existing_contact_search($node, $component, $_GET['str'])));
    }
  }
  return drupal_access_denied();
}

/**
 * Check if a required name field exists for this contact.
 */
function webform_civicrm_name_field_exists($vals, $c) {
  foreach (webform_civicrm_required_contact_fields() as $f) {
    $fid = 'civicrm_' . $c . '_contact_1_ ' . $f['table'] . '_' . $f['name'];
    if (!empty($vals[$fid])) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * Access callback. Check if user has permission to view autocomplete results.
 */
function webform_civicrm_autocomplete_access($node, $cid) {
  global $user;
  if (!$cid || empty($node->webform['components'][$cid]) || !node_access('view', $node)) {
    return FALSE;
  }
  if ($user->uid === 1 || webform_results_access($node)) {
    return TRUE;
  }
  if (!empty($node->webform['components'][$cid]['private'])) {
    return FALSE;
  }
  if (variable_get('webform_submission_access_control', 1)) {
    foreach ($node->webform['roles'] as $rid) {
      $allowed_roles[$rid] = isset($user->roles[$rid]) ? TRUE : FALSE;
    }
    if (array_search(TRUE, $allowed_roles) === FALSE) {
      return FALSE;
    }
  }
  return TRUE;
}
