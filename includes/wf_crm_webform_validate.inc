<?php

/**
 * @file
 * Front-end form validation.
 */

module_load_include('inc', 'webform_civicrm', 'includes/wf_crm_webform_base');

class wf_crm_webform_validate extends wf_crm_webform_base {
  private $form;
  private $form_state;
  private $submitted;

  function __construct(&$form, &$form_state) {
    civicrm_initialize();
    $this->form = &$form;
    $this->form_state = &$form_state;
    $this->node = $form['#node'];
    $this->settings = $this->node->webform_civicrm;
    $this->data = $this->settings['data'];
  }
  
  public function validate() {
    $values = _webform_client_form_submit_flatten($this->node, wf_crm_aval($this->form_state, 'values:submitted'));
    $this->submitted = wf_crm_enabled_fields($this->node, $values);
    $this->validateValues($this->form['submitted']);
    if (!empty($this->node->webform_civicrm['data']['reg_options']['validate'])
      && !empty($this->node->webform_civicrm['data']['participant'])
      && !empty($this->node->webform_civicrm['data']['participant_reg_type']))
    {
      // We need data from all pages to validate events
      if (!empty($this->form_state['storage']['submitted'])
        && wf_crm_aval($this->form_state, 'storage:page_num', 1) > 1)
      {
        $values += $this->form_state['storage']['submitted'];
        $this->submitted = wf_crm_enabled_fields($this->node, $values);
      }
      $this->validateParticipants();
    }
  }

  /**
   * Recursive validation callback for webform submissions.
   *
   * @param array $elements
   *   FAPI form array
   */
  private function validateValues($elements) {
    // Recurse through form elements.
    foreach (element_children($elements) as $key) {
      if (is_array($elements[$key]) && ($element = $elements[$key])) {
        $this->validateValues($elements[$key]);
        if (!empty($element['#civicrm_data_type'])
          && substr(wf_crm_aval($element, '#type', ''), 0, 4) === 'text'
          && $element['#value'] !== NULL
          && $element['#value'] !== '') {
          $dt = $element['#civicrm_data_type'];
          // Validate state/prov abbreviation
          if ($dt == 'state_province_abbr') {
            $ckey = str_replace('state_province', 'country', $key);
            if (!empty($this->submitted[$ckey]) && is_numeric($this->submitted[$ckey])) {
              $country_id = $this->submitted[$ckey];
            }
            else {
              $config = CRM_Core_Config::singleton();
              $country_id = $config->defaultContactCountry;
            }
            $states = wf_crm_get_states($country_id);
            if ($states && !array_key_exists(strtoupper($element['#value']), $states)) {
              $countries = wf_crm_apivalues('address', 'getoptions', array('field' => 'country_id'));
              form_error($element, t('Mismatch: "@state" is not a state/province of %country. Please enter a valid state/province abbreviation for %field.', array('@state' => $element['#value'], '%country' => $countries[$country_id], '%field' => $element['#title'])));
            }
          }
          // Strings and files don't need any validation
          elseif ($dt !== 'String' && $dt !== 'Memo' && $dt !== 'File'
            && CRM_Utils_Type::escape($element['#value'], $dt, FALSE) === NULL) {
            // Allow data type names to be translated
            switch ($dt) {
              case 'Int':
                $dt = t('an integer');
                break;
              case 'Float':
                $dt = t('a number');
                break;
              case 'Link':
                $dt = t('a web address starting with http://');
                break;
              case 'Money':
                $dt = t('a currency value');
                break;
            }
            form_error($element, t('Please enter @type for %field.', array('@type' => $dt, '%field' => $element['#title'])));
          }
        }
      }
    }
  }

  /**
   * Validation callback for event registrations.
   */
  private function validateParticipants() {
    $contacts = $cids = array();
    // Check how many valid contacts we have
    foreach ($this->data['contact'] as $c => $contact) {
      // Check if we have a contact_id
      if (is_numeric(wf_crm_aval($this->submitted, "civicrm_{$c}_contact_1_contact_existing"))) {
        $cids[] = $contacts[$c] = $this->submitted["civicrm_{$c}_contact_1_contact_existing"];
      }
      // Or else see if enough info was entered to create a contact
      elseif (wf_crm_name_field_exists($this->submitted, $c, $contact['contact'][1]['contact_type'])) {
        $contacts[$c] = 0;
      }
    }
    $events = array();
    $add = $this->data['participant_reg_type'] == 'all' ? count($contacts) : 1;
    // Fetch events set in back-end
    foreach ($this->data['participant'] as $c => $par) {
      if (!empty($par['participant']) && isset($contacts[$c])) {
        foreach ($par['participant'] as $p) {
          if (!empty($p['event_id']) && $p['event_id'] != 'create_civicrm_webform_element') {
            list($eid) = explode('-', $p['event_id']);
            if (is_numeric($eid)) {
              $events[$eid]['ended'] = TRUE;
              $events[$eid]['title'] = t('this event');
              $events[$eid]['count'] = wf_crm_aval($events, "$eid:count", 0) + $add;
            }
          }
        }
      }
    }
    // Add events selected by user
    foreach ($this->submitted as $field => $value) {
      if (strpos($field, 'participant_event_id')) {
        list(, $c) = wf_crm_explode_key($field);
        if (isset($contacts[$c]) || ($c == 1 && $this->data['participant_reg_type'] == 'all')) {
          $value = is_array($value) ? $value : array($value);
          foreach ($value as $val) {
            $eid = explode('-', $val);
            if (count($eid) == 2 && is_numeric($eid[0]) && $eid[0]) {
              $eid = $eid[0];
              $events[$eid]['ended'] = TRUE;
              $events[$eid]['title'] = t('this event');
              $events[$eid]['count'] = wf_crm_aval($events, "$eid:count", 0) + $add;
            }
          }
        }
      }
    }
    // Subtract events already registered for - this only works with known contacts
    if ($events && $cids) {
      $dao = CRM_Core_DAO::executeQuery("SELECT event_id
      FROM civicrm_participant p, civicrm_participant_status_type s
      WHERE s.id = p.status_id AND s.is_counted = 1
      AND event_id IN (" . implode(',', array_keys($events)) . ")
      AND contact_id IN (" . implode(',', $cids) . ")
      AND is_test = 0");
      while ($dao->fetch()) {
        if (isset($events[$dao->event_id])) {
          if ((--$events[$dao->event_id]['count']) === 0) {
            unset($events[$dao->event_id]);
          }
        }
      }
      $dao->free();
    }
    $this->loadEvents($events);
    foreach ($events as $eid => $event) {
      if ($event['ended']) {
        form_set_error($eid, t('Sorry, you can no longer register for %event.', array('%event' => $event['title'])));
      }
      elseif ($event['max_participants'] && $event['count'] > $event['remaining']) {
        if (!empty($event['full'])) {
          form_set_error($eid, '<em>' . $event['title'] . '</em>: ' . $event['full_message']);
        }
        else {
          form_set_error($eid, format_plural($event['remaining'],
            'Sorry, you tried to register !count people for %event but there is only 1 space remaining.',
            'Sorry, you tried to register !count people for %event but there are only @count spaces remaining.',
            array('%event' => $event['title'], '!count' => $event['count'])));
        }
      }
    }
  }

}
