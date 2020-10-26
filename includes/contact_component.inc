<?php

/**
 * @file
 * CiviCRM contact webform component.
 */

module_load_include('inc', 'webform_civicrm', 'includes/utils');
module_load_include('inc', 'webform_civicrm', 'includes/wf_crm_admin_help');

/**
 * Implements _webform_defaults_component().
 */
function _webform_defaults_civicrm_contact() {
  return [
    'name' => '',
    'form_key' => NULL,
    'pid' => 0,
    'weight' => 0,
    'value' => '',
    'required' => 0,
    'extra' => [
      'search_prompt' => '',
      'none_prompt' => '',
      'results_display' => ['display_name'],
      'allow_create' => 0,
      'widget' => 'autocomplete',
      'show_hidden_contact' => 0,
      'unique' => 0,
      'title_display' => 'before',
      'randomize' => 0,
      'description' => '',
      'no_autofill' => [],
      'hide_fields' => [],
      'hide_method' => 'hide',
      'no_hide_blank' => FALSE,
      'submit_disabled' => FALSE,
      'attributes' => [],
      'private' => FALSE,
      'default' => '',
      'default_contact_id' => '',
      'default_relationship' => '',
      'default_relationship_to' => '1',
      'default_custom_ref' => '',
      'default_for_case' => '1',
      'default_case_roles' => '',
      'allow_url_autofill' => TRUE,
      'dupes_allowed' => FALSE,
      'filters' => [
        'contact_sub_type' => 0,
        'group' => [],
        'tag' => [],
        'relationship' => [],
        'check_permissions' => 1,
      ],
    ],
  ];
}

/**
 * Implements _webform_edit_component().
 */
