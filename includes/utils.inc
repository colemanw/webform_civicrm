<?php

/**
 * @file
 * Webform CiviCRM module's common utility functions.
 */

/**
 * Get options for a specific field
 *
 * @param array $field
 *   Webform component array
 * @param string $context
 *   Where is this being called from?
 * @param array $data
 *   Array of crm entity data
 *
 * @return array
 */
function wf_crm_field_options($field, $context, $data) {
  $ret = [];
  $fields = wf_crm_get_fields();
  if ($pieces = wf_crm_explode_key($field['form_key'])) {
    list( , $c, $ent, $n, $table, $name) = $pieces;
    // Ensure we have complete info for this field
    if (isset($fields[$table . '_' . $name])) {
      $field += $fields[$table . '_' . $name];
    }
    if ($name === 'contact_sub_type') {
      list($contact_types, $sub_types) = wf_crm_get_contact_types();
      $ret = wf_crm_aval($sub_types, $data['contact'][$c]['contact'][1]['contact_type'], []);
    }
    elseif ($name === 'relationship_type_id') {
      $ret = wf_crm_get_contact_relationship_types($data['contact'][$c]['contact'][1]['contact_type'], $data['contact'][$n]['contact'][1]['contact_type'], $data['contact'][$c]['contact'][1]['contact_sub_type'], $data['contact'][$n]['contact'][1]['contact_sub_type']);
    }
    elseif ($name === 'relationship_permission') {
      $ret = [
        1 => t('!a may view and edit !b', ['!a' => wf_crm_contact_label($c, $data, 'plain'), '!b' => wf_crm_contact_label($n, $data, 'plain')]),
        2 => t('!a may view and edit !b', ['!a' => wf_crm_contact_label($n, $data, 'plain'), '!b' => wf_crm_contact_label($c, $data, 'plain')]),
        3 => t('Both contacts may view and edit each other'),
      ];
    }
    // If this is a contact reference or shared address field, list webform contacts
    elseif ($name === 'master_id' || wf_crm_aval($field, 'data_type') === 'ContactReference') {
      $contact_type = wf_crm_aval($field, 'reference_contact_type', 'contact');
      foreach ($data['contact'] as $num => $contact) {
        if ($num != $c || $name != 'master_id') {
          if ($contact_type == 'contact' || $contact_type == $contact['contact'][1]['contact_type']) {
            $ret[$num] = wf_crm_contact_label($num, $data, 'plain');
          }
        }
      }
    }
    elseif ($name == 'privacy') {
      $ret = wf_crm_get_privacy_options();
    }
    elseif (isset($field['table']) && $field['table'] === 'tag') {
      $split = explode('_', $name);
      $ret = wf_crm_get_tags($ent, wf_crm_aval($split, 1));
    }
    elseif (isset($field['table']) && $field['table'] === 'group') {
      $ret = wf_crm_apivalues('group', 'get', ['is_hidden' => 0], 'title');
    }
    elseif ($name === 'survey_id') {
      $ret = wf_crm_get_surveys(wf_crm_aval($data, "activity:$c:activity:1", []));
    }
    elseif ($name == 'event_id') {
      $ret = wf_crm_get_events($data['reg_options'], $context);
    }
    elseif ($table == 'contribution' && $name == 'is_test') {
      // Getoptions would return 'yes' and 'no' - this is a bit more descriptive
      $ret = [0 => t('Live Transactions'), 1 => t('Test Mode')];
    }
    // Not a real field so can't call getoptions for this one
    elseif ($table == 'membership' && $name == 'num_terms') {
      $ret = drupal_map_assoc(range(1, 9));
    }
    // Aside from the above special cases, most lists can be fetched from api.getoptions
    else {
      $params = ['field' => $name, 'context' => 'create'];
      // Special case for contribution_recur fields
      if ($table == 'contribution' && strpos($name, 'frequency_') === 0) {
        $table = 'contribution_recur';
      }
      // Use the Contribution table to pull up financial type id-s
      if ($table == 'membership' && $name == 'financial_type_id') {
        $table = 'contribution';
      }
      // Custom fields - use main entity
      if (substr($table, 0, 2) == 'cg') {
        $table = $ent;
      }
      else {
        // Pass data into api.getoptions for contextual filtering
        $params += wf_crm_aval($data, "$ent:$c:$table:$n", []);
      }
      $ret = wf_crm_apivalues($table, 'getoptions', $params);

      // Hack to format money data correctly
      if (!empty($field['data_type']) && $field['data_type'] === 'Money') {
        $old = $ret;
        $ret = [];
        foreach ($old as $key => $val) {
          $ret[number_format(str_replace(',', '', $key), 2, '.', '')] = $val;
        }
      }
    }
    // Remove options that were set behind the scenes on the admin form
    if ($context != 'config_form' && !empty($field['extra']['multiple']) && !empty($field['expose_list'])) {
      foreach (wf_crm_aval($data, "$ent:$c:$table:$n:$name", []) as $key => $val) {
        unset($ret[$key]);
      }
    }
  }
  if (!empty($field['exposed_empty_option'])) {
    $ret = [0 => $field['exposed_empty_option']] + $ret;
  }
  return $ret;
}

/**
 * Fetch tags within a given tagset
 *
 * If no tagset specified, all tags NOT within a tagset are returned.
 * Return format is a flat array with some tic marks to indicate nesting.
 *
 * @param string $used_for
 * @param int $parent_id
 * @return array
 */
function wf_crm_get_tags($used_for, $parent_id = NULL) {
  $params = [
    'used_for' => ['LIKE' => "%civicrm_{$used_for}%"],
    'is_tagset' => 0,
    'is_selectable' => 1,
    'parent_id' => $parent_id ?: ['IS NULL' => 1],
    'options' => ['sort' => 'name'],
  ];
  $tags = wf_crm_apivalues('Tag', 'get', $params, 'name');
  // Tagsets cannot be nested so no need to fetch children
  if ($parent_id || !$tags) {
    return $tags;
  }
  // Fetch child tags
  unset($params['parent_id']);
  $params += ['return' => ['name', 'parent_id'], 'parent_id.is_tagset' => 0, 'parent_id.is_selectable' => 1, 'parent_id.used_for' => $params['used_for']];
  $unsorted = wf_crm_apivalues('Tag', 'get', $params);
  $parents = array_fill_keys(array_keys($tags), ['depth' => 1]);
  // Place children under their parents.
  $prevLoop = NULL;
  while ($unsorted && count($unsorted) !== $prevLoop) {
    // If count stops going down then we are left with only orphan tags & will abort the loop
    $prevLoop = count($unsorted);
    foreach ($unsorted as $id => $tag) {
      $parent = $tag['parent_id'];
      if (isset($parents[$parent])) {
        $name = str_repeat('- ', $parents[$parent]['depth']) . $tag['name'];
        $pos = array_search($parents[$parent]['child'] ?? $parent, array_keys($tags)) + 1;
        $tags = array_slice($tags, 0, $pos, TRUE) + [$id => $name] + array_slice($tags, $pos, NULL, TRUE);
        $parents[$id] = ['depth' => $parents[$parent]['depth'] + 1];
        $parents[$parent]['child'] = $id;
        unset($unsorted[$id]);
      }
    }
  }
  return $tags;
}

/**
 * Get list of states, keyed by abbreviation rather than ID.
 *
 * @param null|int|string $param
 * @return array
 */
function wf_crm_get_states($param = NULL) {
  $ret = [];
  if (!$param || $param == 'default') {
    $provinceLimit = wf_crm_get_civi_setting('provinceLimit');
    if (!$param && $provinceLimit) {
      $param = (array) $provinceLimit;
    }
    else {
      $param = [(int) wf_crm_get_civi_setting('defaultContactCountry', 1228)];
    }
  }
  else {
    $param = [(int) $param];
  }
  $states = wf_crm_apivalues('state_province', 'get', [
    'return' => 'abbreviation,name',
    'sort' => 'name',
    'country_id' => ['IN' => $param]
  ]);
  foreach ($states as $state) {
    $ret[strtoupper($state['abbreviation'])] = $state['name'];
  }
  // Localize the state/province names if in an non-en_US locale
  $tsLocale = CRM_Utils_System::getUFLocale();
  if ($tsLocale != '' and $tsLocale != 'en_US') {
    $i18n = CRM_Core_I18n::singleton();
    $i18n->localizeArray($ret, ['context' => 'province']);
    CRM_Utils_Array::asort($ret);
  }
  return $ret;
}

