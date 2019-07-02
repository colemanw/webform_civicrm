<?php

namespace Drupal\Tests\webform_civicrm\Kernel;

use CRM_Core_Config;
use CRM_Core_DAO;
use CRM_Utils_Date;

class ApiReplacementTest  extends CiviCRMTestBase {

  /**
   *
   */
  protected function setUp() {
    parent::setUp();
    module_load_include('inc','webform_civicrm','includes/utils');
    // needed to load the wf_crm_aval function
    module_load_include('module','webform_civicrm','webform_civicrm');
  }

  /**
   *  test for wf_crm_state_abbr
   */
  public function test_CrmStateAbbr() {
    $nlId = 1152;
    $cuId = wf_crm_state_abbr('CU','id',$nlId);
    $this->assertNull($cuId,'Culemborg is not (yet) a separate province of the Netherlands');
    $glId = wf_crm_state_abbr('GL','id',$nlId);
    $this->assertNotNull($glId,'Gelderland should have a code');
    $glCode =  wf_crm_state_abbr($glId,'abbreviation',$nlId);
    $this->assertEquals('GL',$glCode);
  }

  /**
   * The old function, copied here to compare the test results
   * @param $contact_type
   *
   * @return mixed
   */
  function old_wf_crm_get_matching_rules($contact_type) {
    static $rules;
    $contact_type = ucfirst($contact_type);
    if (!$rules) {
      $rules = array_fill_keys(array('Individual', 'Organization', 'Household'), array());
      $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_dedupe_rule_group');
      while ($dao->fetch()) {
        $rules[$dao->contact_type][$dao->id] = $dao->title;
      }
    }
    return $rules[$contact_type];
  }

  /**
   * test wf_crm_get_matching_rules compares the old results of the function
   * with the new results
   */
  public function test_CrmGetMatchingRules(){
    $types = ['Individual', 'Organization', 'Household'];
    foreach($types as $type) {
      $oldRules = $this->old_wf_crm_get_matching_rules($type);
      $newRules = wf_crm_get_matching_rules($type);
      $this->assertSame($oldRules, $newRules);
    }
  }