function _webform_edit_civicrm_contact($component) {
  civicrm_initialize();
  $form = [];
  $node = node_load($component['nid']);
  if (empty($node->webform_civicrm) && node_is_page($node)) {
    drupal_set_message(t('CiviCRM processing is not enabled for this webform.'), 'error');
    return $form;
  }
  list($contact_types, $sub_types) = wf_crm_get_contact_types();
  $data = $node->webform_civicrm['data'];
  $enabled = wf_crm_enabled_fields($node);
  list(, $c, ) = explode('_', $component['form_key'], 3);
  $contact_type = $data['contact'][$c]['contact'][1]['contact_type'];
  wf_crm_update_existing_component($component, $enabled, $data, $node);
  $allow_create = $component['extra']['allow_create'];

  // Load scripts & css
  $form['#attached']['js'][] = wf_crm_tokeninput_path();
  $form['#attached']['js'][] = drupal_get_path('module', 'webform_civicrm') . '/js/webform_civicrm_admin.js';
  CRM_Core_Resources::singleton()->addCoreResources();
  $form['#suffix'] = wf_crm_admin_help::helpTemplate();
  $callback_path = '"' . url('webform-civicrm/js/' . $node->nid . '-' . $component['cid'], ['alias' => TRUE, 'query' => ['admin' => 1]]) . '"';
  $settings = '{
    queryParam: "str",
    hintText: "' . t('Choose @type', ['@type' => $contact_types[$contact_type]]) . '",
    noResultsText: "' . t('Not found') . '",
    searchingText: "' . t('Searching...') . '",
    tokenLimit: 1,
    prePopulate: prep
  }';
  $js = '
  jQuery(function($) {
    var prep = wfCiviContact.init(' . $callback_path . ');
    $("#default-contact-id").tokenInput(' . $callback_path . ', ' . $settings . ');
  });';
  $form['#attached']['js'][$js] = ['type' => 'inline'];
  $form['#attached']['css'][] = drupal_get_path('module', 'webform_civicrm') . '/css/token-input.css';
  $form['#attached']['css'][] = drupal_get_path('module', 'webform_civicrm') . '/css/webform_civicrm_admin.css';
  $form['#attached']['js'][] = drupal_get_path('module', 'webform_civicrm') . '/js/contact_component.js';

  $form['display']['widget'] = [
    '#type' => 'select',
    '#title' => t('Form Widget'),
    '#default_value' => $component['extra']['widget'],
    '#options' => ['autocomplete' => t('Autocomplete'), 'select' => t('Select List'), 'hidden' => t('Static'), 'textfield' => t('Enter Contact ID')],
    '#weight' => -9,
    '#parents' => ['extra', 'widget'],
  ];
  $status = $allow_create ? t('<strong>Contact Creation: Enabled</strong> - this contact has name/email fields on the webform.') : t('<strong>Contact Creation: Disabled</strong> - no name/email fields for this contact on the webform.');
  $form['display']['#description'] = '<div class="messages ' . ($allow_create ? 'status' : 'warning') . '">' . $status . ' ' . wf_crm_admin_help::helpIcon('contact_creation', t('Contact Creation')) . '</div>';
  $form['display']['search_prompt'] = [
    '#type' => 'textfield',
    '#title' => t('Search Prompt'),
    '#default_value' => $component['extra']['search_prompt'],
    '#description' => t('Text the user will see before selecting a contact.'),
    '#size' => 60,
    '#maxlength' => 1024,
    '#weight' => -7,
    '#parents' => ['extra', 'search_prompt'],
  ];
  $form['display']['none_prompt'] = [
    '#type' => 'textfield',
    '#title' => $allow_create ? t('Create Prompt') : t('Not Found Prompt'),
    '#default_value' => $component['extra']['none_prompt'],
    '#description' => $allow_create ? t('This text should prompt the user to create a new contact.') : t('This text should tell the user that no search results were found.'),
    '#size' => 60,
    '#maxlength' => 1024,
    '#weight' => -6,
    '#parents' => ['extra', 'none_prompt'],
  ];
  $form['display']['results_display'] = [
    '#type' => 'select',
    '#multiple' => TRUE,
    '#title' => t("Contact Display Field(s)"),
    '#required' => TRUE,
    '#default_value' => $component['extra']['results_display'],
    '#options' => wf_crm_results_display_options($contact_type),
    '#parents' => ['extra', 'results_display'],
  ];
  $form['display']['show_hidden_contact'] = [
    '#type' => 'radios',
    '#title' => t('Display Contact Name'),
    '#description' => t('If enabled, this static element will show the contact that has been pre-selected (or else the Create/Not Found Prompt if set). Otherwise the element will not be visible.'),
    '#options' => [t('No'), t('Yes')],
    '#default_value' => $component['extra']['show_hidden_contact'],
    '#parents' => ['extra', 'show_hidden_contact'],
    '#weight' => -5,
  ];
  $form['field_handling'] = [
    '#type' => 'fieldset',
    '#collapsible' => TRUE,
    '#title' => t('Contact Field Handling'),
    '#description' => t('By default, all contact data will be autopopulated on the form for this existing contact. You can modify that behavior here.'),
  ];
  $form['field_handling']['no_autofill'] = [
    '#type' => 'select',
    '#multiple' => TRUE,
    '#title' => t('Skip Autofilling of'),
    '#description' => t('Which fields should <em>not</em> be autofilled for this contact?'),
    '#default_value' => $component['extra']['no_autofill'],
    '#options' => ['' => '- ' . t('None') . ' -'] + wf_crm_contact_fields($node, $c),
    '#parents' => ['extra', 'no_autofill'],
  ];
  $form['field_handling']['hide_fields'] = [
    '#type' => 'select',
    '#multiple' => TRUE,
    '#title' => t('Fields to Lock'),
    '#description' => t('Prevent editing by disabling or hiding fields when a contact already exists.'),
    '#default_value' => $component['extra']['hide_fields'],
    '#options' => ['' => '- ' . t('None') . ' -'] + wf_crm_contact_fields($node, $c),
    '#parents' => ['extra', 'hide_fields'],
  ];
  $form['field_handling']['hide_method'] = [
    '#type' => 'select',
    '#title' => t('Locked fields should be'),
    '#default_value' => $component['extra']['hide_method'],
    '#options' => ['hide' => t('Hidden'), 'disable' => t('Disabled')],
    '#parents' => ['extra', 'hide_method'],
  ];
  $form['field_handling']['no_hide_blank'] = [
    '#type' => 'checkbox',
    '#title' => t("Don't lock fields that are empty"),
    '#default_value' => $component['extra']['no_hide_blank'],
    '#parents' => ['extra', 'no_hide_blank'],
  ];
  $form['field_handling']['submit_disabled'] = [
    '#type' => 'checkbox',
    '#title' => t("Submit disabled field value(s)"),
    '#description' => t('Store disabled field value(s) in webform submissions.'),
    '#default_value' => $component['extra']['submit_disabled'],
    '#parents' => ['extra', 'submit_disabled'],
  ];
  $form['validation']['unique'] = [
    '#type' => 'checkbox',
    '#title' => t('Unique'),
    '#return_value' => 1,
    '#description' => t('Require this field to be unique for every submission. The same contact may not be entered twice.'),
    '#weight' => 1,
    '#default_value' => $component['extra']['unique'],
    '#parents' => ['extra', 'unique'],
  ];
  $form['extra']['allow_create'] = [
    '#type' => 'hidden',
    '#value' => $allow_create,
  ];
  $form['defaults'] = [
    '#type' => 'fieldset',
    '#title' => t('Default value'),
    '#description' => t('Should the form be pre-populated with an existing contact?<ul><li>Any filters set below will restrict this default.</li><li>If more than one contact meets the criteria, the first match will be picked. If multiple existing contact fields exist on the webform, each will select a different contact.</li></ul>'),
    '#collapsible' => TRUE,
  ];
  $form['defaults']['default'] = [
    '#type' => 'select',
    '#title' => t('Set default contact from'),
    '#options' => ['contact_id' => t('Specified Contact')],
    '#empty_option' => t('- None -'),
    '#default_value' => $component['extra']['default'],
    '#parents' => ['extra', 'default'],
  ];
  if ($c == 1 && $contact_type == 'individual') {
    $form['defaults']['default']['#options']['user'] = t('Current User');
  }
  elseif ($c > 1) {
    $form['defaults']['default']['#options']['relationship'] = t('Relationship to...');
    $to = $component['extra']['default_relationship_to'];
    $form['defaults']['default_relationship_to'] = [
      '#type' => 'select',
      '#title' => t('Relationship to Contact'),
      '#default_value' => $to,
      '#parents' => ['extra', 'default_relationship_to'],
    ];
    $form['defaults']['default_relationship'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => t('Specify Relationship(s)'),
      '#options' => ['-' => '-'],
      '#required' => TRUE,
      '#default_value' => $component['extra']['default_relationship'],
      '#parents' => ['extra', 'default_relationship'],
    ];
  }
  $custom_ref_options = wf_crm_get_custom_ref_options();
  if ($custom_ref_options) {
    $form['defaults']['default']['#options']['custom_ref'] = t('Custom reference field');
    $form['defaults']['default_custom_ref'] = [
      '#type' => 'select',
      '#title' => t('Specify Custom Field'),
      '#options' => $custom_ref_options,
      '#required' => TRUE,
      '#default_value' => $component['extra']['default_custom_ref'],
      '#parents' => ['extra', 'default_custom_ref'],
    ];
  }

  $case_count = isset($data['case']['number_of_case']) ? $data['case']['number_of_case'] : 0;
  if ($case_count > 0) {
    $form['defaults']['default']['#options']['case_roles'] = t('Case roles');
    $dc = $component['extra']['default_relationship_to'];
    $form['defaults']['default_for_case'] = [
      '#type' => 'select',
      '#title' => t('For Case'),
      '#default_value' => $dc,
      '#parents' => ['extra', 'default_for_case'],
    ];
    $form['defaults']['default_case_roles'] = [
      '#type' => 'select',
      '#title' => t('Specify Case Role(s)'),
      '#options' => wf_crm_get_case_roles_options(),
      '#required' => TRUE,
      '#default_value' => $component['extra']['default_case_roles'],
      '#parents' => ['extra', 'default_case_roles'],
    ];
    $case_type = [];
    for ($i = 1; $i <= $case_count; $i++) {
      $form['defaults']['default_for_case']['#options'][$i] = 'Case ' . $i;
    }
  }

  $form['defaults']['default']['#options']['auto'] = t('Auto - From Filters');
  $form['defaults']['default_contact_id'] = [
    '#type' => 'textfield',
    '#title' => t('Contact'),
    '#id' => 'default-contact-id',
    '#parents' => ['extra', 'default_contact_id'],
  ];
  $cid = $component['extra']['default_contact_id'];
  if ($cid) {
    if ($name = wf_crm_contact_access($component, ['check_permissions' => 1], $cid)) {
      $form['defaults']['default_contact_id']['#default_value'] = $cid;
      $form['defaults']['default_contact_id']['#attributes'] = [
        'data-civicrm-name' => $name,
        'data-civicrm-id' => $cid,
      ];
    }
  }
  $form['defaults']['allow_url_autofill'] = [
    '#type' => 'checkbox',
    '#title' => t('Use contact id from URL'),
    '#default_value' => $component['extra']['allow_url_autofill'],
    '#parents' => ['extra', 'allow_url_autofill'],
    '#description' => t('If the url contains e.g. %arg, it will be used to pre-populate this contact (takes precidence over other default values).', ['%arg' => "cid$c=123"]),
  ];
  if ($c > 1) {
    $form['defaults']['dupes_allowed'] = [
      '#type' => 'checkbox',
      '#title' => t('Allow Duplicate Autofill'),
      '#default_value' => $component['extra']['dupes_allowed'],
      '#parents' => ['extra', 'dupes_allowed'],
      '#description' => t('Check this box to allow a contact to be selected even if they already autofilled a prior field on the form. (For example, if contact 1 was autofilled with Bob Smith, should this field also be allowed to select Bob Smith or should it pick a different contact?)'),
    ];
  }
  $form['defaults']['randomize'] = [
    '#type' => 'checkbox',
    '#title' => t('Randomize'),
    '#default_value' => $component['extra']['randomize'],
    '#parents' => ['extra', 'randomize'],
    '#description' => t('Pick a contact at random if more than one meets criteria.'),
  ];
  $form['filters'] = [
    '#type' => 'fieldset',
    '#title' => t('Filters'),
    '#description' => t('Only contacts meeting filter criteria will be available as select options or default value.<br />Note: Filters only apply to how a contact is chosen on the form, they do not affect how a contact is saved.'),
    '#parents' => ['extra', 'filters'],
    '#tree' => TRUE,
    '#collapsible' => TRUE,
  ];
  if (!empty($sub_types[$contact_type])) {
    $form['filters']['contact_sub_type'] = [
      '#type' => 'select',
      '#title' => t('Type of @contact', ['@contact' => $contact_types[$contact_type]]),
      '#options' => [t('- Any -')] + $sub_types[$contact_type],
      '#default_value' => $component['extra']['filters']['contact_sub_type'],
    ];
  }
  $form['filters']['group'] = [
    '#type' => 'select',
    '#multiple' => TRUE,
    '#title' => t('Groups'),
    '#options' => ['' => '- ' . t('None') . ' -'] + wf_crm_apivalues('group_contact', 'getoptions', ['field' => 'group_id']),
    '#default_value' => $component['extra']['filters']['group'],
    '#description' => t('Listed contacts must be members of at least one of the selected groups (leave blank to not filter by group).'),
  ];
  $tags = [];
  $form['filters']['tag'] = [
    '#type' => 'select',
    '#multiple' => TRUE,
    '#title' => t('Tags'),
    '#options' => ['' => '- ' . t('None') . ' -'] + wf_crm_get_tags('contact'),
    '#default_value' => $component['extra']['filters']['tag'],
    '#description' => t('Listed contacts must be have at least one of the selected tags (leave blank to not filter by tag).'),
  ];
  if ($c > 1) {
    $form['filters']['relationship']['contact'] = [
      '#type' => 'select',
      '#title' => t('Relationships to'),
      '#options' => ['' => '- ' . t('None') . ' -'],
      '#default_value' => wf_crm_aval($component['extra']['filters'], 'relationship:contact'),
    ];
    $form['filters']['relationship']['type'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => t('Specify Relationship(s)'),
      '#options' => ['' => '- ' . t('Any relation') . ' -'],
      '#default_value' => wf_crm_aval($component['extra']['filters'], 'relationship:type'),
    ];
    // Fill relationship data for defaults and filters
    $all_relationship_types = array_fill(1, $c - 1, []);
    for ($i = 1; $i < $c; ++$i) {
      $form['defaults']['default_relationship_to']['#options'][$i] = $form['filters']['relationship']['contact']['#options'][$i] = wf_crm_contact_label($i, $data, 'plain');
      $rtypes = wf_crm_get_contact_relationship_types($contact_type, $data['contact'][$i]['contact'][1]['contact_type'], $data['contact'][$c]['contact'][1]['contact_sub_type'], $data['contact'][$i]['contact'][1]['contact_sub_type']);
      foreach ($rtypes as $k => $v) {
        $all_relationship_types[$i][] = ['key' => $k, 'value' => $v . ' ' . wf_crm_contact_label($i, $data, 'plain')];
        $form['defaults']['default_relationship']['#options'][$k] = $form['filters']['relationship']['type']['#options'][$k] = $v . ' ' . wf_crm_contact_label($i, $data, 'plain');
      }
      if (!$rtypes) {
        $all_relationship_types[$i][] = ['key' => '', 'value' => '- ' . t('No relationship types defined for @a to @b', ['@a' => $contact_types[$contact_type], '@b' => $contact_types[$data['contact'][$i]['contact'][1]['contact_type']]]) . ' -'];
      }
    }
    $form['#attributes']['data-reltypes'] = json_encode($all_relationship_types);
  }
  $form['filters']['check_permissions'] = [
    '#type' => 'checkbox',
    '#title' => t('Enforce Permissions'),
    '#default_value' => $component['extra']['filters']['check_permissions'],
    '#description' => t('Only show contacts the acting user has permission to see in CiviCRM.') . '<br />' . t('WARNING: Keeping this option enabled is highly recommended unless you are effectively controlling access by another method.'),
  ];
  wf_crm_contact_component_form_help($form);
  return $form;
}