/**
 * Match a state/province id to its abbr. and vice-versa
 *
 * @param $input
 *   User input (state province id or abbr)
 * @param $ret
 *   String: 'abbreviation' or 'id'
 * @param $country_id
 *   Int: (optional) must be supplied if fetching id from abbr
 *
 * @return string or integer
 */
function wf_crm_state_abbr($input, $ret = 'abbreviation', $country_id = NULL) {
  $params = ['sequential' => 1];
  if ($ret == 'id') {
    $params['abbreviation'] = $input;
    if (!$country_id || $country_id === 'default') {
      $country_id = (int) wf_crm_get_civi_setting('defaultContactCountry', 1228);
    }
  }
  else {
    $params['id'] = $input;
  }
  $params['country_id'] = $country_id;
  $result = wf_crm_apivalues('StateProvince', 'get', $params, $ret);
  return wf_crm_aval($result, 0);
}

/**
 * Get list of events.
 *
 * @param array $reg_options
 * @param string $context
 *
 * @return array
 */
function wf_crm_get_events($reg_options, $context) {
  $ret = [];
  $format = wf_crm_aval($reg_options, 'title_display', 'title');
  $params = [
    'is_template' => 0,
    'is_active' => 1,
  ];
  $event_types = array_filter((array) $reg_options['event_type'], "is_numeric");
  if ($event_types) {
    $params['event_type_id'] = ['IN' => $event_types];
  }
  if (is_numeric(wf_crm_aval($reg_options, 'show_public_events'))) {
    $params['is_public'] = $reg_options['show_public_events'];
  }
  $params['options'] = ['sort' => 'start_date' . ($context == 'config_form' ? ' DESC' : '')];
  $values = wf_crm_apivalues('Event', 'get', $params);
  // 'now' means only current events, 1 means show all past events, other values are relative date strings
  $date_past = wf_crm_aval($reg_options, 'show_past_events', 'now');
  if ($date_past != '1') {
    $date_past = date('Y-m-d H:i:s', strtotime($date_past));
    foreach ($values as $key => $value) {
      if (isset($value['end_date']) && $value['end_date'] <= $date_past) {
        unset($values[$key]);
      }
    }
  }
  // 'now' means only past events, 1 means show all future events, other values are relative date strings
  $date_future = wf_crm_aval($reg_options, 'show_future_events', '1');
  if ($date_future != '1') {
    $date_future = date('Y-m-d H:i:s', strtotime($date_future));
    foreach ($values as $key => $value) {
      if (isset($value['end_date']) && $value['end_date'] >= $date_future) {
        unset($values[$key]);
      }
    }
  }
  foreach ($values as $value) {
    $ret[$value['id'] . '-' . $value['event_type_id']] = wf_crm_format_event($value, $format);
  }
  return $ret;
}

/**
 * @param array $event
 * @param string $format
 * @return string
 */
function wf_crm_format_event($event, $format) {
  $format = explode(' ', $format);
  // Date format
  foreach ($format as $value) {
    if (strpos($value, 'dateformat') === 0) {
      $date_format = wf_crm_get_civi_setting($value);
    }
  }
  $title = [];
  if (in_array('title', $format)) {
    $title[] = $event['title'];
  }
  if (in_array('type', $format)) {
    $types = wf_crm_apivalues('event', 'getoptions', [
      'field' => 'event_type_id',
      'context' => 'get',
    ]);
    $title[] = $types[$event['event_type_id']];
  }
  if (in_array('start', $format) && !empty($event['start_date'])) {
    $title[] = CRM_Utils_Date::customFormat($event['start_date'], $date_format);
  }
  if (in_array('end', $format) && isset($event['end_date'])) {
    // Avoid showing redundant end-date if it is the same as the start date
    $same_day = substr($event['start_date'], 0, 10) == substr($event['end_date'], 0, 10);
    if (!$same_day || in_array('dateformatDatetime', $format) || in_array('dateformatTime', $format)) {
      $end_format = (in_array('dateformatDatetime', $format) && $same_day) ? wf_crm_get_civi_setting('dateformatTime') : $date_format;
      $title[] = CRM_Utils_Date::customFormat($event['end_date'], $end_format);
    }
  }
  return implode(' - ', $title);
}

/**
 * Get list of surveys
 * @param array $act
 *
 * @return array
 */
function wf_crm_get_surveys($act = []) {
  return wf_crm_apivalues('survey', 'get', array_filter($act), 'title');
}

/**
 * Get activity types related to CiviCampaign
 * @return array
 */
function wf_crm_get_campaign_activity_types() {
  $ret = [];
  if (array_key_exists('activity_survey_id', wf_crm_get_fields())) {
    $vals = wf_crm_apivalues('option_value', 'get', [
      'option_group_id' => 'activity_type',
      'is_active' => 1,
      'component_id' => 'CiviCampaign',
    ]);
    foreach ($vals as $val) {
      $ret[$val['value']] = $val['label'];
    }
  }
  return $ret;
}

/**
 * Get contact types and sub-types
 * Unlike pretty much every other option list CiviCRM wants "name" instead of "id"
 *
 * @return array
 */
function wf_crm_get_contact_types() {
  static $contact_types = [];
  static $sub_types = [];
  if (!$contact_types) {
    $data = wf_crm_apivalues('contact_type', 'get', ['is_active' => 1]);
    foreach ($data as $type) {
      if (empty($type['parent_id'])) {
        $contact_types[strtolower($type['name'])] = $type['label'];
        continue;
      }
      $sub_types[strtolower($data[$type['parent_id']]['name'])][$type['name']] = $type['label'];
    }
  }
  return [$contact_types, $sub_types];
}

/**
 * In reality there is no contact field 'privacy' so this is not a real option list.
 * These are actually 5 separate contact fields that this module munges into 1 for better usability.
 *
 * @return array
 */
function wf_crm_get_privacy_options() {
  return [
    'do_not_email' => ts('Do not email'),
    'do_not_phone' => ts('Do not phone'),
    'do_not_mail' => ts('Do not mail'),
    'do_not_sms' => ts('Do not sms'),
    'do_not_trade' => ts('Do not trade'),
    'is_opt_out' => ts('NO BULK EMAILS (User Opt Out)'),
  ];
}

/**
 * Get relationship type data
 *
 * @return array
 */
function wf_crm_get_relationship_types() {
  static $types = [];
  if (!$types) {
    foreach (wf_crm_apivalues('relationship_type', 'get', ['is_active' => 1]) as $r) {
      $r['type_a'] = strtolower(wf_crm_aval($r, 'contact_type_a'));
      $r['type_b'] = strtolower(wf_crm_aval($r, 'contact_type_b'));
      $r['sub_type_a'] = wf_crm_aval($r, 'contact_sub_type_a');
      $r['sub_type_b'] = wf_crm_aval($r, 'contact_sub_type_b');
      $types[$r['id']] = $r;
    }
  }
  return $types;
}

/**
 * Get valid relationship types for a given pair of contacts
 *
 * @param $type_a
 *   Contact type
 * @param $type_b
 *   Contact type
 * @param $sub_type_a
 *   Contact sub-type
 * @param $sub_type_b
 *   Contact sub-type
 *
 * @return array
 */
