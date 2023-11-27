<?php

namespace Drupal\webform_civicrm;

/**
 * @file
 * Webform CiviCRM module's common utility functions.
 */
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\webform\WebformInterface;

class Utils implements UtilsInterface {

  /**
   * The related request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * Constructs a utils object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  function __construct(RequestStack $requestStack) {
    $this->requestStack = $requestStack;
  }

  /**
   * Explodes form key into an array and verifies that it is in the right format
   *
   * @param $key
   *   Webform component field key (string)
   *
   * @return array or NULL
   */
  public function wf_crm_explode_key($key) {
    $pieces = explode('_', $key, 6);
    if (count($pieces) !== 6 || $pieces[0] !== 'civicrm') {
      return NULL;
    }
    return $pieces;
  }

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
  public static function wf_crm_field_options($field, $context, $data) {
    return \Drupal::service('webform_civicrm.field_options')->get($field, $context, $data);
  }

  /**
   * Fetches CiviCRM field data.
   *
   * @param string $var
   *   Name of variable to return: fields, tokens, or sets
   *
   * @return array
   * @return array
   *   fields: The CiviCRM contact fields this module supports
   *   tokens: Available tokens keyed to field ids
   *   sets: Info on fieldsets (entities)
   */
  public function wf_crm_get_fields($var = 'fields') {
    return \Drupal::service('webform_civicrm.fields')->get($var);
  }