/**
 * Adds help to the form
 *
 * @param $form
 */
function wf_crm_contact_component_form_help(&$form) {
  foreach (element_children($form) as $element) {
    if (is_array($form[$element])) {
      wf_crm_contact_component_form_help($form[$element]);
      if (method_exists('wf_crm_admin_help', "contact_component_$element")) {
        wf_crm_admin_help::addHelp($form[$element], "contact_component_$element");
      }
    }
  }
}

/**
 * Implements _webform_render_component().
 */
function _webform_render_civicrm_contact($component, $value = NULL, $filter = TRUE) {
  $node = isset($component['nid']) ? node_load($component['nid']) : NULL;
  civicrm_initialize();
  list(, $c, ) = explode('_', $component['form_key'], 3);
  $hide_fields = array_filter($component['extra']['hide_fields']);
  $hide_method = wf_crm_aval($component['extra'], 'hide_method', 'hide');
  $no_hide_blank = (int) wf_crm_aval($component['extra'], 'no_hide_blank', 0);
  $js = '';
  $element = [
    '#type' => $component['extra']['widget'] == 'autocomplete' ? 'textfield' : $component['extra']['widget'],
    '#weight' => $component['weight'],
    '#attributes' => $component['extra']['attributes'],
  ];
  $element['#attributes']['data-hide-method'] = $hide_method;
  $element['#attributes']['data-no-hide-blank'] = $no_hide_blank;
  if ($component['extra']['widget'] != 'hidden' || $component['extra']['show_hidden_contact']) {
    $element += [
      '#title' => $filter ? webform_filter_xss($component['name']) : $component['name'],
      '#title_display' => $component['extra']['title_display'] ? $component['extra']['title_display'] : 'before',
      '#required' => $component['required'],
      '#description' => $filter ? webform_filter_descriptions($component['extra']['description'], $node) : $component['extra']['description'],
      '#translatable' => ['title', 'description'],
      '#theme_wrappers' => ['webform_element'],
    ];
  }
  // See if there are any fields to fetch via ajax
  $other_fields = wf_crm_contact_fields($node, $c);
  if ($hide_method == 'hide') {
    foreach ($hide_fields as $h) {
      unset($other_fields[$h]);
    }
  }
  $defaults = json_encode(wf_crm_get_defaults($node));
  $ajax = $other_fields ? 'true' : 'false';
  $callback_path = '"' . url('webform-civicrm/js/' . $node->nid . '-' . $component['cid'], ['alias' => TRUE]) . '"';
  $onChange = "field.change(function() {
                  wfCivi.existingSelect($c, {$node->nid}, $callback_path, toHide, '$hide_method', $no_hide_blank, \$(this).val(), $ajax, $defaults);
                });";

  // Render component based on type
  switch ($component['extra']['widget']) {

    case 'autocomplete':
      if ($node && isset($node->webform_civicrm)) {
        $element['#attached']['js'][] = wf_crm_tokeninput_path();
        $element['#attached']['css'][] = drupal_get_path('module', 'webform_civicrm') . '/css/token-input.css';
        $settings = '{
          hintText: ' . json_encode(filter_xss($component['extra']['search_prompt'])) . ',
          noResultsText: ' . json_encode(filter_xss($component['extra']['none_prompt'])) . ',
          searchingText: ' . json_encode(t('Searching...')) . '
        }';
        $js = "wfCivi.existingInit(field, $c, {$node->nid}, $callback_path, toHide, $settings);$onChange";
      }
      break;

    case 'select':
      // Options will be set by wf_crm_fill_contact_value.
      $element['#options'] = [];
      if ($node && isset($node->webform_civicrm)) {
        $js = "$onChange wfCivi.existingInit(field, $c, {$node->nid}, $callback_path, toHide);";
      }
      break;

    case 'hidden':
      if (!empty($value[0])) {
        $element['#value'] = $value[0];
      }
      if ($node && isset($node->webform_civicrm)) {
        $js = $onChange;
        if ($component['extra']['show_hidden_contact']) {
          $element['#attached']['css'][] = drupal_get_path('module', 'webform_civicrm') . '/css/token-input.css';
        }
        if ($hide_fields) {
          $js .= "wfCivi.existingInit(field, $c, {$node->nid}, $callback_path, toHide);";
        }
        $element['#theme_wrappers'] = ['static_contact_element'];
        if ($component['required']) {
          $element['#element_validate'] = ['wf_crm_contact_component_required'];
        }
      }
      break;

    case 'textfield':
      $element['#description'] = t('Lookup by Contact ID.');
      $element['#size'] = 8;
      if ($node && isset($node->webform_civicrm)) {
        $js = "$onChange wfCivi.existingInit(field, $c, {$node->nid}, $callback_path, toHide);";
      }
      // Display empty option unless there are no results
      if (!$component['extra']['allow_create'] || count($element['#options']) > 1) {
        $element['#empty_option'] = $component['extra'][$element['#options'] ? 'search_prompt' : 'none_prompt'];
      }

  }
  // Add inline javascript
  if ($js) {
    $js = '
      (function ($, D) {
        D.behaviors.webform_civicrm_' . $node->nid . '_' . $component['cid'] . ' = {
          attach: function (context) {
            var toHide = ' . json_encode(array_values($hide_fields)) . ';
            var field = $(\'.webform-client-form-' . $component['nid'] . ' :input.civicrm-enabled[name$="' . $component['form_key'] . ']"]\', context);
            ' . $js .'
          }
        };
      })(jQuery, Drupal);';
    $element['#attached']['js'][$js] = ['type' => 'inline'];
  }

  // Enforce uniqueness.
  if ($component['extra']['unique']) {
    $element['#element_validate'][] = 'webform_validate_unique';
  }
  if ($cid = wf_crm_aval($value, 0)) {
    $element['#default_value'] = $cid;
  }
  wf_crm_fill_contact_value($node, $component, $element);

  return $element;
}