function wf_crm_get_contact_relationship_types($type_a, $type_b, $sub_type_a, $sub_type_b) {
  $ret = [];
  foreach (wf_crm_get_relationship_types() as $t) {
    $reciprocal = ($t['label_a_b'] != $t['label_b_a'] && $t['label_b_a'] || $t['type_a'] != $t['type_b']);
    if (($t['type_a'] == $type_a || !$t['type_a'])
      && ($t['type_b'] == $type_b || !$t['type_b'])
      && (in_array($t['sub_type_a'], $sub_type_a) || !$t['sub_type_a'])
      && (in_array($t['sub_type_b'], $sub_type_b) || !$t['sub_type_b'])
    ) {
      $ret[$t['id'] . ($reciprocal ? '_a' : '_r')] = $t['label_a_b'];
    }
    // Reciprocal form - only show if different from above
    if ($reciprocal
      && ($t['type_a'] == $type_b || !$t['type_a'])
      && ($t['type_b'] == $type_a || !$t['type_b'])
      && (in_array($t['sub_type_a'], $sub_type_b) || !$t['sub_type_a'])
      && (in_array($t['sub_type_b'], $sub_type_a) || !$t['sub_type_b'])
    ) {
      $ret[$t['id'] . '_b'] = $t['label_b_a'];
    }
  }
  return $ret;
}

/**
 * List dedupe rules available for a contact type
 *
 * @param string $contact_type
 * @return array
 */
function wf_crm_get_matching_rules($contact_type) {
  static $rules;
  $contact_type = ucfirst($contact_type);
  if (!$rules) {
    $rules = array_fill_keys(['Individual', 'Organization', 'Household'], []);
    $values = wf_crm_apivalues('RuleGroup', 'get');
    foreach ($values as $value) {
      $rules[$value['contact_type']][$value['id']] = $value['title'];
    }
  }
  return $rules[$contact_type];
}

/**
 * Get ids or values of enabled CiviCRM fields for a webform.
 *
 * @param stdClass $node
 *   Node object
 * @param array|null $submission
 *   (optional) if supplied, will match field keys with submitted values
 * @param boolean $show_all
 *   (optional) if true, get every field even if it belongs to a contact that does not exist
 *
 * @return array of enabled fields
 */
function wf_crm_enabled_fields($node, $submission = NULL, $show_all = FALSE) {
  $enabled = [];
  if (!empty($node->webform['components']) && (!empty($node->webform_civicrm) || $show_all)) {
    $fields = wf_crm_get_fields();
    foreach ($node->webform['components'] as $c) {
      $exp = explode('_', $c['form_key'], 5);
      $customGroupFieldsetKey = "";
      if (count($exp) == 5) {
        list($lobo, $i, $ent, $n, $id) = $exp;
        if ($lobo != 'civicrm') {
          continue;
        }
        $explodedId = explode('_', $id);
        if (wf_crm_aval($explodedId, 1) == 'fieldset' && $explodedId[0] != 'fieldset') {
          $customGroupFieldsetKey = $explodedId[0];

          // Automatically enable 'Create mode' field for Contact's custom group.
          if ($ent === 'contact') {
            $enabled[$lobo . '_' . $i . '_' . $ent . '_' . $n . '_' . $customGroupFieldsetKey . '_createmode'] = 1;
          }
        }
        if ((isset($fields[$id]) || $id == 'fieldset_fieldset' || $id == $customGroupFieldsetKey . '_fieldset') && is_numeric($i) && is_numeric($n)) {
          if (!$show_all && ($ent == 'contact' || $ent == 'participant') && empty($node->webform_civicrm['data']['contact'][$i])) {
            continue;
          }
          if ($submission) {
            $enabled[$c['form_key']] = wf_crm_aval($submission, $c['cid'], NULL, TRUE);
          }
          else {
            $enabled[$c['form_key']] = $c['cid'];
          }
        }
      }
    }
  }
  return $enabled;
}

/**
 * Fetches CiviCRM field data.
 *
 * @param string $var
 *   Name of variable to return: fields, tokens, or sets
 *
 * @return array
 *   fields: The CiviCRM contact fields this module supports
 *   tokens: Available tokens keyed to field ids
 *   sets: Info on fieldsets (entities)
 */
