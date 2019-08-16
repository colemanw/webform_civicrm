<?php

/**
 * @file
 * Webform CiviCRM module's common utility functions.
 */

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\webform\WebformInterface;
use Drupal\webform_civicrm\Utils;

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
  $params += ['return' => ['name', 'parent_id'], 'parent_id.is_tagset' => 0, 'parent_id.is_selectable' => 1];
  $unsorted = wf_crm_apivalues('Tag', 'get', $params);
  $parents = array_fill_keys(array_keys($tags), ['depth' => 1]);
  // Loop until all children are placed under their parents
  while ($unsorted) {
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
 * Get list of surveys
 * @param array $act
 *
 * @return array
 */
function wf_crm_get_surveys($act = array()) {
  return wf_crm_apivalues('survey', 'get', array_filter($act), 'title');
}

/**
 * Get activity types related to CiviCampaign
 * @return array
 */
function wf_crm_get_campaign_activity_types() {
  $ret = array();
  if (array_key_exists('activity_survey_id', Utils::wf_crm_get_fields())) {
    $vals = wf_crm_apivalues('option_value', 'get', array(
      'option_group_id' => 'activity_type',
      'is_active' => 1,
      'component_id' => 'CiviCampaign',
    ));
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
  static $contact_types = array();
  static $sub_types = array();
  if (!$contact_types) {
    $data = wf_crm_apivalues('contact_type', 'get', array('is_active' => 1));
    foreach ($data as $type) {
      if (empty($type['parent_id'])) {
        $contact_types[strtolower($type['name'])] = $type['label'];
        continue;
      }
      $sub_types[strtolower($data[$type['parent_id']]['name'])][$type['name']] = $type['label'];
    }
  }
  return array($contact_types, $sub_types);
}

/**
 * In reality there is no contact field 'privacy' so this is not a real option list.
 * These are actually 5 separate contact fields that this module munges into 1 for better usability.
 *
 * @return array
 */
function wf_crm_get_privacy_options() {
  return array(
    'do_not_email' => ts('Do not email'),
    'do_not_phone' => ts('Do not phone'),
    'do_not_mail' => ts('Do not mail'),
    'do_not_sms' => ts('Do not sms'),
    'do_not_trade' => ts('Do not trade'),
    'is_opt_out' => ts('NO BULK EMAILS (User Opt Out)'),
  );
}

/**
 * Get relationship type data
 *
 * @return array
 */
function wf_crm_get_relationship_types() {
  static $types = array();
  if (!$types) {
    foreach (wf_crm_apivalues('relationship_type', 'get', array('is_active' => 1)) as $r) {
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
  $ret = array();
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
 * @param \Drupal\webform\WebformInterface $webform
 *   The webform.
 * @param array|null $submission
 *   (optional) if supplied, will match field keys with submitted values
 * @param boolean $show_all
 *   (optional) if true, get every field even if it belongs to a contact that does not exist
 *
 * @return array of enabled fields
 */
function wf_crm_enabled_fields(WebformInterface $webform, $submission = NULL, $show_all = FALSE) {
  $enabled = array();
  $elements = $webform->getElementsDecodedAndFlattened();
  if (!empty($elements) || ($show_all)) {
    $handler_collection = $webform->getHandlers('webform_civicrm');
    if (!$handler_collection->has('webform_civicrm')) {
      return $enabled;
    }
    /** @var \Drupal\webform\Plugin\WebformHandlerInterface $handler */
    $handler = $handler_collection->get('webform_civicrm');
    $handler_configuration = $handler->getConfiguration();

    $fields = Utils::wf_crm_get_fields();
    foreach ($elements as $key => $c) {
      $exp = explode('_', $key, 5);
      $customGroupFieldsetKey = '';
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
          if (!$show_all && ($ent == 'contact' || $ent == 'participant') && empty($handler_configuration['settings']['data']['contact'][$i])) {
            continue;
          }
          if ($submission) {
            $enabled[$key] = wf_crm_aval($submission, $c['#form_key'], NULL, TRUE);
          }
          else {
            $enabled[$key] = $key;
          }
        }
      }
    }
  }
  return $enabled;
}

/**
 * Get a field based on its short or full name
 * @param string $key
 * @return array|null
 */
function wf_crm_get_field($key) {
  $fields = Utils::wf_crm_get_fields();
  if (isset($fields[$key])) {
    return $fields[$key];
  }
  if ($pieces = Utils::wf_crm_explode_key($key)) {
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
    $id = $user_lookup = \Drupal::currentUser()->id();
  }
  if (!$id || !is_numeric($id)) {
    return NULL;
  }
  // Lookup current domain for multisite support
  static $domain = 0;
  if (!$domain) {
    $domain = wf_civicrm_api('domain', 'get', array('current_domain' => 1, 'return' => 'id'));
    $domain = wf_crm_aval($domain, 'id', 1);
  }
  $result = wf_crm_apivalues('uf_match', 'get', array(
    $type . '_id' => $id,
    'domain_id' => $domain,
    'sequential' => 1,
  ));
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
  \Drupal::getContainer()->get('civicrm')->initialize();
  $result = wf_civicrm_api('contact', 'get', array('id' => $cid, 'return.display_name' => 1, 'is_deleted' => 0));
  return Html::escape(wf_crm_aval($result, "values:$cid:display_name", ''));
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
function wf_crm_contact_label($n, $data = array(), $html = 'escape') {
  $label = trim(wf_crm_aval($data, "contact:$n:contact:1:webform_label", ''));
  if (!$label) {
    $label = t('Contact :num', array(':num' => $n));
  }
  if ($html != 'plain') {
    $label = Html::escape($label);
  }
  if ($html == 'wrap') {
    $label = Markup::create('<span class="contact-label number-' . $n . '">' . $label . '</span>');
  }
  return $label;
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
  $ret = array();
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
  $params += array(
    'check_permissions' => FALSE,
    'version' => 3
  );
  $result = civicrm_api($entity, $operation, $params);
  // I guess we want silent errors for getoptions b/c we check it for failure separately
  if (!empty($result['is_error']) && $operation != 'getoptions') {
    $bt = debug_backtrace();
    $n = $bt[0]['function'] == 'wf_civicrm_api' ? 1 : 0;
    $file = explode('/', $bt[$n]['file']);
    if (isset($params['credit_card_number'])) {
      $params['credit_card_number'] = "xxxxxxxxxxxx".substr($params['credit_card_number'], -4);
    }
    $stop = null;
    \Drupal::logger('webform_civicrm')->error(
      'The CiviCRM ":function" API returned the error: ":msg" when called by function ":fn" on line :line of :file with parameters: ":params"',
      array(
        ':function' => $entity . ' ' . $operation,
        ':msg' => $result['error_message'],
        ':fn' => $bt[$n+1]['function'],
        ':line' => $bt[$n]['line'],
        ':file' => array_pop($file),
        ':params' => print_r($params, TRUE),
      )
    );
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
function wf_crm_apivalues($entity, $operation, $params = array(), $value = NULL) {
  if (is_numeric($params)) {
    $params = array('id' => $params);
  }
  $params += array('options' => array());
  // Work around the api's default limit of 25
  $params['options'] += array('limit' => 0);
  $ret = wf_crm_aval(wf_civicrm_api($entity, $operation, $params), 'values', array());
  if ($value) {
    foreach ($ret as &$values) {
      $values = wf_crm_aval($values, $value);
    }
  }
  return $ret;
}

/**
 * Check if a name or email field exists for this contact.
 * This determines whether a new contact can be created on the webform.
 *
 * @param $enabled
 *   Array of enabled fields
 * @param $c
 *   Contact #
 * @param $contact_type
 *   Contact type
 * @return int
 */
function wf_crm_name_field_exists($enabled, $c, $contact_type) {
  foreach (wf_crm_required_contact_fields($contact_type) as $f) {
    $fid = 'civicrm_' . $c . '_contact_1_' . $f['table'] . '_' . $f['name'];
    if (!empty($enabled[$fid])) {
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
    return array(
      array('table' => 'email', 'name' => 'email'),
      array('table' => 'contact', 'name' => 'first_name'),
      array('table' => 'contact', 'name' => 'last_name'),
      array('table' => 'contact', 'name' => 'nick_name'),
    );
  }
  return array(array('table' => 'contact', 'name' => $contact_type . '_name'));
}

/**
 * These are the contact location fields this module supports
 *
 * @return array
 */
function wf_crm_location_fields() {
  return array('address', 'email', 'phone', 'website', 'im');
}

/**
 * These are the address fields this module supports
 *
 * @return array
 */
function wf_crm_address_fields() {
  $fields = array();
  foreach (array_keys(Utils::wf_crm_get_fields()) as $key) {
    if (strpos($key, 'address') === 0) {
      $fields[] = substr($key, 8);
    }
  }
  return $fields;
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
  $sets = array();

  $sql = "SELECT cg.id, cg.title, cg.help_pre, cg.extends, SUM(cf.is_active) as custom_field_sum
          FROM civicrm_custom_group cg
          LEFT OUTER JOIN civicrm_custom_field cf
          ON (cg.id = cf.custom_group_id)
          GROUP By cg.id";

  $dao = CRM_Core_DAO::executeQuery($sql);

  while($dao->fetch()) {
    // Because a set with all fields disabled = empty set
    if (empty($dao->custom_field_sum)) {
      $set = 'cg' . $dao->id;
      if ($dao->extends == 'address' || $dao->extends == 'relationship' || $dao->extends == 'membership') {
        $set = $dao->extends;
      }
      $sets[$set] = array(
        'label' => $dao->title,
        'entity_type' => strtolower($dao->extends),
        'help_text' => $dao->help_pre,
      );
    }
  }

  return $sets;
}

/**
 * Pull custom fields to match with Webform element types
 *
 * @return array
 */
function wf_crm_custom_types_map_array() {
  $custom_types = array(
    'Select' => array('type' => 'select'),
    'Multi-Select' => array('type' => 'select', 'extra' => array('multiple' => 1)),
    'AdvMulti-Select' => array('type' => 'select', 'extra' => array('multiple' => 1)),
    'Radio' => array('type' => 'select', 'extra' => array('aslist' => 0)),
    'CheckBox' => array('type' => 'select', 'extra' => array('multiple' => 1)),
    'Text'  => array('type' => 'textfield'),
    'TextArea' => array('type' => 'textarea'),
    'RichTextEditor' => array('type' => \Drupal::moduleHandler()->moduleExists('webform_html_textarea') ? 'html_textarea' : 'textarea'),
    'Select Date' => array('type' => 'date'),
    'Link'  => array('type' => 'textfield'),
    'Select Country' => array('type' => 'select'),
    'Multi-Select Country' => array('type' => 'select', 'extra' => array('multiple' => 1)),
    'Select State/Province' => array('type' => 'select'),
    'Multi-Select State/Province' => array('type' => 'select', 'extra' => array('multiple' => 1)),
    'Autocomplete-Select' => array('type' => \Drupal::moduleHandler()->moduleExists('webform_autocomplete') ? 'autocomplete' : 'select'),
    'File' => array('type' => 'file'),
  );

  return $custom_types;
}

/**
 * @param string $setting_name
 * @param mixed $default_value
 * @return mixed
 */
function wf_crm_get_civi_setting($setting_name, $default_value = NULL) {
  $settings = wf_civicrm_api('Setting', 'get', ['sequential' => 1, 'return' => $setting_name]);
  return wf_crm_aval($settings, "values:0:$setting_name", $default_value);
}