/**
 * Lookup contact name from ID, verify permissions, and attach as html data.
 *
 * Used when rendering or altering a CiviCRM contact field.
 *
 * Also sets options for select lists.
 *
 * @param stdClass $node
 *   Node object
 * @param array $component
 *   Webform component
 * @param array $element
 *   FAPI form element (reference)
 * @param array $ids
 *   Known entity ids
 */
function wf_crm_fill_contact_value($node, $component, &$element, $ids = NULL) {
  $cid = wf_crm_aval($element, '#default_value', '');
  if ($element['#type'] == 'hidden') {
    if (!$component['extra']['show_hidden_contact']) {
      return;
    }
  }
  if ($cid) {
    // Don't lookup same contact again
    if (wf_crm_aval($element, '#attributes:data-civicrm-id') != $cid) {
      $filters = wf_crm_search_filters($node, $component);
      $name = wf_crm_contact_access($component, $filters, $cid);
      if ($name !== FALSE) {
        $element['#attributes']['data-civicrm-name'] = $name;
        $element['#attributes']['data-civicrm-id'] = $cid;
      }
      else {
        unset($cid);
      }
    }
  }
  if (empty($cid) && $element['#type'] == 'hidden' && $component['extra']['none_prompt']) {
    $element['#attributes']['data-civicrm-name'] = filter_xss($component['extra']['none_prompt']);
  }
  // Set options list for select elements. We do this here so we have access to entity ids.
  if (is_array($ids) && $element['#type'] == 'select') {
    $filters = wf_crm_search_filters($node, $component);
    $element['#options'] = wf_crm_contact_search($node, $component, $filters, wf_crm_aval($ids, 'contact', []));
    // Display empty option unless there are no results
    if (!$component['extra']['allow_create'] || count($element['#options']) > 1) {
      $element['#empty_option'] = filter_xss($component['extra'][$element['#options'] ? 'search_prompt' : 'none_prompt']);
    }
  }
}