function wf_crm_get_fields($var = 'fields') {
  static $fields = [];
  static $tokens;
  static $sets;

  if (!$fields) {
    $components = wf_crm_get_civi_setting('enable_components');

    $sets = [
      'contact' => ['entity_type' => 'contact', 'label' => t('Contact Fields')],
      'other' => ['entity_type' => 'contact', 'label' => t('Tags and Groups'), 'max_instances' => 1],
      'address' => ['entity_type' => 'contact', 'label' => t('Address'), 'max_instances' => 9, 'custom_fields' => 'combined'],
      'phone' => ['entity_type' => 'contact', 'label' => t('Phone'), 'max_instances' => 9, 'custom_fields' => 'combined'],
      'email' => ['entity_type' => 'contact', 'label' => t('Email'), 'max_instances' => 9, 'custom_fields' => 'combined'],
      'website' => ['entity_type' => 'contact', 'label' => t('Website'), 'max_instances' => 9, 'custom_fields' => 'combined'],
      'im' => ['entity_type' => 'contact', 'label' => t('Instant Message'), 'max_instances' => 9, 'custom_fields' => 'combined'],
      'activity' => ['entity_type' => 'activity', 'label' => t('Activity'), 'max_instances' => 99,  'attachments' => TRUE],
      'relationship' => ['entity_type' => 'contact', 'label' => t('Relationship'), 'help_text' => TRUE, 'custom_fields' => 'combined'],
    ];
    $conditional_sets = [
      'CiviCase' => ['entity_type' => 'case', 'label' => t('Case'), 'max_instances' => 30],
      'CiviEvent' => ['entity_type' => 'participant', 'label' => t('Participant'), 'max_instances' => 9],
      'CiviContribute' => ['entity_type' => 'contribution', 'label' => t('Contribution')],
      'CiviMember' => ['entity_type' => 'membership', 'label' => t('Membership'), 'custom_fields' => 'combined'],
      'CiviGrant' => ['entity_type' => 'grant', 'label' => t('Grant'), 'max_instances' => 30, 'attachments' => TRUE],
    ];
    foreach ($conditional_sets as $component => $set) {
      if (in_array($component, $components)) {
        $sets[$set['entity_type']] = $set;
      }
    }
    // Contribution line items
    if (in_array('CiviContribute', $components)) {
      $sets['line_items'] = ['entity_type' => 'line_item', 'label' => t('Line Items')];
    }

    $moneyDefaults = [
      'type' => 'number',
      'data_type' => 'Money',
      'extra' => [
        'field_prefix' => wf_crm_get_civi_setting('defaultCurrencySymbol', '$'),
        'point' => wf_crm_get_civi_setting('monetaryDecimalPoint', '.'),
        'separator' => wf_crm_get_civi_setting('monetaryThousandSeparator', ','),
        'decimals' => 2,
        'min' => 0,
      ],
    ];

    // Field keys are in the format table_column
    // Use a # sign as a placeholder for field number in the title (or by default it will be appended to the end)
    // Setting 'expose_list' allows the value to be set on the config form
    // Set label for 'empty_option' for exposed lists that do not require input
    $fields['contact_contact_sub_type'] = [
      'name' => t('Type of @contact'),
      'type' => 'select',
      'extra' => ['multiple' => 1, 'civicrm_live_options' => 1],
      'expose_list' => TRUE,
    ];
    $fields['contact_existing'] = [
      'name' => t('Existing Contact'),
      'type' => 'civicrm_contact',
      'extra' => [
        'search_prompt' => t('- Choose existing -'),
      ],
    ];
    // Organization / household names
    foreach (['organization' => t('Organization Name'), 'legal' => t('Legal Name'), 'household' => t('Household Name')] as $key => $label) {
      $fields['contact_' . $key . '_name'] = [
        'name' => $label,
        'type' => 'textfield',
        'contact_type' => $key == 'household' ? 'household' : 'organization',
      ];
    }
    $fields['contact_sic_code'] = [
      'name' => t('SIC Code'),
      'type' => 'textfield',
      'contact_type' => 'organization',
    ];
    // Individual names
    $enabled_names = wf_crm_get_civi_setting('contact_edit_options');
    $name_options = array_column(wf_crm_apivalues('OptionValue', 'get', ['option_group_id' => 'contact_edit_options', 'return' => ['name', 'value']]), 'name', 'value');
    $enabled_names = array_intersect_key($name_options, array_flip($enabled_names));
    foreach (['prefix_id' => t('Name Prefix'), 'formal_title' => t('Formal Title'), 'first_name' => t('First Name'), 'middle_name' => t('Middle Name'), 'last_name' => t('Last Name'), 'suffix_id' => t('Name Suffix')] as $key => $label) {
      if (in_array(ucwords(str_replace(['_id', '_'], ['', ' '], $key)), $enabled_names)) {
        $fields['contact_' . $key] = [
          'name' => $label,
          'type' => strpos($key, '_id') ? 'select' : 'textfield',
          'contact_type' => 'individual',
        ];
      }
    }
    $fields['contact_nick_name'] = [
      'name' => t('Nickname'),
      'type' => 'textfield',
    ];
    $fields['contact_gender_id'] = [
      'name' => t('Gender'),
      // Gender should be textfield if using https://civicrm.org/extensions/gender-self-identify
      'type' => function_exists('genderselfidentify_civicrm_apiWrappers') ? 'textfield' : 'select',
      'contact_type' => 'individual',
    ];
    $fields['contact_job_title'] = [
      'name' => t('Job Title'),
      'type' => 'textfield',
      'contact_type' => 'individual',
    ];
    $fields['contact_birth_date'] = [
      'name' => t('Birth Date'),
      'type' => 'date',
      'extra' => [
        'start_date' => '-100 years',
        'end_date' => 'now',
      ],
      'contact_type' => 'individual',
    ];
    $fields['contact_preferred_communication_method'] = [
      'name' => t('Preferred Communication Method(s)'),
      'type' => 'select',
      'extra' => ['multiple' => 1],
    ];
    $fields['contact_privacy'] = [
      'name' => t('Privacy Preferences'),
      'type' => 'select',
      'extra' => ['multiple' => 1],
    ];
    $fields['contact_preferred_language'] = [
      'name' => t('Preferred Language'),
      'type' => 'select',
      'value' => wf_crm_get_civi_setting('lcMessages', 'en_US'),
    ];
    if (array_key_exists('file', webform_components())) {
      $fields['contact_image_URL'] = [
        'name' => t('Upload Image'),
        'type' => 'file',
        'extra' => ['width' => 40],
        'data_type' => 'File',
      ];
    }
    $fields['contact_contact_id'] = [
      'name' => t('Contact ID'),
      'type' => 'hidden',
    ];
    $fields['contact_user_id'] = [
      'name' => t('User ID'),
      'type' => 'hidden',
    ];
    $fields['contact_external_identifier'] = [
      'name' => t('External ID'),
      'type' => 'hidden',
    ];
    $fields['contact_source'] = [
      'name' => t('Source'),
      'type' => 'textfield',
    ];
    $fields['contact_cs'] = [
      'name' => t('Checksum'),
      'type' => 'hidden',
      'value_callback' => TRUE,
    ];
    $fields['contact_employer_id'] = [
      'name' => t('Current Employer'),
      'type' => 'select',
      'expose_list' => TRUE,
      'empty_option' => t('None'),
      'data_type' => 'ContactReference',
      'contact_type' => 'individual',
      'reference_contact_type' => 'organization'
    ];
    $fields['contact_is_deceased'] = [
      'name' => t('Is Deceased'),
      'type' => 'select',
      'extra' => ['aslist' => 0],
      'contact_type' => 'individual',
    ];
    $fields['contact_deceased_date'] = [
      'name' => t('Deceased Date'),
      'type' => 'date',
      'extra' => [
        'start_date' => '-100 years',
        'end_date' => 'now',
      ],
      'contact_type' => 'individual',
    ];
    $fields['email_email'] = [
      'name' => t('Email'),
      'type' => 'email',
    ];
    $addressOptions = [
      'street_address' => t('Street Address'),
      'street_name' => t('Street Name'),
      'street_number' => t('Street Number'),
      'street_unit' => t('Street Number Suffix'),
      'name' => t('Address Name'),
      'supplemental_address_1' => t('Street Address # Line 2'),
      'supplemental_address_2' => t('Street Address # Line 3'),
      'supplemental_address_3' => t('Street Address # Line 4'),
      'city' => t('City'),
    ];
    foreach ($addressOptions as $key => $value) {
      $fields['address_' . $key] = [
        'name' => $value,
        'type' => 'textfield',
        'extra' => ['width' => $key == 'city' ? 20 : 60],
      ];
    }
    $fields['address_postal_code'] = [
      'name' => t('Postal Code'),
      'type' => 'textfield',
      'extra' => ['width' => 7],
    ];
    $fields['address_postal_code_suffix'] = [
      'name' => t('Postal Code Suffix'),
      'type' => 'textfield',
      'extra' => [
        'width' => 5,
        'description' => t('+4 digits of Zip Code'),
      ],
    ];
    $fields['address_country_id'] = [
      'name' => t('Country'),
      'type' => 'select',
      'extra' => ['civicrm_live_options' => 1],
      'value' => wf_crm_get_civi_setting('defaultContactCountry', 1228),
    ];
    $fields['address_state_province_id'] = [
      'name' => t('State/Province'),
      'type' => 'textfield',
      'extra' => [
        'maxlength' => 5,
        'width' => 4,
      ],
      'data_type' => 'state_province_abbr',
    ];
    $fields['address_county_id'] = [
      'name' => t('District/County'),
      'type' => 'textfield',
    ];
    $fields['address_master_id'] = [
      'name' => t('Share address of'),
      'type' => 'select',
      'expose_list' => TRUE,
      'extra' => ['aslist' => 0],
      'empty_option' => t('Do Not Share'),
    ];
    $fields['phone_phone'] = [
      'name' => t('Phone Number'),
      'type' => 'textfield',
    ];
    $fields['phone_phone_ext'] = [
      'name' => t('Phone Extension'),
      'type' => 'textfield',
      'extra' => [
        'width' => 4,
      ],
    ];
    $fields['phone_phone_type_id'] = [
      'name' => t('Phone # Type'),
      'type' => 'select',
      'table' => 'phone',
      'expose_list' => TRUE,
    ];
    $fields['im_name'] = [
      'name' => t('Screen Name'),
      'type' => 'textfield',
    ];
    $fields['im_provider_id'] = [
      'name' => t('IM Provider'),
      'type' => 'select',
      'expose_list' => TRUE,
    ];
    $defaultLocType = wf_crm_aval(wf_civicrm_api('LocationType', 'get', [
      'return' => ["id"],
      'is_default' => 1,
    ]), 'id');
    foreach (['address' => t('Address # Location'), 'phone' => t('Phone # Location'), 'email' => t('Email # Location'), 'im' => t('IM # Location')] as $key => $label) {
      if (isset($sets[$key])) {
        $fields[$key . '_location_type_id'] = [
          'name' => $label,
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => $defaultLocType,
        ];
      }
    }
    $fields['website_url'] = [
      'name' => t('Website'),
      'type' => 'textfield',
      'data_type' => 'Link',
    ];
    $fields['website_website_type_id'] = [
      'name' => t('Website # Type'),
      'type' => 'select',
      'expose_list' => TRUE,
    ];
    $fields['other_group'] = [
      'name' => t('Group(s)'),
      'type' => 'select',
      'extra' => ['multiple' => 1, 'civicrm_live_options' => 1],
      'table' => 'group',
      'expose_list' => TRUE,
    ];
    $fields['activity_activity_type_id'] = [
      'name' => t('Activity # Type'),
      'type' => 'select',
      'expose_list' => TRUE,
    ];
    $fields['activity_target_contact_id'] = [
      'name' => t('Activity # Participant(s)'),
      'type' => 'select',
      'expose_list' => TRUE,
      'extra' => ['multiple' => 1],
      'data_type' => 'ContactReference',
    ];
    $fields['activity_source_contact_id'] = [
      'name' => t('Activity # Creator'),
      'type' => 'select',
      'expose_list' => TRUE,
      'data_type' => 'ContactReference',
      'exposed_empty_option' => '- ' . t('Automatic') . ' -',
    ];
    $fields['activity_subject'] = [
      'name' => t('Activity # Subject'),
      'type' => 'textfield',
    ];
    $fields['activity_details'] = [
      'name' => t('Activity # Details'),
      'type' => module_exists('webform_html_textarea') ? 'html_textarea' : 'textarea',
    ];
    $fields['activity_status_id'] = [
      'name' => t('Activity # Status'),
      'type' => 'select',
      'expose_list' => TRUE,
      'exposed_empty_option' => '- ' . t('Automatic') . ' -',
    ];
    $fields['activity_priority_id'] = [
      'name' => t('Activity # Priority'),
      'type' => 'select',
      'expose_list' => TRUE,
      'exposed_empty_option' => '- ' . t('Automatic') . ' -',
    ];
    $fields['activity_assignee_contact_id'] = [
      'name' => t('Assign Activity # to'),
      'type' => 'select',
      'expose_list' => TRUE,
      'empty_option' => t('No One'),
      'extra' => ['multiple' => 1],
      'data_type' => 'ContactReference',
    ];
    $fields['activity_location'] = [
      'name' => t('Activity # Location'),
      'type' => 'textfield',
    ];
    $fields['activity_activity_date_time'] = [
      'name' => t('Activity # Date'),
      'type' => 'date',
      'value' => 'now',
    ];
    $fields['activity_activity_date_time_timepart'] = [
      'name' => t('Activity # Time'),
      'type' => 'time',
      'value' => 'now',
    ];
    $fields['activity_duration'] = [
      'name' => t('Activity # Duration'),
      'type' => 'number',
      'extra' => [
        'field_suffix' => t('min.'),
        'min' => 0,
        'step' => 5,
        'integer' => 1,
      ],
    ];
    $tag_entities = ['other', 'activity'];
    if (isset($sets['case'])) {
      $tag_entities[] = 'case';
      $fields['case_case_type_id'] = [
        'name' => t('Case # Type'),
        'type' => 'select',
        'expose_list' => TRUE,
      ];
      $fields['case_client_id'] = [
        'name' => t('Case # Client'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['required' => 1, 'multiple' => wf_crm_get_civi_setting('civicaseAllowMultipleClients', 0)],
        'data_type' => 'ContactReference',
        'set' => 'caseRoles',
        'value' => 1,
      ];
      $fields['case_status_id'] = [
        'name' => t('Case # Status'),
        'type' => 'select',
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['case_medium_id'] = [
        'name' => t('Medium'),
        'type' => 'select',
        'expose_list' => TRUE,
      ];
      $fields['case_subject'] = [
        'name' => t('Case # Subject'),
        'type' => 'textfield',
      ];
      $fields['case_creator_id'] = [
        'name' => t('Case # Creator'),
        'type' => 'select',
        'expose_list' => TRUE,
        'data_type' => 'ContactReference',
        'set' => 'caseRoles',
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['case_start_date'] = [
        'name' => t('Case # Start Date'),
        'type' => 'date',
        'value' => 'now',
      ];
      $fields['case_end_date'] = [
        'name' => t('Case # End Date'),
        'type' => 'date',
        'value' => 'now',
      ];
      $fields['case_details'] = [
        'name' => t('Case # Details'),
        'type' => 'textarea',
      ];
      // Fetch case roles
      $sets['caseRoles'] = ['entity_type' => 'case', 'label' => t('Case Roles')];
      foreach (wf_crm_apivalues('case_type', 'get') as $case_type) {
        foreach ($case_type['definition']['caseRoles'] as $role) {
          foreach (wf_crm_get_relationship_types() as $rel_type) {
            if (in_array($role['name'], [$rel_type['name_b_a'], $rel_type['label_b_a']])) {
              if (!isset($fields['case_role_' . $rel_type['id']])) {
                $fields['case_role_' . $rel_type['id']] = [
                  'name' => $rel_type['label_b_a'],
                  'type' => 'select',
                  'expose_list' => TRUE,
                  'data_type' => 'ContactReference',
                  'set' => 'caseRoles',
                  'empty_option' => t('None'),
                  'extra' => [
                    'multiple' => 1,
                  ],
                ];
              }
              $fields['case_role_' . $rel_type['id']]['case_types'][] = $case_type['id'];
              break;
            }
          }
        }
      }
    }
    $all_tagsets = wf_crm_apivalues('tag', 'get', [
      'return' => ['id', 'name', 'used_for'],
      'is_tagset' => 1,
      'parent_id' => ['IS NULL' => 1],
    ]);
    foreach ($tag_entities as $entity) {
      $table_name = $entity == 'other' ? 'civicrm_contact' : "civicrm_$entity";
      $tagsets = ['' => t('Tag(s)')];
      foreach ($all_tagsets as $set) {
        if (strpos($set['used_for'], $table_name) !== FALSE) {
          $tagsets[$set['id']] = $set['name'];
        }
      }
      foreach ($tagsets as $pid => $name) {
        $fields[$entity . '_tag' . ($pid ? "_$pid" : '')] = [
          'name' => $name,
          'type' => 'select',
          'extra' => ['multiple' => 1, 'civicrm_live_options' => 1],
          'table' => 'tag',
          'expose_list' => TRUE,
        ];
      }
    }
    $fields['relationship_relationship_type_id'] = [
      'name' => t('Relationship Type(s)'),
      'type' => 'select',
      'expose_list' => TRUE,
      'extra' => [
        'civicrm_live_options' => 1,
        'multiple' => 1,
      ],
    ];
    $fields['relationship_is_active'] = [
      'name' => t('Is Active'),
      'type' => 'select',
      'expose_list' => TRUE,
      'value' => '1',
    ];
    $fields['relationship_relationship_permission'] = [
      'name' => t('Permissions'),
      'type' => 'select',
      'expose_list' => TRUE,
      'empty_option' => t('No Permissions'),
    ];
    $fields['relationship_start_date'] = [
      'name' => t('Start Date'),
      'type' => 'date',
      'extra' => [
        'start_date' => '-50 years',
        'end_date' => '+10 years',
      ],
    ];
    $fields['relationship_end_date'] = [
      'name' => t('End Date'),
      'type' => 'date',
      'extra' => [
        'start_date' => '-50 years',
        'end_date' => '+10 years',
      ],
    ];
    $fields['relationship_description'] = [
      'name' => t('Description'),
      'type' => 'textarea',
    ];
    if (isset($sets['contribution'])) {
      $fields['contribution_contribution_page_id'] = [
        'name' => ts('Contribution Page'),
        'type' => 'hidden',
        'expose_list' => TRUE,
        'empty_option' => t('None'),
        'extra' => [
          'hidden_type' => 'hidden',
        ],
        'weight' => 9999,
      ];
      $fields['contribution_contact_id'] = [
        'name' => t('Contact'),
        'type' => 'select',
        'expose_list' => TRUE,
        'data_type' => 'ContactReference',
        'weight' => 9998,
      ];
      $fields['contribution_total_amount'] = [
        'name' => t('Contribution Amount'),
        'weight' => 9991,
      ] + $moneyDefaults;
      $fields['contribution_payment_processor_id'] = [
        'name' => t('Payment Processor'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['aslist' => 0],
        'exposed_empty_option' => t('Pay Later'),
        'value_callback' => TRUE,
        'weight' => 9995,
      ];
      $fields['contribution_note'] = [
        'name' => t('Contribution Note'),
        'type' => 'textarea',
        'weight' => 9993,
      ];
      $fields['contribution_soft'] = [
        'name' => t('Soft Credit To'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['multiple' => TRUE],
        'data_type' => 'ContactReference',
      ];
      $fields['contribution_honor_contact_id'] = [
        'name' => t('In Honor/Memory of'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('No One'),
        'data_type' => 'ContactReference',
      ];
      $fields['contribution_honor_type_id'] = [
        'name' => t('Honoree Type'),
        'type' => 'select',
        'expose_list' => TRUE,
      ];
      $fields['contribution_is_test'] = [
        'name' => t('Payment Processor Mode'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['civicrm_live_options' => 1],
        'value' => 0,
        'weight' => 9997,
      ];
      $fields['contribution_source'] = [
        'name' => t('Contribution Source'),
        'type' => 'textfield',
      ];
      // Line items
      $fields['contribution_line_total'] = [
          'name' => t('Line Item Amount'),
          'set' => 'line_items',
        ] + $moneyDefaults;
      $fields['contribution_financial_type_id'] = [
        'name' => t('Financial Type'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['civicrm_live_options' => 1],
        'value' => 1,
        'default' => 1,
        'set' => 'line_items',
      ];
      $sets['contributionRecur'] = ['entity_type' => 'contribution', 'label' => t('Recurring Contribution')];
      $fields['contribution_frequency_unit'] = [
        'name' => t('Frequency of Installments'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => 0,
        'exposed_empty_option' => '- ' . t('No Installments') . ' -',
        'set' => 'contributionRecur',
      ];
      $fields['contribution_installments'] = [
        'name' => t('Number of Installments'),
        'type' => 'number',
        'value' => '1',
        'extra' => [
          'integer' => 1,
          'min' => 0,
        ],
        'set' => 'contributionRecur',
      ];
      $fields['contribution_frequency_interval'] = [
        'name' => t('Interval of Installments'),
        'type' => 'number',
        'value' => '1',
        'extra' => [
          'integer' => 1,
          'min' => 1,
        ],
        'set' => 'contributionRecur',
      ];
    }
    if (isset($sets['participant'])) {
      $fields['participant_event_id'] = [
        'name' => t('Event(s)'),
        'type' => 'select',
        'extra' => ['multiple' => 1, 'civicrm_live_options' => 1],
        'expose_list' => TRUE,
      ];
      $fields['participant_role_id'] = [
        'name' => t('Participant Role'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => '1',
        'extra' => ['multiple' => 1, 'required' => 1],
      ];
      $fields['participant_status_id'] = [
        'name' => t('Registration Status'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => 0,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['participant_note'] = [
        'name' => t('Participant Notes'),
        'type' => 'textarea',
      ];
      if (isset($sets['contribution'])) {
        $fields['participant_fee_amount'] = [
          'name' => t('Participant Fee'),
        ] + $moneyDefaults;
      }
    }
    if (isset($sets['membership'])) {
      $fields['membership_membership_type_id'] = [
        'name' => t('Membership Type'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['civicrm_live_options' => 1],
      ];
      $fields['membership_financial_type_id'] = [
        'name' => t('Membership Financial Type'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => 0,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['membership_status_id'] = [
        'name' => t('Override Status'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => 0,
        'exposed_empty_option' => '- ' . t('No') . ' -',
      ];
      $fields['membership_status_override_end_date'] = [
        'name' => t('Status Override Until Date'),
        'type' => 'date',
        'civicrm_condition' => [
          'andor' => 'or',
          'action' => 'show',
          'rules' => [
            'membership_status_id' => [
              'values' => '0',
              'operator' => 'not_equal',
            ],
          ],
        ],
      ];
      $fields['membership_num_terms'] = [
        'name' => t('Number of Terms'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => 1,
        'empty_option' => t('Enter Dates Manually'),
      ];
      if (isset($sets['contribution'])) {
        $fields['membership_fee_amount'] = [
          'name' => t('Membership Fee'),
        ] + $moneyDefaults;
      }
      $fields['membership_join_date'] = [
        'name' => t('Member Since'),
        'type' => 'date',
      ];
      $fields['membership_start_date'] = [
        'name' => t('Start Date'),
        'type' => 'date',
      ];
      $fields['membership_end_date'] = [
        'name' => t('End Date'),
        'type' => 'date',
      ];
    }
    // Add campaign fields
    if (in_array('CiviCampaign', $components)) {
      $fields['activity_engagement_level'] = [
        'name' => t('Engagement Level'),
        'type' => 'select',
        'empty_option' => t('None'),
        'expose_list' => TRUE,
      ];
      $fields['activity_survey_id'] = [
        'name' => t('Survey/Petition'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('None'),
        'extra' => ['civicrm_live_options' => 1],
      ];
      foreach (array_intersect(['activity', 'membership', 'participant', 'contribution'], array_keys($sets)) as $ent) {
        $fields[$ent . '_campaign_id'] = [
          'name' => t('Campaign'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => ['civicrm_live_options' => 1],
          'empty_option' => t('None'),
        ];
      }
    }
    // CiviGrant fields
    if (isset($sets['grant'])) {
      $fields['grant_contact_id'] = [
        'name' => t('Grant Applicant'),
        'type' => 'select',
        'expose_list' => TRUE,
        'data_type' => 'ContactReference',
      ];
      $fields['grant_grant_type_id'] = [
        'name' => t('Grant Type'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['civicrm_live_options' => 1],
      ];
      $fields['grant_status_id'] = [
        'name' => t('Grant Status'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => 0,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['grant_application_received_date'] = [
        'name' => t('Application Received Date'),
        'type' => 'date',
      ];
      $fields['grant_decision_date'] = [
        'name' => t('Decision Date'),
        'type' => 'date',
      ];
      $fields['grant_money_transfer_date'] = [
        'name' => t('Money Transfer Date'),
        'type' => 'date',
      ];
      $fields['grant_grant_due_date'] = [
        'name' => t('Grant Report Due'),
        'type' => 'date',
      ];
      $fields['grant_grant_report_received'] = [
        'name' => t('Grant Report Received?'),
        'type' => 'select',
        'extra' => ['aslist' => 0],
      ];
      $fields['grant_rationale'] = [
        'name' => t('Grant Rationale'),
        'type' => 'textarea',
      ];
      $fields['grant_note'] = [
        'name' => t('Grant Notes'),
        'type' => 'textarea',
      ];
      $fields['grant_amount_total'] = [
        'name' => t('Amount Requested'),
      ] + $moneyDefaults;
      $fields['grant_amount_granted'] = [
        'name' => t('Amount Granted'),
      ] + $moneyDefaults;
    }

    // File attachment fields
    $numAttachments = wf_crm_get_civi_setting('max_attachments', 3);
    foreach ($sets as $ent => $set) {
      if (!empty($set['attachments']) && $numAttachments) {
        $sets["{$ent}upload"] = [
          'label' => t('File Attachments'),
          'entity_type' => $ent,
        ];
        for ($i = 1; $i <= $numAttachments; $i++) {
          $fields["{$ent}upload_file_$i"] = [
            'name' => t('Attachment !num', ['!num' => $i]),
            'type' => 'file',
            'data_type' => 'File',
          ];
        }
      }
    }

    $tokens = [
      'display_name' => t('display name'),
      'first_name' => t('first name'),
      'nick_name' => t('nickname'),
      'middle_name' => t('middle name'),
      'last_name' => t('last name'),
      'individual_prefix' => t('name prefix'),
      'individual_suffix' => t('name suffix'),
      'gender' => t('gender'),
      'birth_date' => t('birth date'),
      'job_title' => t('job title'),
      'current_employer' => t('current employer'),
      'contact_id' => t('contact id'),
      'street_address' => t('street address'),
      'city' => t('city'),
      'state_province' => t('state/province abbr'),
      'state_province_name' => t('state/province full'),
      'postal_code' => t('postal code'),
      'country' => t('country'),
      'world_region' => t('world region'),
      'phone' => t('phone number'),
      'email' => t('email'),
    ];

    // Fetch custom groups
    list($contact_types) = wf_crm_get_contact_types();
    $custom_sets = [];
    $custom_groups = wf_crm_apivalues('CustomGroup', 'get', [
      'return' => ['title', 'extends', 'extends_entity_column_value', 'extends_entity_column_id', 'is_multiple', 'max_multiple', 'help_pre'],
      'is_active' => 1,
      'extends' => ['IN' => array_keys($contact_types + $sets)],
      'options' => ['sort' => 'weight'],
    ]);
    foreach ($custom_groups as $custom_group) {
      $set = 'cg' . $custom_group['id'];
      $entity_type = strtolower($custom_group['extends']);
      // Place these custom fields directly into their entity
      if (wf_crm_aval($sets, "$entity_type:custom_fields") == 'combined') {
        $set = $entity_type;
      }
      else {
        $sets[$set] = [
          'label' => $custom_group['title'],
          'entity_type' => $entity_type,
          'max_instances' => 1,
        ];
        if (isset($contact_types[$entity_type]) || $entity_type == 'contact') {
          $sets[$set]['entity_type'] = 'contact';
          if ($entity_type != 'contact') {
            $sets[$set]['contact_type'] = $entity_type;
          }
          if (!empty($custom_group['is_multiple'])) {
            $sets[$set]['max_instances'] = $custom_group['max_multiple'] ?? 9;
          }
        }
        if (!empty($custom_group['extends_entity_column_value'])) {
          $sets[$set]['sub_types'] = $custom_group['extends_entity_column_value'];
        }
        if (!empty($custom_group['extends_entity_column_id'])) {
          $sets[$set]['extension_of'] = $custom_group['extends_entity_column_id'];
        }
        $sets[$set]['help_text'] = $custom_group['help_pre'] ?? NULL;
      }
      $custom_sets[$custom_group['id']] = $set;
    }

    // Fetch custom fields
    $custom_types = wf_crm_custom_types_map_array();
    $custom_fields = wf_crm_apivalues('CustomField', 'get', [
      'is_active' => 1,
      'custom_group_id' => ['IN' => array_keys($custom_sets)],
      'html_type' => ['IN' => array_keys($custom_types)],
      'options' => ['sort' => 'weight'],
    ]);
    foreach ($custom_fields as $custom_field) {
      $set = $custom_sets[$custom_field['custom_group_id']];
      $custom_group = $custom_groups[$custom_field['custom_group_id']];
      $id = $set . '_custom_' . $custom_field['id'];
      $fields[$id] = $custom_types[$custom_field['html_type']];
      if ($custom_field['html_type'] == 'Text' && $custom_field['data_type'] == 'Money') {
        $fields[$id] = $moneyDefaults;
      }
      $fields[$id]['name'] = $custom_field['label'];
      $fields[$id]['required'] = (int) !empty($custom_field['is_required']);
      if (!empty($custom_field['default_value'])) {
        $fields[$id]['value'] = implode(',', wf_crm_explode_multivalue_str($custom_field['default_value']));
      }
      $fields[$id]['data_type'] = $custom_field['data_type'];
      if (!empty($custom_field['help_pre']) || !empty($custom_field['help_post'])) {
        $fields[$id]['extra']['description'] = !empty($custom_field['help_pre']) ? $custom_field['help_pre'] : $custom_field['help_post'];
        $fields[$id]['extra']['description_above'] = (int) empty($custom_field['help_pre']);
        $fields[$id]['has_help'] = TRUE;
      }
      // Conditional rule - todo: support additional entities
      if ($sets[$set]['entity_type'] == 'contact' && !empty($sets[$set]['sub_types'])) {
        $fields[$id]['civicrm_condition'] = [
          'andor' => 'or',
          'action' => 'show',
          'rules' => [
            'contact_contact_sub_type' => [
              'values' => $sets[$set]['sub_types'],
            ],
          ],
        ];
      }

      if ($set == 'relationship' && !empty($custom_group['extends_entity_column_value'])) {
        $fields[$id]['attributes']['data-relationship-type'] = implode(',', $custom_group['extends_entity_column_value']);
      }

      if ($fields[$id]['type'] == 'date') {
        $fields[$id]['extra']['start_date'] = (!empty($custom_field['start_date_years']) ? '-' . $custom_field['start_date_years'] : '-50') . ' years';
        $fields[$id]['extra']['end_date'] = (!empty($custom_field['end_date_years']) ? '+' . $custom_field['end_date_years'] : '+50') . ' years';
        // Add "time" component for datetime fields
        if (!empty($custom_field['time_format'])) {
          $fields[$id]['name'] .= ' - ' . t('date');
          $fields[$id . '_timepart'] = [
            'name' => $custom_field['label'] . ' - ' . t('time'),
            'type' => 'time',
            'extra' => ['hourformat' => $custom_field['time_format'] == 1 ? '12-hour' : '24-hour'],
          ];
        }
      }
      elseif ($fields[$id]['data_type'] == 'ContactReference') {
        $fields[$id]['expose_list'] = TRUE;
        $fields[$id]['empty_option'] = t('None');
      }
      elseif ($fields[$id]['data_type'] !== 'Boolean' && $fields[$id]['type'] == 'select') {
        $fields[$id]['extra']['civicrm_live_options'] = 1;
      }
      elseif ($fields[$id]['type'] == 'textarea') {
        $fields[$id]['extra']['cols'] = $custom_field['note_columns'] ?? 60;
        $fields[$id]['extra']['rows'] = $custom_field['note_rows'] ?? 4;
      }
    }
  }
  return $$var;
}

/**
 * Get a field based on its short or full name
 * @param string $key
 * @return array|null
 */
function wf_crm_get_field($key) {
  $fields = wf_crm_get_fields();
  if (isset($fields[$key])) {
    return $fields[$key];
  }
  if ($pieces = wf_crm_explode_key($key)) {
    list( , , , , $table, $name) = $pieces;
    if (isset($fields[$table . '_' . $name])) {
      return $fields[$table . '_' . $name];
    }
  }
}

/**
 * Lookup a uf ID from contact ID or vice-versa
 * With no arguments passed in, this function will return the contact_id of the current logged-in user
 *
 * @param $id
 *   (optional) uf or contact ID - defaults to current user
 * @param $type
 *   (optional) what type of ID is supplied - defaults to user id
 * @return int|null
 */
function wf_crm_user_cid($id = NULL, $type = 'uf') {
  static $current_user = NULL;
  if (!$id) {
    if ($current_user !== NULL) {
      return $current_user;
    }
    global $user;
    $id = $user_lookup = $user->uid;
  }
  if (!$id || !is_numeric($id)) {
    return NULL;
  }
  // Lookup current domain for multisite support
  static $domain = 0;
  if (!$domain) {
    $domain = wf_civicrm_api('domain', 'get', ['current_domain' => 1, 'return' => 'id']);
    $domain = wf_crm_aval($domain, 'id', 1);
  }
  $result = wf_crm_apivalues('uf_match', 'get', [
    $type . '_id' => $id,
    'domain_id' => $domain,
    'sequential' => 1,
  ]);
  if ($result) {
    if (!empty($user_lookup)) {
      $current_user = $result[0]['contact_id'];
    }
    return $type == 'uf' ? $result[0]['contact_id'] : $result[0]['uf_id'];
  }
}

/**
 * Fetch contact display name
 *
 * @param $cid
 *   Contact id
 *
 * @return string
 */
function wf_crm_display_name($cid) {
  if (!$cid || !is_numeric($cid)) {
    return '';
  }
  civicrm_initialize();
  $result = wf_civicrm_api('contact', 'get', ['id' => $cid, 'return.display_name' => 1, 'is_deleted' => 0]);
  return check_plain(wf_crm_aval($result, "values:$cid:display_name", ''));
}

/**
 * @param integer $n
 * @param array $data Form data
 * @param string $html Controls how html should be treated. Options are:
 *  * 'escape': (default) Escape html characters
 *  * 'wrap': Escape html characters and wrap in a span
 *  * 'plain': Do not escape (use when passing into an FAPI options list which does its own escaping)
 * @return string
 */
function wf_crm_contact_label($n, $data = [], $html = 'escape') {
  $label = trim(wf_crm_aval($data, "contact:$n:contact:1:webform_label", ''));
  if (!$label) {
    $label = t('Contact !num', ['!num' => $n]);
  }
  if ($html != 'plain') {
    $label = check_plain($label);
  }
  if ($html == 'wrap') {
    $label = '<span class="contact-label number-' . $n . '">' . $label . '</span>';
  }
  return $label;
}

/**
 * Explodes form key into an array and verifies that it is in the right format
 *
 * @param $key
 *   Webform component field key (string)
 *
 * @return array or NULL
 */
function wf_crm_explode_key($key) {
  $pieces = explode('_', $key, 6);
  if (count($pieces) != 6 || $pieces[0] !== 'civicrm') {
    return FALSE;
  }
  return $pieces;
}

/**
 * Convert a | separated string into an array
 *
 * @param string $str
 *   String representation of key => value select options
 *
 * @return array of select options
 */
function wf_crm_str2array($str) {
  $ret = [];
  if ($str) {
    foreach (explode("\n", trim($str)) as $row) {
      if ($row && $row[0] !== '<' && strpos($row, '|')) {
        list($k, $v) = explode('|', $row);
        $ret[trim($k)] = trim($v);
      }
    }
  }
  return $ret;
}

/**
 * Convert an array into a | separated string
 *
 * @param array $arr
 *   Array of select options
 *
 * @return string
 *   String representation of key => value select options
 */
function wf_crm_array2str($arr) {
  $str = '';
  foreach ($arr as $k => $v) {
    $str .= ($str ? "\n" : '') . $k . '|' . $v;
  }
  return $str;
}

/**
 * Wrapper for all CiviCRM API calls
 * For consistency, future-proofing, and error handling
 *
 * @param string $entity
 *   API entity
 * @param string $operation
 *   API operation
 * @param array $params
 *   API params
 *
 * @return array
 *   Result of API call
 */
function wf_civicrm_api($entity, $operation, $params) {
  if (!$entity) {
    return [];
  }

  $params += [
    'check_permissions' => FALSE,
    'version' => 3
  ];
  $result = civicrm_api($entity, $operation, $params);
  // I guess we want silent errors for getoptions b/c we check it for failure separately
  if (!empty($result['is_error']) && $operation != 'getoptions') {
    $bt = debug_backtrace();
    $n = $bt[0]['function'] == 'wf_civicrm_api' ? 1 : 0;
    $file = explode('/', $bt[$n]['file']);
    if (isset($params['credit_card_number'])) {
      $params['credit_card_number'] = "xxxxxxxxxxxx".substr($params['credit_card_number'], -4);
    }
    watchdog('webform_civicrm',
      'The CiviCRM "%function" API returned the error: "%msg" when called by function "!fn" on line !line of !file with parameters: "!params"',
      [
        '%function' => $entity . ' ' . $operation,
        '%msg' => $result['error_message'],
        '!fn' => $bt[$n+1]['function'],
        '!line' => $bt[$n]['line'],
        '!file' => array_pop($file),
        '!params' => print_r($params, TRUE),
      ],
      WATCHDOG_ERROR);
  }
  return $result;
}

/**
 * Get the values from an api call
 *
 * @param string $entity
 *   API entity
 * @param string $operation
 *   API operation
 * @param array $params
 *   API params
 * @param string $value
 *   Reduce each result to this single value
 *
 * @return array
 *   Values from API call
 */
function wf_crm_apivalues($entity, $operation, $params = [], $value = NULL) {
  if (is_numeric($params)) {
    $params = ['id' => $params];
  }
  $params += ['options' => []];
  // Work around the api's default limit of 25
  $params['options'] += ['limit' => 0];
  // If we only care about one field value
  if ($value && $operation == 'get') {
    $params += ['return' => $value];
  }
  $ret = wf_crm_aval(wf_civicrm_api($entity, $operation, $params), 'values', []);
  if ($value) {
    foreach ($ret as &$values) {
      $values = wf_crm_aval($values, $value);
    }
  }
  return $ret;
}

/**
 * Check if a (non-disabled) name or email field exists for this contact.
 * This determines whether a new contact can be created on the webform.
 *
 * @param $enabled
 *   Array of enabled fields
 * @param $c
 *   Contact #
 * @param $contact_type
 *   Contact type
 * @param stdClass $node
 * @return int
 */
function wf_crm_name_field_exists($enabled, $c, $contact_type, $node) {
  foreach (wf_crm_required_contact_fields($contact_type) as $f) {
    $fid = 'civicrm_' . $c . '_contact_1_' . $f['table'] . '_' . $f['name'];
    if (!empty($enabled[$fid]) && empty($node->webform['components'][$enabled[$fid]]['extra']['disabled'])) {
      return 1;
    }
  }
  return 0;
}

/**
 * At least one of these fields is required to create a contact
 *
 * @param string $contact_type
 * @return array of fields
 */
function wf_crm_required_contact_fields($contact_type) {
  if ($contact_type == 'individual') {
    return [
      ['table' => 'email', 'name' => 'email'],
      ['table' => 'contact', 'name' => 'first_name'],
      ['table' => 'contact', 'name' => 'last_name'],
    ];
  }
  return [['table' => 'contact', 'name' => $contact_type . '_name']];
}

/**
 * These are the contact location fields this module supports
 *
 * @return array
 */
function wf_crm_location_fields() {
  return ['address', 'email', 'phone', 'website', 'im'];
}

/**
 * These are the address fields this module supports
 *
 * @return array
 */
function wf_crm_address_fields() {
  $fields = [];
  foreach (array_keys(wf_crm_get_fields()) as $key) {
    if (strpos($key, 'address') === 0) {
      $fields[] = substr($key, 8);
    }
  }
  return $fields;
}

/**
 * Returns a count of children of a webform component
 *
 * @param int $nid
 * @param int $id
 * @return int
 */
function _wf_crm_child_components($nid, $id) {
  return db_select('webform_component', 'c')
    ->fields('c')
    ->condition('nid', $nid)
    ->condition('pid', $id)
    ->countQuery()
    ->execute()
    ->fetchField();
}

/**
 * @param string
 * @return array
 */
function wf_crm_explode_multivalue_str($str) {
  $sp = CRM_Core_DAO::VALUE_SEPARATOR;
  if (is_array($str)) {
    return $str;
  }
  return explode($sp, trim((string) $str, $sp));
}

/**
 * Check if value is a positive integer
 * @param mixed $val
 * @return bool
 */
function wf_crm_is_positive($val) {
  return is_numeric($val) && $val > 0 && round($val) == $val;
}

/**
 * Returns empty custom civicrm field sets
 *
 * @return array $sets
 */
function wf_crm_get_empty_sets() {
  $sets = wf_crm_get_fields('sets');
  foreach (array_keys(wf_crm_get_fields()) as $key) {
    list($set) = explode('_', $key);
    unset($sets[$set]);
  }
  return $sets;
}

/**
 * Pull custom fields to match with Webform element types
 *
 * @return array
 */
function wf_crm_custom_types_map_array() {
  $custom_types = [
    'Select' => ['type' => 'select'],
    'Multi-Select' => ['type' => 'select', 'extra' => ['multiple' => 1]],
    'Radio' => ['type' => 'select', 'extra' => ['aslist' => 0]],
    'CheckBox' => ['type' => 'select', 'extra' => ['multiple' => 1]],
    'Text'  => ['type' => 'textfield'],
    'TextArea' => ['type' => 'textarea'],
    'RichTextEditor' => ['type' => module_exists('webform_html_textarea') ? 'html_textarea' : 'textarea'],
    'Select Date' => ['type' => 'date'],
    'Link'  => ['type' => 'textfield'],
    'Select Country' => ['type' => 'select'],
    'Multi-Select Country' => ['type' => 'select', 'extra' => ['multiple' => 1]],
    'Select State/Province' => ['type' => 'select'],
    'Multi-Select State/Province' => ['type' => 'select', 'extra' => ['multiple' => 1]],
    'Autocomplete-Select' => ['type' => module_exists('webform_autocomplete') ? 'autocomplete' : 'select'],
    'File' => ['type' => 'file'],
  ];

  return $custom_types;
}

/**
 * @param string $setting_name
 * @param mixed $default_value
 * @return mixed
 */
function wf_crm_get_civi_setting($setting_name, $default_value = NULL) {
  $aliases = [
    'defaultCurrencySymbol' => 'defaultCurrency',
  ];
  $settings = wf_civicrm_api('Setting', 'get', [
    'sequential' => 1,
    'return' => str_replace(array_keys($aliases), array_values($aliases), $setting_name),
  ]);
  // Not a real setting, requires cross-lookup
  if ($setting_name == 'defaultCurrencySymbol') {
    $currencies = wf_crm_apivalues('Contribution', 'getoptions', [
      'field' => "currency",
      'context' => "abbreviate",
    ]);
    return wf_crm_aval($currencies, $settings['values'][0]['defaultCurrency'], $default_value);
  }
  $result = wf_crm_aval($settings, "values:0:$setting_name", $default_value);
  if ($result === 'default') {
    return $default_value;
  }
  return $result;
}
