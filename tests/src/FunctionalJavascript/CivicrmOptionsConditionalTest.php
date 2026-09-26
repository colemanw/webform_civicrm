<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests conditional logic for CiviCRM Options elements.
 *
 * @group webform_civicrm
 */
final class CivicrmOptionsConditionalTest extends WebformCivicrmTestBase {

  private $events = [];

  protected function setUp(): void {
    parent::setUp();

    // Create test events.
    $event1 = $this->utils->wf_civicrm_api('Event', 'create', [
      'event_type_id' => "Conference",
      'title' => "Event A",
      'start_date' => date('Y-m-d', strtotime('+1 month')),
    ]);
    $this->assertEquals(0, $event1['is_error']);
    $this->events['event_a'] = reset($event1['values']);

    $event2 = $this->utils->wf_civicrm_api('Event', 'create', [
      'event_type_id' => "Meeting",
      'title' => "Event B",
      'start_date' => date('Y-m-d', strtotime('+2 months')),
    ]);
    $this->assertEquals(0, $event2['is_error']);
    $this->events['event_b'] = reset($event2['values']);

    $event3 = $this->utils->wf_civicrm_api('Event', 'create', [
      'event_type_id' => "Workshop",
      'title' => "Event C",
      'start_date' => date('Y-m-d', strtotime('+3 months')),
    ]);
    $this->assertEquals(0, $event3['is_error']);
    $this->events['event_c'] = reset($event3['values']);
  }

  /**
   * Test conditionals for checkboxes, radios, and select configurations.
   */
  public function testCivicrmOptionsConditionals() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Configure Event tab with user select.
    $this->getSession()->getPage()->clickLink('Event Registration');
    $this->getSession()->getPage()->selectFieldOption('participant_reg_type', 'all');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('participant_1_number_of_participant', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_participant_1_participant_event_id[]', '- User Select -');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->saveCiviCRMSettings();

    // Add test fields with conditionals.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->addTextField('checkbox_field', 'Checkbox Field');
    $this->addConditionalRule('checkbox_field', 'civicrm_1_participant_1_participant_event_id', $this->events['event_a']['id']);

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->addTextField('radio_field', 'Radio Field');
    $this->addConditionalRuleWholeField('radio_field', 'civicrm_1_participant_1_participant_event_id', 'filled');

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->addTextField('select_field', 'Select Field');
    $this->addConditionalRuleWholeField('select_field', 'civicrm_1_participant_1_participant_event_id', 'value', $this->events['event_c']['id']);

    // Test 1: Conditionals for Checkboxes.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->editCivicrmOptionElement('edit-webform-ui-elements-civicrm-1-participant-1-participant-event-id-operations', TRUE, FALSE, NULL, NULL, FALSE, FALSE);

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementNotExists('css', '[name="checkbox_field"]');

    $this->getSession()->getPage()->checkField('civicrm_1_participant_1_participant_event_id[' . $this->events['event_a']['id'] . ']');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementVisible('css', '[name="checkbox_field"]');
    $this->assertSession()->fieldExists('checkbox_field');

    $this->getSession()->getPage()->uncheckField('civicrm_1_participant_1_participant_event_id[' . $this->events['event_a']['id'] . ']');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementRemoved('css', '[name="checkbox_field"]');

    // Test 2: Radio Buttons.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->editCivicrmOptionElement('edit-webform-ui-elements-civicrm-1-participant-1-participant-event-id-operations', FALSE, FALSE, NULL, NULL, FALSE, FALSE);

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementNotExists('css', '[name="radio_field"]');

    $this->getSession()->getPage()->selectFieldOption('civicrm_1_participant_1_participant_event_id', $this->events['event_b']['id']);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementVisible('css', '[name="radio_field"]');
    $this->assertSession()->fieldExists('radio_field');

    // Test 3: Select field.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->editCivicrmOptionElement('edit-webform-ui-elements-civicrm-1-participant-1-participant-event-id-operations', FALSE, FALSE, NULL, NULL, FALSE, TRUE);

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementNotExists('css', '[name="select_field"]');

    $this->getSession()->getPage()->selectFieldOption('civicrm_1_participant_1_participant_event_id', $this->events['event_c']['id']);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementVisible('css', '[name="select_field"]');
    $this->assertSession()->fieldExists('select_field');

    $this->getSession()->getPage()->selectFieldOption('civicrm_1_participant_1_participant_event_id', $this->events['event_a']['id']);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementRemoved('css', '[name="select_field"]');
  }

  /**
   * Add a text field to the form.
   */
  private function addTextField($key, $label) {
    $this->getSession()->getPage()->clickLink('Add element');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->clickLink('Text field');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementVisible('css', '[name="properties[title]"]');
    $this->getSession()->getPage()->fillField('properties[title]', $label);
    $this->getSession()->getPage()->fillField('properties[key]', $key);
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Add per-option conditional (checkboxes).
   */
  private function addConditionalRule($target_field, $trigger_field, $trigger_value) {
    $this->clickLink('Edit', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->clickLink('Conditions');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('State', 'Visible');
    $this->getSession()->getPage()->pressButton('Add condition');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementVisible('css', '[name="states[visible][0][selector]"]');
    $this->getSession()->getPage()->selectFieldOption('states[visible][0][selector]', ':input[name="' . $trigger_field . '[' . $trigger_value . ']"]');
    $this->getSession()->getPage()->selectFieldOption('states[visible][0][trigger]', 'checked');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Add whole-field conditional (radios/select).
   */
  private function addConditionalRuleWholeField($target_field, $trigger_field, $trigger_type, $trigger_value = NULL) {
    $this->clickLink('Edit', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->clickLink('Conditions');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('State', 'Visible');
    $this->getSession()->getPage()->pressButton('Add condition');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementVisible('css', '[name="states[visible][0][selector]"]');
    $this->getSession()->getPage()->selectFieldOption('states[visible][0][selector]', ':input[name="' . $trigger_field . '"]');
    $this->getSession()->getPage()->selectFieldOption('states[visible][0][trigger]', $trigger_type);

    if ($trigger_value !== NULL) {
      $this->assertSession()->waitForElementVisible('css', '[name="states[visible][0][value]"]');
      $this->getSession()->getPage()->fillField('states[visible][0][value]', $trigger_value);
    }

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

}