/**
 * Implements _webform_display_component().
 */
function _webform_display_civicrm_contact($component, $value, $format = 'html') {
  $display = empty($value[0]) ? '' : wf_crm_display_name($value[0]);
  if ($format == 'html' && $display && user_access('access CiviCRM')) {
    $display = l($display, 'civicrm/contact/view', [
      'alias' => TRUE,
      'query' => [
        'reset' => 1,
        'cid' => $value[0],
      ],
    ]);
  }
  return [
    '#title' => $component['name'],
    '#weight' => $component['weight'],
    '#theme' => 'display_civicrm_contact',
    '#theme_wrappers' => $format == 'html' ? ['webform_element'] : ['webform_element_text'],
    '#field_prefix' => '',
    '#field_suffix' => '',
    '#format' => $format,
    '#value' => $display,
    '#translatable' => ['title'],
  ];
}

/**
 * Theme function.
 * Format the output of data for this component.
 */
function theme_display_civicrm_contact($variables) {
  $element = $variables['element'];
  $prefix = $element['#format'] == 'html' ? '' : $element['#field_prefix'];
  $suffix = $element['#format'] == 'html' ? '' : $element['#field_suffix'];
  return $element['#value'] !== '' ? ($prefix . $element['#value'] . $suffix) : ' ';
}

/**
 * Implements _webform_table_component().
 */
function _webform_table_civicrm_contact($component, $value) {
  return empty($value[0]) ? '' : check_plain(wf_crm_display_name($value[0]));
}

/**
 * Implements _webform_csv_headers_component().
 */
function _webform_csv_headers_civicrm_contact($component, $export_options) {
  $header = [];
  $header[0] = '';
  $header[1] = '';
  $header[2] = $component['name'];
  return $header;
}

/**
 * Implements _webform_csv_data_component().
 */
function _webform_csv_data_civicrm_contact($component, $export_options, $value) {
  return empty($value[0]) ? '' : wf_crm_display_name($value[0]);
}

/**
 * Returns a list of contacts based on component settings.
 *
 * @param stdClass $node
 *   Node object
 * @param array $component
 *   Webform component
 * @param array $params
 *   Contact get params (filters)
 * @param array $contacts
 *   Existing contact data
 * @param string $str
 *   Search string (used during autocomplete)
 *
 * @return array
 */