  /**
   * The old function, copied here to compare the test results
   * @param $reg_options
   * @param $context
   *
   * @return array
   */
  function local_wf_crm_get_events($reg_options, $context) {
    $ret = array();
    $format = wf_crm_aval($reg_options, 'title_display', 'title');
    $sql = "SELECT id, title, start_date, end_date, event_type_id FROM civicrm_event WHERE is_template = 0 AND is_active = 1";
    // 'now' means only current events, 1 means show all past events, other values are relative date strings
    $date_past = wf_crm_aval($reg_options, 'show_past_events', 'now');
    if ($date_past != '1') {
      $date_past = date('Y-m-d H:i:s', strtotime($date_past));
      $sql .= " AND (end_date >= '$date_past' OR end_date IS NULL)";
    }
    // 'now' means only past events, 1 means show all future events, other values are relative date strings
    $date_future = wf_crm_aval($reg_options, 'show_future_events', '1');
    if ($date_future != '1') {
      $date_future = date('Y-m-d H:i:s', strtotime($date_future));
      $sql .= " AND (end_date <= '$date_future' OR end_date IS NULL)";
    }
    $event_types = array_filter((array) $reg_options['event_type'], "is_numeric");
    if ($event_types) {
      $sql .= ' AND event_type_id IN ( ' . implode(", ", $event_types) . ' ) ';
    }
    if (is_numeric(wf_crm_aval($reg_options, 'show_public_events'))) {
      $sql .= ' AND is_public = ' . $reg_options['show_public_events'];
    }
    $sql .= ' ORDER BY start_date ' . ($context == 'config_form' ? 'DESC' : '');
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $ret[$dao->id . '-' . $dao->event_type_id] = $this->old_wf_crm_format_event($dao, $format);
    }
    return $ret;
  }

  /**
   *
   * The old function, copied here to compare the test results
   *
   * @param $event
   * @param $format
   *
   * @return string
   */
  function old_wf_crm_format_event($event, $format) {
    $format = explode(' ', $format);
    // Date format
    foreach ($format as $value) {
      if (strpos($value, 'dateformat') === 0) {
        $config = CRM_Core_Config::singleton();
        $date_format = $config->$value;
      }
    }
    $event = (object) $event;
    $title = array();
    if (in_array('title', $format)) {
      $title[] = $event->title;
    }
    if (in_array('type', $format)) {
      $types = wf_crm_apivalues('event', 'getoptions', array('field' => 'event_type_id', 'context' => 'get'));
      $title[] = $types[$event->event_type_id];
    }
    if (in_array('start', $format) && $event->start_date) {
      $title[] = CRM_Utils_Date::customFormat($event->start_date, $date_format);
    }
    if (in_array('end', $format) && $event->end_date) {
      // Avoid showing redundant end-date if it is the same as the start date
      $same_day = substr($event->start_date, 0, 10) == substr($event->end_date, 0, 10);
      if (!$same_day || in_array('dateformatDatetime', $format) || in_array('dateformatTime', $format)) {
        $end_format = (in_array('dateformatDatetime', $format) && $same_day) ? $config->dateformatTime : $date_format;
        $title[] = CRM_Utils_Date::customFormat($event->end_date, $end_format);
      }
    }
    return implode(' - ', $title);
  }

  /**
   * Does the heavy lifting in comparing old and new get_event results
   *
   * @param $reg_options
   * @param array $params
   */
  public function help_CRMGetEvents($reg_options, $params = []) {

    foreach($params as $key => $param){
      $reg_options[$key]=$param;
    }

    $contexts = ['config_form', 'no_config_form'];
    foreach ($contexts as $context) {
      $newResult = $this->local_wf_crm_get_events($reg_options, $context);
      $oldResult = wf_crm_get_events($reg_options, $context);
      $this->assertEquals($newResult, $oldResult, print_r($params, TRUE));
      $both = ['new'=>$newResult, 'old'=>$oldResult];
      $newFirst = array_shift($newResult);
      $oldFirst = array_shift($oldResult);
      $this->assertEquals($newFirst, $oldFirst,print_r($both, TRUE));
    }

  }

  /**
   * basically the test compares the results of the api only function with
   * old test results
   */
  public function test_CRMGetEvents(){
    $reg_options = [
      'event_type' => ['any' => 'any'],
      'show_past_events' => 'now',
      'show_future_events' => '1',
      'show_public_events' => 'all',
      'title_display' => 'title',
      'show_remaining' => '0',
      'validate' => 1,
      'block_form' => 0,
      'disable_unregister' => 0,
      'allow_url_load' => 0,
    ];

    $this->help_CRMGetEvents($reg_options);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'title start end dateformatTime']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'title type']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'start dateformatYear']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'start dateformatFull']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'start dateformatTime']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'start dateformatDatetime']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'start end dateformatFull']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'start end dateformatTime']);
    $this->help_CRMGetEvents($reg_options,['title_display' => 'start end dateformatDatetime']);
    $this->help_CRMGetEvents($reg_options,['event_type' => [2]]);
    $this->help_CRMGetEvents($reg_options,['show_public_events' => 1]);
    $this->help_CRMGetEvents($reg_options,['show_public_events' => 0]);
    $this->help_CRMGetEvents($reg_options,['show_future_events' => 'now']);
    $this->help_CRMGetEvents($reg_options,['show_future_events' => 1]);
    $this->help_CRMGetEvents($reg_options,['show_past_events' => 'now']);
    $this->help_CRMGetEvents($reg_options,['show_past_events' => 1]);
    $this->help_CRMGetEvents($reg_options,['show_past_events' => '2019-07-01']);

  }
}