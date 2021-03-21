<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with CiviCRM: Contact with Membership (Free)
 *
 * @group webform_civicrm
 */
final class MembershipSubmissionTest extends WebformCivicrmTestBase {

  private function createMembershipType($amount = 0, $autoRenew = FALSE, $name = 'Basic') {
    $result = civicrm_api3('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id' => "Member Dues",
      'duration_unit' => "year",
      'duration_interval' => 1,
      'period_type' => "rolling",
      'minimum_fee' => $amount,
      'name' => $name,
      'auto_renew' => $autoRenew,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  function testSubmitMembershipAutoRenew() {
    $this->createMembershipType(1, TRUE);
    $payment_processor = $this->createPaymentProcessor();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->clickLink('Memberships');

    // Configure Membership tab.
    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', '- User Select -');
    $this->htmlOutput();
    // $this->createScreenshot($this->htmlOutputDirectory . '/membership_page_settings.png');

    // Configure Contribution tab and enable recurring.
    $this->getSession()->getPage()->clickLink('Contribution');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('You must enable an email field for Contact 1 in order to process transactions.');
    $this->getSession()->getPage()->pressButton('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');
    $this->getSession()->getPage()->selectFieldOption('Financial Type', 1);
    $this->getSession()->getPage()->selectFieldOption('Frequency of Installments', 'year');

    $this->getSession()->getPage()->selectFieldOption('Payment Processor', $payment_processor['id']);
    // $this->createScreenshot($this->htmlOutputDirectory . '/membership_page_settings_before_save.png');

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    // Setup contact information wizard page.
    $this->configureContactInformationWizardPage();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    // $this->createScreenshot($this->htmlOutputDirectory . '/membership_page1.png');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', '1');

    $this->getSession()->getPage()->pressButton('Next >');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '1.00');

    // Wait for the credit card form to load in.
    $this->assertSession()->waitForField('credit_card_number');
    $this->getSession()->getPage()->fillField('Card Number', '4222222222222220');
    $this->getSession()->getPage()->fillField('Security Code', '123');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[M]', '11');
    $this_year = date('Y');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[Y]', $this_year + 1);
    $this->getSession()->getPage()->fillField('Billing First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Billing Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Street Address', '123 Milwaukee Ave');
    $this->getSession()->getPage()->fillField('City', 'Milwaukee');
    // $this->createScreenshot($this->htmlOutputDirectory . '/membership_page2.png');

    // Select2 is being difficult; unhide the country and state/province select.
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->executeScript("document.getElementById('billing_country_id-5').style.display = 'block';");
    $driver->executeScript("document.getElementById('billing_state_province_id-5').style.display = 'block';");

    $this->getSession()->getPage()->fillField('billing_country_id-5', '1228');
    // Wait for select2's AJAX request.
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->wait(1000, 'document.getElementById("billing_state_province_id-5").options.length > 1');
    $this->getSession()->getPage()->fillField('billing_state_province_id-5', '1048');

    $this->getSession()->getPage()->fillField('Postal Code', '53177');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Assert if recur is attached to the created membership.
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
      'return' => 'contribution_recur_id',
    ]);
    $membership = reset($api_result['values']);
    $this->assertNotEmpty($membership['contribution_recur_id']);

    // Let's make sure we have a Contribution by ensuring we have a Transaction ID
    $api_result = $utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals('1.00', $contribution['total_amount']);
  }

  /**
   * Test submitting a Free Membership
   */
  public function testSubmitWebform() {
    $this->createMembershipType();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->clickLink('Memberships');

    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');

    $this->getSession()->getPage()->pressButton('Submit');

    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $membership = reset($api_result['values']);

    $this->assertEquals('Basic', $membership['membership_name']);
    $this->assertEquals('1', $membership['status_id']);

    $today = date('Y-m-d');
    // throw new \Exception(var_export($today, TRUE));

    $this->assertEquals($today,  $membership['join_date']);
    $this->assertEquals($today,  $membership['start_date']);

    $this->assertEquals(date('Y-m-d', strtotime($today. ' +364 days')),  $membership['end_date']);
  }

  /**
   * Test submitting a Membership using query params
   */
  public function testSubmitMembershipQueryParams() {
    $this->createMembershipType(1, TRUE, 'Basic');
    $this->createMembershipType(1, TRUE, 'Basic Plus');

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Configure Membership tab.
    $this->getSession()->getPage()->clickLink('Memberships');
    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', '- User Select -');
    $this->htmlOutput();

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->waitForField('CiviCRM Options');

    // Add the Default -> [current-page:query:membership]
    $membershipElementEdit = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-membership-1-membership-membership-type-id-operations"] a.webform-ajax-link');
    $membershipElementEdit->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('properties[extra][aslist]');
    $this->assertSession()->checkboxChecked('properties[extra][aslist]');

    $this->htmlOutput();

    $this->getSession()->getPage()->clickLink('Advanced');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $fieldset = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-default"]');
    $fieldset->click();
    $this->getSession()->getPage()->fillField('Default value', '[current-page:query:membership]');
    $this->getSession()->getPage()->pressButton('Save');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['membership' => 2]]));
    $this->htmlOutput();
    // ToDo ->
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->assertSession()->pageTextContains('Basic Plus');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();
    // ToDo ->
    $this->assertPageNoErrorMessages();

    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $membership = reset($api_result['values']);

    $this->assertEquals('Basic Plus', $membership['membership_name']);
    $this->assertEquals('1', $membership['status_id']);
  }

}