function wf_crm_contact_search($node, $component, $params, $contacts, $str = NULL) {
  if (empty($node->webform_civicrm)) {
    return [];
  }
  $limit = $str ? 12 : 500;
  $ret = [];
  $display_fields = array_values($component['extra']['results_display']);
  $search_field = 'display_name';
  $sort_field = 'sort_name';
  // Search and sort based on the selected display field
  if (!in_array('display_name', $display_fields)) {
    $search_field = $sort_field = $display_fields[0];
  }
  $params += [
    'rowCount' => $limit,
    'sort' => $sort_field,
    'return' => $display_fields,
  ];
  if (!empty($params['relationship']['contact'])) {
    $c = $params['relationship']['contact'];
    $relations = NULL;
    if (!empty($contacts[$c]['id'])) {
      $relations = wf_crm_find_relations($contacts[$c]['id'], wf_crm_aval($params['relationship'], 'type'));
      $params['id'] = ['IN' => $relations];
    }
    if (!$relations) {
      return $ret;
    }
  }
  unset($params['relationship']);
  if ($str) {
    $str = str_replace(' ', '%', CRM_Utils_Type::escape($str, 'String'));
    // The contact api takes a quirky format for display_name and sort_name
    if (in_array($search_field, ['sort_name', 'display_name'])) {
      $params[$search_field] = $str;
    }
    // Others use the standard convention
    else {
      $params[$search_field] = ['LIKE' => "%$str%"];
    }
  }
  $result = wf_civicrm_api('contact', 'get', $params);
  // Autocomplete results
  if ($str) {
    foreach (wf_crm_aval($result, 'values', []) as $contact) {
      if ($name = wf_crm_format_contact($contact, $display_fields)) {
        $ret[] = ['id' => $contact['id'], 'name' => $name];
      }
    }
    if (count($ret) < $limit && $component['extra']['allow_create']) {
      // HTML hack to get prompt to show up different than search results
      $ret[] = ['id' => "-$str", 'name' => '<em><i>' . filter_xss($component['extra']['none_prompt']) . '</i></em>'];
    }
  }
  // Select results
  else {
    if ($component['extra']['allow_create']) {
      $ret['-'] = filter_xss($component['extra']['none_prompt']);
    }
    foreach (wf_crm_aval($result, 'values', []) as $contact) {
      // Select lists will be escaped by FAPI
      if ($name = wf_crm_format_contact($contact, $display_fields, FALSE)) {
        $ret[$contact['id']] = $name;
      }
    }
    // If we get exactly $limit results, there are probably more - warn that the list is truncated
    if (wf_crm_aval($result, 'count') >= $limit) {
      watchdog(
        'webform_civicrm',
        'Maximum contacts exceeded, list truncated on the webform "@title". The webform_civicrm "@field" field cannot display more than !limit contacts because it is a select list. Recommend switching to autocomplete widget in component settings.',
        ['!limit' => $limit, '@field' => $component['name'], '@title' => $node->title],
        WATCHDOG_WARNING,
        l(t('Edit component'), "node/{$node->nid}/webform/components/{$component['cid']}")
      );
      if (wf_crm_admin_access($node) && node_is_page($node)) {
        drupal_set_message(
          '<strong>' . t('Maximum contacts exceeded, list truncated.') .'</strong><br>' .
          t('The field "@field" cannot show more than !limit contacts because it is a select list. Recommend switching to autocomplete widget in <a !link>component settings</a>.',
            ['!limit' => $limit, '@field' => $component['name'], '!link' => 'href="' . url("node/{$node->nid}/webform/components/{$component['cid']}") . '"']),
          'warning'
        );
      }
    }
  }
  return $ret;
}

/**
 * Load contact name if user has permission. Else return FALSE.
 *
 * @param $component
 *   Webform component of type 'civicrm_contact'
 * @param $filters
 *   Contact get params
 * @param $cid
 *   Contact id
 *
 * @return bool|string
 */
function wf_crm_contact_access($component, $filters, $cid) {
  // Create new contact doesn't require lookup
  $cid = "$cid";
  list(, $c, ) = explode('_', $component['form_key'], 3);
  if ($cid && $cid[0] === '-' && !empty($component['extra']['allow_create']) && !empty($component['extra']['none_prompt'])) {
    return filter_xss($component['extra']['none_prompt']);
  }
  if (!is_numeric($cid)) {
    return FALSE;
  }
  $filters['id'] = $cid;
  $filters['is_deleted'] = 0;
  // A contact always has permission to view self
  if ($cid == wf_crm_user_cid()) {
    $filters['check_permissions'] = FALSE;
  }
  if (!empty($filters['check_permissions'])) {
    // If we have a valid checksum for this contact, bypass other permission checks
    // For legacy reasons we support "cid" param as an alias of "cid1"
    if (wf_crm_aval($_GET, "cid$c") == $cid || $c == 1 && wf_crm_aval($_GET, "cid") == $cid) {
      // For legacy reasons we support "cs" param as an alias of "cs1"
      if (
        (!empty($_GET['cs']) && $c == 1 && CRM_Contact_BAO_Contact_Utils::validChecksum($cid, $_GET['cs']))
        || (!empty($_GET["cs$c"]) && CRM_Contact_BAO_Contact_Utils::validChecksum($cid, $_GET["cs$c"]))
      ) {
        $filters['check_permissions'] = FALSE;
        // Grant CiviCRM permissions to the verified checksum contact.
        if ($c == 1) {
          CRM_Core_Session::singleton()->set('userID', $cid);
        }
      }
    }
  }
  // Fetch contact name with filters applied
  $result = wf_civicrm_api('contact', 'get', $filters);
  return wf_crm_format_contact(wf_crm_aval($result, "values:$cid"), $component['extra']['results_display']);
}

/**
 * Display a contact based on chosen fields
 *
 * @param array $contact
 * @param array $display_fields
 * @param bool $escape
 * @return bool|string
 */
function wf_crm_format_contact($contact, $display_fields, $escape = TRUE) {
  if (!$contact) {
    return FALSE;
  }
  $display = [];
  foreach ($display_fields as $field) {
    if ($field && !empty($contact[$field])) {
      $display[] = $escape ? check_plain($contact[$field]) : $contact[$field];
    }
  }
  return implode(' :: ', $display);
}

/**
 * Find exposed field groups for a contact
 *
 * @param $node
 *   Node object
 * @param $con
 *   Contact #
 *
 * @return array
 */
function wf_crm_contact_fields($node, $con) {
  $ret = [];
  $sets = wf_crm_get_fields('sets');
  $sets['name'] = ['label' => t('Name')];
  foreach ($node->webform['components'] as $f) {
    if ($pieces = wf_crm_explode_key($f['form_key'])) {
      list( , $c, $ent, , $table, $field) = $pieces;
      if ($ent == 'contact' && $c == $con && isset($sets[$table])) {
        // Separate name from other contact fields
        if ($table == 'contact' && strpos($field, 'name')) {
          $table = 'name';
        }
        if ($field != 'existing') {
          $ret[$table] = $sets[$table]['label'];
        }
      }
    }
  }
  return $ret;
}