  /**
   * Get list of states, keyed by ID.
   * @param null|int|string $param
   */
  public function wf_crm_get_states($param = NULL) {
    $ret = [];
    if (!$param || $param == 'default') {
      $settings = $this->wf_civicrm_api('Setting', 'get', [
        'sequential' => 1,
        'return' => 'provinceLimit',
      ]);
      $provinceLimit = wf_crm_aval($settings, "values:0:provinceLimit");
      if (!$param && $provinceLimit) {
        $param = (array) $provinceLimit;
      }
      else {
        $settings = $this->wf_civicrm_api('Setting', 'get', [
          'sequential' => 1,
          'return' => 'defaultContactCountry',
        ]);
        $param = [(int) wf_crm_aval($settings, "values:0:defaultContactCountry", 1228)];
      }
    }
    else {
      $param = [(int) $param];
    }
    $states = $this->wf_crm_apivalues('state_province', 'get', [
      'return' => 'abbreviation,name',
      'sort' => 'name',
      'country_id' => ['IN' => $param],
    ]);
    foreach ($states as $state) {
      $ret[$state['id']] = $state['name'];
    }
    // Localize the state/province names if in an non-en_US locale
    $tsLocale = \CRM_Utils_System::getUFLocale();
    if ($tsLocale !== '' && $tsLocale !== 'en_US') {
      $i18n = \CRM_Core_I18n::singleton();
      $i18n->localizeArray($ret, ['context' => 'province']);
      \CRM_Utils_Array::asort($ret);
    }
    return $ret;
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
    $sort_field = wf_crm_aval($reg_options, 'event_sort_field', 'start_date');
    $sort_order = ($context == 'config_form' && $sort_field === 'start_date') ? ' DESC' : '';
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
    $params['options'] = ['sort' => $sort_field . $sort_order];
    $values = $this->wf_crm_apivalues('Event', 'get', $params);
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
    // A "full" event is one where the maximum participants is less than or equal to the number of registered participants (whose roles count toward the registration cap).
    // FIXME: When we move to API4, we should ensure Event.get has a calculated "registered_participants" field tp avoid an API call per event.
    // For now, keep the "show full" check last to minimize the API calls.
    if (!wf_crm_aval($reg_options, 'show_full_events', '1', TRUE)) {
      $rolesThatCount = array_column($this->wf_crm_apivalues('OptionValue', 'get', ['option_group_id' => "participant_role", 'filter' => 1]), 'value');
      foreach ($values as $key => $value) {
        if (!empty($value['max_participants'])) {
          $registrationCount = $this->wf_civicrm_api('Participant', 'getcount', ['event_id' => $key, 'role_id' => ['IN' => $rolesThatCount]]);
          if ($registrationCount >= $value['max_participants']) {
            unset($values[$key]);
          }
        }
      }
    }
    foreach ($values as $value) {
      $ret[$value['id'] . '-' . $value['event_type_id']] = $this->wf_crm_format_event($value, $format);
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
        $date_format = $this->wf_crm_get_civi_setting($value);
      }
    }
    $title = [];
    if (in_array('title', $format)) {
      $title[] = $event['title'];
    }
    if (in_array('type', $format)) {
      $types = $this->wf_crm_apivalues('event', 'getoptions', [
        'field' => 'event_type_id',
        'context' => 'get',
      ]);
      $title[] = $types[$event['event_type_id']];
    }
    if (in_array('start', $format) && !empty($event['start_date'])) {
      $title[] = \CRM_Utils_Date::customFormat($event['start_date'], $date_format);
    }
    if (in_array('end', $format) && isset($event['end_date'])) {
      // Avoid showing redundant end-date if it is the same as the start date
      $same_day = substr($event['start_date'], 0, 10) == substr($event['end_date'], 0, 10);
      if (!$same_day || in_array('dateformatDatetime', $format) || in_array('dateformatTime', $format)) {
        $end_format = (in_array('dateformatDatetime', $format) && $same_day) ? wf_crm_get_civi_setting('dateformatTime') : $date_format;
        $title[] = \CRM_Utils_Date::customFormat($event['end_date'], $end_format);
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
    $tag_display_field = $this->tag_display_field();
    $tags = $this->wf_crm_apivalues('Tag', 'get', $params, $tag_display_field);
    // Tagsets cannot be nested so no need to fetch children
    if ($parent_id || !$tags) {
      return $tags;
    }
    // Fetch child tags
    unset($params['parent_id']);
    $params += ['return' => [$tag_display_field, 'parent_id'], 'parent_id.is_tagset' => 0, 'parent_id.is_selectable' => 1, 'parent_id.used_for' => $params['used_for']];
    $unsorted = $this->wf_crm_apivalues('Tag', 'get', $params);
    $parents = array_fill_keys(array_keys($tags), ['depth' => 1]);
    // Place children under their parents.
    $prevLoop = NULL;
    while ($unsorted && count($unsorted) !== $prevLoop) {
      // If count stops going down then we are left with only orphan tags & will abort the loop
      $prevLoop = count($unsorted);
      foreach ($unsorted as $id => $tag) {
        $parent = $tag['parent_id'];
        if (isset($parents[$parent])) {
          $name = str_repeat('- ', $parents[$parent]['depth']) . $tag[$tag_display_field];
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
  function wf_crm_get_surveys($act = []) {
    return $this->wf_crm_apivalues('survey', 'get', array_filter($act), 'title');
  }

  /**
   * Get activity types related to CiviCampaign
   * @return array
   */
  function wf_crm_get_campaign_activity_types() {
    $ret = [];
    if (array_key_exists('activity_survey_id', $this->wf_crm_get_fields())) {
      $vals = $this->wf_crm_apivalues('option_value', 'get', [
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
      $data = $this->wf_crm_apivalues('contact_type', 'get', ['is_active' => 1]);
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
      foreach ($this->wf_crm_apivalues('relationship_type', 'get', ['is_active' => 1]) as $r) {
        $r['type_a'] = strtolower(wf_crm_aval($r, 'contact_type_a') ?? '');
        $r['type_b'] = strtolower(wf_crm_aval($r, 'contact_type_b') ?? '');
        $r['sub_type_a'] = wf_crm_aval($r, 'contact_sub_type_a');
        if (!is_null($r['sub_type_a'])) {
          $r['sub_type_a'] = $r['sub_type_a'];
        }
        $r['sub_type_b'] = wf_crm_aval($r, 'contact_sub_type_b');
        if (!is_null($r['sub_type_b'])) {
          $r['sub_type_b'] = $r['sub_type_b'];
        }
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
    foreach ($this->wf_crm_get_relationship_types() as $t) {
      $reciprocal = ($t['label_a_b'] != $t['label_b_a'] && $t['label_b_a'] || $t['type_a'] != $t['type_b'] || $t['sub_type_a'] != $t['sub_type_b']);
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
      $values = $this->wf_crm_apivalues('RuleGroup', 'get');
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
    $enabled = [];
    $elements = $webform->getElementsDecodedAndFlattened();
    if (!empty($elements) || ($show_all)) {
      $handler_collection = $webform->getHandlers('webform_civicrm');
      if (!$handler_collection->has('webform_civicrm')) {
        return $enabled;
      }
      /** @var \Drupal\webform\Plugin\WebformHandlerInterface $handler */
      $handler = $handler_collection->get('webform_civicrm');
      $handler_configuration = $handler->getConfiguration();

      $fields = $this->wf_crm_get_fields();
      foreach ($elements as $key => $c) {
        $exp = explode('_', $key, 5);
        $customGroupFieldsetKey = '';
        if (count($exp) == 5) {
          [$lobo, $i, $ent, $n, $id] = $exp;
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
          $fieldSetIds = ['fieldset_fieldset', "{$customGroupFieldsetKey}_fieldset", "number_of_billing_1_fieldset_fieldset"];
          if ((isset($fields[$id]) || (in_array($id, $fieldSetIds))) && is_numeric($i) && is_numeric($n)) {
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
    $fields = $this->wf_crm_get_fields();
    if (isset($fields[$key])) {
      return $fields[$key];
    }
    if ($pieces = $this->wf_crm_explode_key($key)) {
      [ , , , , $table, $name] = $pieces;
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
      $domain = $this->wf_civicrm_api('domain', 'get', ['current_domain' => 1, 'return' => 'id']);
      $domain = wf_crm_aval($domain, 'id', 1);
    }
    $result = $this->wf_crm_apivalues('uf_match', 'get', [
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
    \Drupal::getContainer()->get('civicrm')->initialize();
    $result = $this->wf_civicrm_api('contact', 'get', ['id' => $cid, 'return.display_name' => 1, 'is_deleted' => 0]);
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
  function wf_crm_contact_label($n, $data = [], $html = 'escape') {
    $label = trim(wf_crm_aval($data, "contact:$n:contact:1:webform_label", ''));
    if (!$label) {
      $label = t('Contact :num', [':num' => $n]);
    }
    if ($html != 'plain') {
      $label = Html::escape($label);
    }
    if ($html == 'wrap') {
      $label = Markup::create($label);
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
    $ret = [];
    if ($str) {
      foreach (explode("\n", trim($str)) as $row) {
        if ($row && $row[0] !== '<' && strpos($row, '|')) {
          [$k, $v] = explode('|', $row);
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
   * @inheritDoc
   */
  function wf_civicrm_api4($entity, $operation, $params, $index = NULL) {
    if (!$entity) {
      return [];
    }
    $params += [
      'checkPermissions' => FALSE,
    ];
    $result = civicrm_api4($entity, $operation, $params, $index);
    return $result;
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
    ];
    if ($operation == 'transact') {
      $utils = \Drupal::service('webform_civicrm.utils');
      $result = $utils->wf_civicrm_api3_contribution_transact($params);
    }
    else {
      $result = civicrm_api3($entity, $operation, $params);
    }
    // I guess we want silent errors for getoptions b/c we check it for failure separately
    if (!empty($result['is_error']) && $operation != 'getoptions') {
      $bt = debug_backtrace();
      $n = $bt[0]['function'] == 'wf_civicrm_api' ? 1 : 0;
      $file = explode('/', $bt[$n]['file']);
      if (isset($params['credit_card_number'])) {
        $params['credit_card_number'] = "xxxxxxxxxxxx".substr($params['credit_card_number'], -4);
      }
      \Drupal::logger('webform_civicrm')->error(
        'The CiviCRM "@function" API returned the error: "@msg" when called by function "@fn" on line @line of @file with parameters: "@params"',
        [
          '@function' => $entity . ' ' . $operation,
          '@msg' => $result['error_message'],
          '@fn' => $bt[$n+1]['function'],
          '@line' => $bt[$n]['line'],
          '@file' => array_pop($file),
          '@params' => print_r($params, TRUE),
        ]
      );
    }
    return $result;
  }

  /**
   * Process a transaction and record it against the contact.
   *
   * @deprecated
   *
   * @param array $params
   *   Input parameters.
   *
   * @return array
   *   contribution of created or updated record (or a civicrm error)
   */
  function wf_civicrm_api3_contribution_transact($params) {
    // Start with the same parameters as Contribution.transact.
    $params['contribution_status_id'] = 'Pending';
    if (!isset($params['invoice_id']) && !isset($params['invoiceID'])) {
      // Set an invoice_id here if you have not already done so.
      // Potentially Order api should do this https://lab.civicrm.org/dev/financial/issues/78
      $params['invoice_id'] = md5(uniqid(rand(), TRUE));
    }
    if (isset($params['invoice_id']) && !isset($params['invoiceID'])) {
      // This would be required prior to https://lab.civicrm.org/dev/financial/issues/77
      $params['invoiceID'] = $params['invoice_id'];
    }
    elseif (!isset($params['invoice_id']) && isset($params['invoiceID'])) {
      $params['invoice_id'] = $params['invoiceID'];
    }

    $order = civicrm_api3('Order', 'create', $params);
    try {
      $params['amount'] = $params['total_amount'];
      $params['contribution_id'] = $order['id'];
      $payParams = $params;
      $payResult = reset(civicrm_api3('PaymentProcessor', 'pay', $payParams)['values']);

      // webform_civicrm sends out receipts using Contribution.send_confirmation API if the contribution page is has is_email_receipt = TRUE.
      // We allow this to be overridden here but default to FALSE.
      $params['is_email_receipt'] = $params['is_email_receipt'] ?? FALSE;

      // payment_status_id is deprecated - https://lab.civicrm.org/dev/financial/-/issues/141
      if (!isset($payResult['payment_status'])) {
        $payResult['payment_status'] = 'Pending';
        // payment_status_id = 1 -> payment completed;
        // payment_status_id = 2 -> payment NOT completed;
        if ($payResult['payment_status_id'] == '1') {
          $payResult['payment_status'] = 'Completed';
        }
      }

      // Assuming the payment was taken, record it which will mark the Contribution
      // as Completed and update related entities.
      if ($payResult['payment_status'] === 'Completed') {
        civicrm_api3('Payment', 'create', [
          'contribution_id' => $order['id'],
          'total_amount' => $payParams['amount'],
          'fee_amount' => $payResult['fee_amount'] ?? 0,
          'payment_instrument_id' => $order['values'][$order['id']]['payment_instrument_id'],
          'payment_processor_id' => $payParams['payment_processor_id'],
          'is_send_contribution_notification' => $params['is_email_receipt'],
          'trxn_id' => $payResult['trxn_id'] ?? NULL,
        ]);
      } else {
        civicrm_api3('Contribution', 'create', [
          'id' => $order['id'],
          'total_amount' => $payParams['amount'],
          'fee_amount' => $payResult['fee_amount'] ?? 0,
          'payment_instrument_id' => $order['values'][$order['id']]['payment_instrument_id'],
          'payment_processor_id' => $payParams['payment_processor_id'],
          'trxn_id' => $payResult['trxn_id'] ?? NULL,
          ]);
      }
    } catch (\Exception $e) {
      return ['error_message' => $e->getMessage()];
    }

    // Contribution.transact is expected to return an API3 result containing the contribution
    //   eg. [ 'id' => X, 'values' => [ X => [ contribution details ] ]    return $contribution;
    return civicrm_api3('Contribution', 'get', ['id' => $order['id']]);
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
    $ret = wf_crm_aval($this->wf_civicrm_api($entity, $operation, $params), 'values', []);
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
    foreach ($this->wf_crm_required_contact_fields($contact_type) as $f) {
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
    foreach (array_keys($this->wf_crm_get_fields()) as $key) {
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
    $sp = \CRM_Core_DAO::VALUE_SEPARATOR;
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
    $sets = [];

    $sql = "SELECT cg.id, cg.title, cg.help_pre, cg.extends, SUM(cf.is_active) as custom_field_sum
            FROM civicrm_custom_group cg
            LEFT OUTER JOIN civicrm_custom_field cf
            ON (cg.id = cf.custom_group_id)
            GROUP By cg.id";

    $dao = \CRM_Core_DAO::executeQuery($sql);

    while($dao->fetch()) {
      // Because a set with all fields disabled = empty set
      if (empty($dao->custom_field_sum)) {
        $set = 'cg' . $dao->id;
        if ($dao->extends == 'address' || $dao->extends == 'relationship' || $dao->extends == 'membership') {
          $set = $dao->extends;
        }
        $sets[$set] = [
          'label' => $dao->title,
          'entity_type' => strtolower($dao->extends),
          'help_text' => $dao->help_pre,
        ];
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
    $custom_types = [
      'Select' => ['type' => 'select'],
      'Multi-Select' => ['type' => 'select', 'extra' => ['multiple' => 1]],
      'Radio' => ['type' => 'select', 'extra' => ['aslist' => 0]],
      'CheckBox' => ['type' => 'select', 'extra' => ['multiple' => 1]],
      'Text'  => ['type' => 'textfield'],
      'TextArea' => ['type' => 'textarea'],
      'RichTextEditor' => ['type' => 'text_format'],
      'Select Date' => ['type' => 'date'],
      'Link'  => ['type' => 'textfield'],
      'Select Country' => ['type' => 'select'],
      'Multi-Select Country' => ['type' => 'select', 'extra' => ['multiple' => 1]],
      'Select State/Province' => ['type' => 'select'],
      'Multi-Select State/Province' => ['type' => 'select', 'extra' => ['multiple' => 1]],
      'Autocomplete-Select' => ['type' => \Drupal::moduleHandler()->moduleExists('webform_autocomplete') ? 'autocomplete' : 'select'],
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
    $settings = $this->wf_civicrm_api('Setting', 'get', [
      'sequential' => 1,
      'return' => str_replace(array_keys($aliases), array_values($aliases), $setting_name),
    ]);
    // Not a real setting, requires cross-lookup
    if ($setting_name == 'defaultCurrencySymbol') {
      $currencies = $this->wf_crm_apivalues('Contribution', 'getoptions', [
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

  /**
   * Searches for all occurrence of form key in the array and
   * unsets it from the webform element.
   *
   * @param array $elements
   * @param string $form_key
   */
  function remove_element(&$elements, $form_key) {
    unset($elements[$form_key]);
    foreach ($elements as $k => &$value) {
      if (is_array($value)) {
        $this->remove_element($value, $form_key);
      }
      elseif ($value === $form_key) {
        unset($elements[$k]);
      }
    }
  }

  /**
   * Build params for contribution receipt.
   *
   * @return array
   */
  public function getReceiptParams($data, $contributionID) {
    $contributionData = wf_crm_aval($data, 'contribution:1:contribution:1');
    $params = ['id' => $contributionID];
    $params['payment_processor_id'] = $contributionData['payment_processor_id'] ?? $data['civicrm_1_contribution_1_contribution_payment_processor_id'] ?? NULL;
    unset($params['payment_processor']);

    $params['financial_type_id'] = $contributionData['financial_type_id'] ?? $data['civicrm_1_contribution_1_contribution_financial_type_id_raw'] ?? NULL;
    $params['currency'] = wf_crm_aval($data, "contribution:1:currency");

    //Assign receipt values set on the webform config page.
    $receipt = wf_crm_aval($data, "receipt", []);
    $receiptValues = ['cc_receipt', 'bcc_receipt', 'receipt_text', 'pay_later_receipt', 'receipt_from_name', 'receipt_from_email'];
    foreach ($receiptValues as $val) {
      $params[$val] = $receipt["number_number_of_receipt_{$val}"] ?? '';
    }
    return $params;
  }

  /**
   * Does an element support multiple values
   *
   * @param array $element
   */
  public function hasMultipleValues($element) {
    if (!empty($element['#extra']['multiple']) ||
      (empty($element['#civicrm_live_options'])
      && empty($element['#extra']['aslist'])
      && !empty($element['#options']) && is_array($element['#options'])
      && count($element['#options']) === 1)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @inheritDoc
   */
  public function checksumUserAccess($c, $cid) {
    $request = $this->requestStack->getCurrentRequest();
    $urlCidN = $urlChecksumN = NULL;
    $session = \CRM_Core_Session::singleton();
    $urlCid1 = $request->query->get('cid');
    $urlChecksum1 = $request->query->get('cs');

    $urlCidN = $request->query->get("cid$c");
    $urlChecksumN = $request->query->get("cs$c");

    $cs = NULL;
    if ($c == 1 && !empty($urlChecksum1)) {
      $cs = $urlChecksum1;
    }
    elseif (!empty($urlChecksumN)) {
      $cs = $urlChecksumN;
    }
    if ($cs && (($c == 1 && $urlCid1 == $cid) || $urlCidN == $cid)) {
      $check_access = $this->wf_civicrm_api4('Contact', 'validateChecksum', [
        'contactId' => $cid,
        'checksum' => $cs,
      ])[0] ?? [];
      if ($check_access['valid']) {
        if ($c == 1) {
          $session->set('userID', $cid);
        }
        return TRUE;
      }
    }
    // If access is checked for non primary contact, check if c1 has access to view it.
    elseif ($c != 1 && $this->isContactAccessible($cid)) {
      return TRUE;
    }
    // If no checksum is passed and user is anonymous, reset prev checksum session values if any.
    if (\Drupal::currentUser()->isAnonymous() && $session->get('userID') && $c == 1 && empty($urlChecksum1)) {
      $session->reset();
    }
    return FALSE;
  }

  /**
   * @inheritDoc
   */
  public function isContactAccessible($cid) {
    $access = $this->wf_civicrm_api4('Contact', 'checkAccess', [
      'action' => 'get',
      'values' => [
        'id' => $cid,
      ],
    ], 0);
    if (!empty($access['access'])) {
      return TRUE;
    }

    $request = $this->requestStack->getCurrentRequest();
    $urlCid1 = $request->query->get('cid') ?? $request->query->get('cid1') ?? NULL;
    $urlChecksum1 = $request->query->get('cs') ?? $request->query->get('cs1') ?? NULL;

    if (!empty($urlChecksum1) && !empty($urlCid1)) {
      $valid = $this->wf_civicrm_api4('Contact', 'validateChecksum', [
        'contactId' => $urlCid1,
        'checksum' => $urlChecksum1,
      ])[0] ?? [];
      if ($valid['valid']) {
        // checkAccess v4 api does not check for access via relationship.
        if (\CRM_Contact_BAO_Contact_Permission::allow($cid)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }
  
  /**
   * @return string Which field is the tag display field in this version of civi?
   */
  public function tag_display_field(): string {
    if (version_compare(\CRM_Core_BAO_Domain::version(), '5.68.alpha1', '>=')) {
      return 'label';
    }
    return 'name';
  }

}
