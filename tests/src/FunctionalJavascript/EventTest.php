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

  private function createEvent() {
    $utils = \Drupal::service('webform_civicrm.utils');
    $ft = $utils->wf_civicrm_api('FinancialType', 'get', [
      'return' => ["id"],
      'name' => "Event Fee",
    ]);
    $event = $utils->wf_civicrm_api('Event', 'create', [
      'event_type_id' => "Conference",
      'title' => "Test Event" . substr(sha1(rand()), 0, 4),
      'start_date' => date('Y-m-d'),
      'financial_type_id' => $ft['id'],
    ]);
    $this->assertEquals(0, $event['is_error']);
    $this->assertEquals(1, $event['count']);
    return reset($event['values']);
  }

  function testSubmitEventParticipant() {
    $event = $this->createEvent();
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
    $this->getSession()->getPage()->clickLink('Contribution');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('You must enable an email field for Contact 1 in order to process transactions.');
    $this->getSession()->getPage()->pressButton('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');
    $this->getSession()->getPage()->selectFieldOption('Financial Type', 1);

    $this->getSession()->getPage()->selectFieldOption('Payment Processor', $payment_processor['id']);
    $this->enableBillingSection();

    $this->saveCiviCRMSettings();
    $this->assertSession()->assertWaitOnAjaxRequest();

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
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('participant', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(0, $api_result['is_error']);
    $this->assertEquals(1, $api_result['count']);
    $this->assertEquals($event['id'], $api_result['values'][0]['event_id']);
  }

}