/**
 * Update existing component if other fields have been added or removed
 *
 * @param $component
 *   Webform component of type 'civicrm_contact' (reference)
 * @param $enabled
 *   Array of enabled fields
 * @param $data
 *   Array of crm entity data
 * @param stdClass $node
 */
function wf_crm_update_existing_component(&$component, $enabled, $data, $node) {
  list(, $c, ) = explode('_', $component['form_key'], 3);
  if (!empty($data['contact'][$c])) {
    $contact_type = $data['contact'][$c]['contact'][1]['contact_type'];
    $allow_create = wf_crm_name_field_exists($enabled, $c, $contact_type, $node);
    if ($allow_create != $component['extra']['allow_create']) {
      $component['extra']['none_prompt'] = $allow_create ? t('+ Create new +') : t('- None Found -');
      $component['extra']['allow_create'] = $allow_create;
      webform_component_update($component);
    }
  }
}

/**
 * Get a contact's relations of certain types
 *
 * @param int cid
 *   Contact id
 * @param array types
 *   Array of relationship_type_ids
 * @param bool $current
 *   Limit to current & enabled relations?
 *
 * @return array
 */
function wf_crm_find_relations($cid, $types = [], $current = TRUE) {
  $found = $allowed = $type_ids = [];
  $cid = (int) $cid;
  static $employer_type = 0;
  if ($cid) {
    if (!$employer_type && $current) {
      $employer_type = wf_crm_aval(wf_civicrm_api('relationshipType', 'get', ['name_a_b' => 'Employee of', 'return' => 'id']), 'id');
    }
    foreach ($types as $t) {
      list($type, $a) = explode('_', $t);
      // Put current employer first in the list
      if ($type == $employer_type && $current) {
        $search_key = $a == 'b' ? 'id' : 'employer_id';
        // Note: inconsistency in api3 - search key is "employer_id" but return key is "current_employer_id"
        $employer = wf_crm_apivalues('contact', 'get', [
          $search_key => $cid,
          'sequential' => 1,
        ], $a == 'b' ? 'current_employer_id' : 'id');
        if ($employer) {
          $found[$employer[0]] = $employer[0];
        }
      }
      $type_ids[] = $type;
      if ($a == 'a' || $a == 'r') {
        $allowed[] = $type . '_a';
      }
      if ($a == 'b' || $a == 'r') {
        $allowed[] = $type . '_b';
      }
    }
    $params = [
      'return' => ['contact_id_a', 'contact_id_b', 'relationship_type_id', 'end_date'],
      'contact_id_a' => $cid,
      'contact_id_b' => $cid,
      'options' => ['or' => [['contact_id_a', 'contact_id_b']]],
    ];
    if ($type_ids) {
      $params['relationship_type_id'] = ['IN' => $type_ids];
    }
    if ($current) {
      $params['is_active'] = 1;
    }
    foreach (wf_crm_apivalues('relationship', 'get', $params) as $relationship) {
      $a = $relationship['contact_id_a'] == $cid ? 'b' : 'a';
      if (!$current || empty($relationship['end_date']) || strtotime($relationship['end_date']) > time()) {
        if (!$allowed || in_array($relationship['relationship_type_id'] . '_' . $a, $allowed)) {
          $c = $relationship["contact_id_$a"];
          $found[$c] = $c;
        }
      }
    }
  }
  return $found;
}

/**
 * Returns a list of all custom CiviCRM fields of type "ContactReference"
 *
 * @return array
 */
function wf_crm_get_custom_ref_options() {
  $filters = [
    'sequential' => 1,
    'return' => ["id", "label"],
    'data_type' => "ContactReference",
  ];
  $result = wf_civicrm_api('CustomField', 'get', $filters);
  $options = [];
  foreach ($result['values'] as $val) {
    $options[$val['id']] = $val['label'];
  }
  return $options;
}

/**
 * Returns ids of all contacts who have reference to given contact via given custom field id
 *
 * @param $cid
 * @param $customFieldID
 * @return array
 */
function wf_crm_find_custom_refs($cid, $customFieldID) {
  $filters = [
    'return' => ['custom_'.$customFieldID],
    'id' => $cid,
  ];
  $result = wf_civicrm_api('Contact', 'get', $filters);
  if (isset($result['values'][$cid]['custom_'.$customFieldID])) {
    return [$result['values'][$cid]['custom_'.$customFieldID] => $result['values'][$cid]['custom_'.$customFieldID]];
  }
  return [];
}

/**
 * Returns a list of all case roles
 *
 * @return array
 */
function wf_crm_get_case_roles_options() {
  $filters = [
    'return' => ["definition"],
    'options' => ['limit' => 0],
  ];
  $result = wf_civicrm_api('CaseType', 'get', $filters);
  $options = [];
  $options['case_client'] = t('Case Client');
  foreach ($result['values'] as $k => $val) {
    if (isset($val['definition']['caseRoles'])) {
      foreach ($val['definition']['caseRoles'] as $caseRole) {
        $caseRoleID = wf_civicrm_api('RelationshipType', 'get', [
          'sequential' => 1,
          'return' => ["id", "label_b_a", "label_a_b"],
          'name_b_a' => $caseRole['name'],
          'label_b_a' => $caseRole['name'],
          'options' => ['or' => [["name_b_a", "label_b_a"]]]
        ])['values'][0];
        $options[$caseRoleID['id']] = $caseRoleID['label_b_a'];
      }
    }
  }
  return $options;
}

/**
 * Returns ids of all contacts who are related to given contact for given case
 *
 * @param $contact_a
 * @param $case
 * @param $case_role
 * @return array
 */
