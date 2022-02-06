<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with CiviCRM: Contact with Event.
 *
 * @group webform_civicrm
 */
final class EventTest extends WebformCivicrmTestBase {

  protected function setup() {
    parent::setUp();
    $ft = $this->utils->wf_civicrm_api('FinancialType', 'get', [
      'return' => ["id"],
      'name' => "Event Fee",
    ]);
    $event = $this->utils->wf_civicrm_api('Event', 'create', [
      'event_type_id' => "Conference",
      'title' => "Test Event",
      'start_date' => date('Y-m-d'),
      'financial_type_id' => $ft['id'],
    ]);
    $this->assertEquals(0, $event['is_error']);
    $this->assertEquals(1, $event['count']);
    $this->_event = reset($event['values']);
  }

  protected function createCustomFields() {
    $this->cg = $this->createCustomGroup([
      'title' => 'Participant CG',
      'extends' => 'Participant',
    ]);

    //Add text custom field.
    $params = [
      'custom_group_id' => $this->cg['id'],
      'label' => 'Text',
      'name' => 'text',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
    ];
    $result = $this->utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['text'] = $result['id'];

    //Add contact reference field.
    $params = [
      'custom_group_id' => $this->cg['id'],
      'label' => 'Participant Contact Ref',
      'name' => 'participant_contact_ref',
      'data_type' => 'ContactReference',
      'html_type' => 'Autocomplete-Select',
      'is_active' => 1,
    ];
    $this->_customFields['participant_contact_ref'] = $this->utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $this->_customFields['participant_contact_ref']['is_error']);
    $this->assertEquals(1, $this->_customFields['participant_contact_ref']['count']);
  }

  /**
   * Test contact reference field for participants.
   */
  function testParticipantContactReference() {
    $this->createCustomFields();

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->selectFieldOption('Number of Contacts', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();

    //Configure Event tab.
    $this->getSession()->getPage()->clickLink('Event Registration');
    $this->getSession()->getPage()->selectFieldOption('participant_reg_type', 'all');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('participant_1_number_of_participant', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_participant_1_participant_event_id[]', 'Test Event');
    $this->getSession()->getPage()->checkField('Participant Fee');
    $this->getSession()->getPage()->checkField('Text');
    $this->getSession()->getPage()->selectFieldOption('Participant Contact Ref', '- User Select -');

    $this->configureContributionTab();
    $this->getSession()->getPage()->selectFieldOption('Payment Processor', 'Pay Later');

    $this->saveCiviCRMSettings();
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();

    $this->assertPageNoErrorMessages();
    $params = [
      'civicrm_1_contact_1_contact_first_name' => 'Frederick',
      'civicrm_1_contact_1_contact_last_name' => 'Pabst',
      'civicrm_1_contact_1_email_email' => 'fred@example.com',
      'civicrm_2_contact_1_contact_first_name' => 'Mark',
      'civicrm_2_contact_1_contact_last_name' => 'Smith',
      'Participant Fee' => 20,
      'Text' => 'Foo',
    ];
    foreach ($params as $key => $val) {
      $this->getSession()->getPage()->fillField($key, $val);
    }
    $refName = 'civicrm_1_participant_1_cg' . $this->cg['id'] . '_custom_' . $this->_customFields['participant_contact_ref']['id'];
    $this->getSession()->getPage()->selectFieldOption($refName, 2);
    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '40.00');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $contactRef = $this->utils->wf_civicrm_api('Contact', 'get', [
      'first_name' => 'Mark',
      'last_name' => 'Smith',
    ]);
    $participant = current($this->utils->wf_civicrm_api('Participant', 'get', [
      'contact_id' => $this->rootUserCid
    ])['values']);
    $customKey = 'custom_' . $this->_customFields['participant_contact_ref']['id'];
    $this->assertEquals('Smith, Mark', $participant[$customKey]);
    $this->assertEquals($contactRef['id'], $participant["{$customKey}_id"]);
  }

  /**
   * Waitlist Event Participant submission.
   */
  function testWaitlistParticipant() {
    // Enable waitlist on the event with max participant = 2.
    $this->utils->wf_civicrm_api('Event', 'create', [
      'id' => $this->_event['id'],
      'max_participants' => 2,
      'has_waitlist' => 1,
      'waitlist_text' => 'This event is currently full. However you can register now and get added to a waiting list. You will be notified if spaces become available.',
    ]);

    // Create a webform with 3 contacts.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 3);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Configure Event tab.
    $this->getSession()->getPage()->clickLink('Event Registration');
    $this->getSession()->getPage()->selectFieldOption('participant_reg_type', 'all');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('reg_options[show_remaining]', 'always');
    $this->getSession()->getPage()->selectFieldOption('participant_1_number_of_participant', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_participant_1_participant_event_id[]', 'Test Event');

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $edit = [
      'civicrm_1_contact_1_contact_first_name' => 'Frederick',
      'civicrm_1_contact_1_contact_last_name' => 'Pabst',
      'civicrm_2_contact_1_contact_first_name' => 'Mark',
      'civicrm_2_contact_1_contact_last_name' => 'Anthony',
      'civicrm_3_contact_1_contact_first_name' => 'John',
      'civicrm_3_contact_1_contact_last_name' => 'Doe',
    ];
    $this->postSubmission($this->webform, $edit);

    $api_result = $this->utils->wf_civicrm_api('participant', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(0, $api_result['is_error']);
    $this->assertEquals(3, $api_result['count']);
    $participants = $api_result['values'];
    $this->assertEquals($this->_event['id'], $participants[0]['event_id']);

    // Verify 2 participants are registered and 3rd one is added to waitlist.
    $this->assertEquals('Registered', $participants[0]['participant_status']);
    $this->assertEquals('Registered', $participants[1]['participant_status']);
    $this->assertEquals('On waitlist', $participants[2]['participant_status']);

    // Visit the webform again and confirm if waitlist text is displayed.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('This event is currently full. However you can register now and get added to a waiting list. You will be notified if spaces become available.');
  }

  /**
   * Event Participant submission.
   */
  function testSubmitEventParticipant() {
    $payment_processor = $this->createPaymentProcessor();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->clickLink('Event Registration');

    //Configure Event tab.
    $this->getSession()->getPage()->selectFieldOption('participant_reg_type', 'all');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('participant_1_number_of_participant', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_participant_1_participant_event_id[]', 'Test Event');
    $this->getSession()->getPage()->checkField('Participant Fee');

    //Configure Contribution tab.
    $this->configureContributionTab();

    $this->getSession()->getPage()->selectFieldOption('Payment Processor', $payment_processor['id']);
    $this->enableBillingSection();

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
      'Email' => 'fred@example.com',
      'Participant Fee' => 20,
    ];
    $this->postSubmission($this->webform, $edit, 'Next >');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '20.00');

    // Wait for the credit card form to load in.
    $this->assertSession()->waitForField('credit_card_number');
    $this->getSession()->getPage()->fillField('Card Number', '4222222222222220');
    $this->getSession()->getPage()->fillField('Security Code', '123');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[M]', '11');
    $this_year = date('Y');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[Y]', $this_year + 1);
    $billingValues = [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
      'street_address' => '123 Milwaukee Ave',
      'city' => 'Milwaukee',
      'country' => '1228',
      'state_province' => '1048',
      'postal_code' => '53177',
    ];
    $this->fillBillingFields($billingValues);
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    //Assert if recur is attached to the created membership.
    $api_result = $this->utils->wf_civicrm_api('participant', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(0, $api_result['is_error']);
    $this->assertEquals(1, $api_result['count']);
    $this->assertEquals($this->_event['id'], $api_result['values'][0]['event_id']);
  }

}