function wf_crm_find_case_roles($contact_a, $case, $case_role) {
  if ($case_role == 'case_client') {
    // spl handling; this is not a relationship
    $result = civicrm_api3('Case', 'get', [
      'sequential' => 1,
      'id' => $case,
      'return' => ["contact_id"],
    ]);
    if (isset($result['values'][0]['client_id'])) {
      $firstCaseClient = reset($result['values'][0]['client_id']);
      return [$firstCaseClient => $firstCaseClient];
    }
    return [];
  }
  $params = [
    'sequential' => 1,
    'return' => ["contact_id_b"],
    'relationship_type_id' => $case_role,
    'contact_id_a' => $contact_a,
    'case_id' => $case,
  ];
  $result = wf_civicrm_api('Relationship', 'get', $params);
  if (isset($result['values'][0]['contact_id_b'])) {
    return [$result['values'][0]['contact_id_b'] => $result['values'][0]['contact_id_b']];
  }
  return [];
}

/**
 * Format filters for the contact get api
 *
 * @param $node
 *   Webform node object
 * @param $component
 *   Webform component of type 'civicrm_contact'
 *
 * @return array
 *   Api params
 */
function wf_crm_search_filters($node, $component) {
  $params = ['is_deleted' => 0];
  list(, $c, ) = explode('_', $component['form_key'], 3);
  $params['contact_type'] = ucfirst($node->webform_civicrm['data']['contact'][$c]['contact'][1]['contact_type']);
  foreach ($component['extra']['filters'] as $key => $val) {
    if (is_array($val)) {
      $val = array_filter($val);
    }
    if (!empty($val)) {
      if ($key === 'tag' || $key === 'group') {
        $val = array_fill_keys($val, 1);
      }
      $params[$key] = $val;
    }
  }
  return $params;
}

/**
 * Theme the wrapper for a static contact element
 * Includes normal webform theme wrappers plus a tokeninput-style name field
 */
function theme_static_contact_element($vars) {
  $element = $vars['element'];
  $component = $element['#webform_component'];

  // All elements using this for display only are given the "display" type.
  $type = wf_crm_aval($element, '#format') == 'html' ? 'display' : 'civicrm_contact';

  // Convert the parents array into a string, excluding the "submitted" wrapper.
  $nested_level = $element['#parents'][0] == 'submitted' ? 1 : 0;
  $parents = str_replace('_', '-', implode('--', array_slice($element['#parents'], $nested_level)));

  $wrapper_classes = [
    'form-item',
    'webform-component',
    'webform-component-' . $type,
    'static',
    'webform-component--' . $parents,
  ];

  if ($component['extra']['title_display'] === 'inline') {
    $wrapper_classes[] = 'webform-container-inline';
  }

  $output = '<div class="' . implode(' ', $wrapper_classes) . '">' . "\n";

  // Display static value in addition to hidden field
  if ($type == 'civicrm_contact' && !empty($element['#attributes']['data-civicrm-name'])) {

    if ($component['extra']['title_display'] != 'none') {
      $output .= theme('form_element_label', ['element' => $element]);
    }

    $output .= '<ul class="token-input-list"><li class="token-input-token"><p>' . $element['#attributes']['data-civicrm-name'] . "</p></li></ul>\n";

    if (!empty($component['extra']['description'])) {
      $output .= ' <div class="description">' . filter_xss($element['#description']) . "</div>\n";
    }
  }

  return $output . $element['#children'] . "\n</div>\n";
}

/**
 * Returns a list of fields that can be shown in an "Existing Contact" field display
 * In the future we could use api.getfields for this, but that also returns a lot of stuff we don't want
 *
 * @return array
 */
function wf_crm_results_display_options($contact_type) {
  $options = [
    'display_name' => t("Display Name"),
    'sort_name' => t("Sort Name"),
  ];
  if ($contact_type == 'individual') {
    $options += [
      'first_name' => t("First Name"),
      'middle_name' => t("Middle Name"),
      'last_name' => t("Last Name"),
      'current_employer' => t("Current Employer"),
      'job_title' => t("Job Title"),
    ];
  }
  else {
    $options[$contact_type . '_name'] = $contact_type == 'organization' ? t("Organization Name") : t("Household Name");
  }
  $options += [
    'nick_name' => t("Nick Name"),
    'id' => t("Contact ID"),
    'external_identifier' => t("External ID"),
    'source' => t("Source"),
    'email' => t("Email"),
    'city' => t("City"),
    'county' => t("District/County"),
    'state_province' => t("State/Province"),
    'street_address' => t("Street Address"),
    'country' => t("Country"),
    'postal_code' => t("Postal Code"),
    'phone' => t("Phone"),
  ];
  return $options;
}

/**
 * Get the path to the jquery.tokeninput plugin
 * @return string
 */
function wf_crm_tokeninput_path() {
  static $ret;
  if (!$ret) {
    // First see if the library is installed in Drupal
    $path = function_exists('libraries_get_path') ? libraries_get_path('tokeninput') : NULL;
    if ($path && is_file(DRUPAL_ROOT . '/' . $path . '/src/jquery.tokeninput.js')) {
      $ret = $path . '/src/jquery.tokeninput.js';
    }
    // If not, fall back on using the copy shipped with CiviCRM
    else {
      $ret = drupal_get_path('module', 'civicrm') . '/../packages/jquery/plugins/jquery.tokeninput.js';
    }
  }
  return $ret;
}

/**
 * Validation callback
 *
 * @param array $element
 * @param array $form_state
 */
function wf_crm_contact_component_required($element, &$form_state) {
  if (empty($element['#value'])) {
    form_error($element, t('!name field is required.', ['!name' => $element['#title']]));
  }
}

/**
 * Returns a default value for a component.
 *
 * @param object $node
 *
 * @return array
 */
function wf_crm_get_defaults($node) {
  $defaults = [];
  foreach ($node->webform['components'] as $comp) {
    if (!empty($comp['value'])) {
      $key = str_replace('_', '-', $comp['form_key']);
      $defaults[$key] = $comp['type'] == 'date' ? date('Y-m-d', strtotime($comp['value'])) : $comp['value'];
    }
  }
  return $defaults;
}
