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

  function testSubmitMembershipAutoRenew() {
    $this->createMembershipType(1, TRUE);
    $payment_processor = $this->createPaymentProcessor();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->clickLink('Memberships');

    // Configure Membership tab.
    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', '- User Select -');
    $this->htmlOutput();
    // $this->createScreenshot($this->htmlOutputDirectory . '/membership_page_settings.png');

    // Configure Contribution tab and enable recurring.
    $params = [
      'payment_processor_id' => $payment_processor['id'],
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->selectFieldOption('Frequency of Installments', 'year');

    $this->enableBillingSection();

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', '1');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertSession()->waitForField('wf-crm-billing-items');
    $this->htmlOutput();

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '1.00');
    $this->assertSession()->elementTextContains('css', '.civicrm_1_membership_1', 'Basic: Frederick Pabst');

    // Wait for the credit card form to load in.
    $this->assertSession()->waitForField('credit_card_number');
    $this->getSession()->getPage()->fillField('Card Number', '4222222222222220');
    $this->getSession()->getPage()->fillField('Security Code', '123');
    $this->getSession()->getPage()->selectFieldOption($this->getCreditCardMonthFieldName(), '11');
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
    // ToDo -> comment back in after removing support for 5.35.*
    // -> 1) Drupal\Tests\webform_civicrm\FunctionalJavascript\MembershipSubmissionTest::testSubmitMembershipAutoRenew
    // Error message Notice: Undefined index: line_item in CRM_Contribute_BAO_Contribution::checkTaxAmount()
    // involves both Sales Tax + Recurring
    // $this->assertPageNoErrorMessages();

    // Assert if recur is attached to the created membership.
    $api_result = $this->utils->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
      'return' => 'contribution_recur_id',
    ]);
    $membership = reset($api_result['values']);
    $this->assertNotEmpty($membership['contribution_recur_id']);

    // Let's make sure we have a Contribution by ensuring we have a Transaction ID
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
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
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->clickLink('Memberships');

    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->saveCiviCRMSettings();

    $adminUserCid = $this->getUFMatchRecord($this->adminUser->id())['contact_id'];
    // Create two memberships with the same status with the first membership
    // having an end date after the second membership's end date.
    $this->utils->wf_civicrm_api('membership', 'create', [
      'membership_type_id' => 'Basic',
      'contact_id' => $adminUserCid,
      'join_date' => '08/10/21',
      'start_date' => '08/10/21',
      'end_date' => '08/10/22',
      'is_override' => 1,
      'status_id' => 'Expired',
    ]);

    $this->utils->wf_civicrm_api('membership', 'create', [
      'membership_type_id' => 'Basic',
      'contact_id' => $adminUserCid,
      'join_date' => '01/01/21',
      'start_date' => '01/01/21',
      'end_date' => '01/01/22',
      'is_override' => 1,
      'status_id' => 'Expired',
    ]);

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->elementExists('xpath', $this->assertSession()->buildXPathQuery('//div[@data-drupal-messages]//div[contains(., :message)]', [
      ':message' => 'Basic membership for ' . mb_strtolower($this->adminUser->getEmail()) . ' has a status of "Expired". Expired ' . \CRM_Utils_Date::customFormat('2022-08-10'),
    ]));

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');

    $this->getSession()->getPage()->pressButton('Submit');

    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = $this->utils->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
      'options' => ['sort' => 'id DESC'],
    ]);
    $this->assertEquals(3, $api_result['count']);
    $membership = reset($api_result['values']);

    $this->assertEquals('Basic', $membership['membership_name']);
    $this->assertEquals('1', $membership['status_id']);

    $today = date('Y-m-d');

    $this->assertEquals($today,  $membership['join_date']);
    $this->assertEquals($today,  $membership['start_date']);

    $this->assertEquals(date('Y-m-d', strtotime('+1 year -1 day')), $membership['end_date']);
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
    $this->assertSession()->assertWaitOnAjaxRequest();
    
    $this->getSession()->getPage()->pressButton('Save elements');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['membership' => 2]]));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->assertSession()->pageTextContains('Basic Plus');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = $this->utils->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $membership = reset($api_result['values']);

    $this->assertEquals('Basic Plus', $membership['membership_name']);
    $this->assertEquals('1', $membership['status_id']);
  }

  /**
   * Test submitting Membership with different Financial Types
   * (5% and 13% Sales Tax - based on province)
   */
  public function testMembershipVaryingSalesTax() {
    // Add Canada
    \Civi::settings()->set('countryLimit', [1228, 1039]);
    // Make it the default country
    \Civi::settings()->set('defaultContactCountry', 1039);

    // Create Financial Type and Attach Sales Tax to it - for Alberta GST = 5%
    $this->createFinancialType('Member5');
    $this->setupSalesTax(5, $accountParams = [], 5);
    // Create Financial Type and Attach Sales Tax to it - for Ontario HST = 13%
    $this->createFinancialType('Member13');
    $this->setupSalesTax(6, $accountParams = [], 13);

    $this->createMembershipType(100, FALSE, 'Basic', 'Member Dues');

    $payment_processor = $this->createPaymentProcessor();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    // Enable Address fields.
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_address', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Configure Contribution tab.
    $params = [
      'payment_processor_id' => $payment_processor['id'],
      'financial_type_id' => 2,
    ];
    $this->configureContributionTab($params);

    // Configure Membership tab.
    $this->getSession()->getPage()->clickLink('Memberships');
    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', 'Basic');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_financial_type_id', '- User Select -');
    // $this->getSession()->getPage()->checkField('Membership Fee');

    $this->htmlOutput();

    $this->saveCiviCRMSettings();

    $this->drupalLogout();

    $this->purchaseMembershipProvince('Alberta');
    $this->purchaseMembershipProvince('Ontario');
  }

  public function purchaseMembershipProvince($province) {
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    if ($province == 'Alberta') {
      $province_id = 'AB';
      $financial_type_id = 5;
      $expected_total_amount = 105;
      $expected_tax_amount = 5;
    }
    elseif ($province == 'Ontario') {
      $province_id = 'ON';
      $financial_type_id = 6;
      $expected_total_amount = 113;
      $expected_tax_amount = 13;
    }

    $provinceId = $this->utils->wf_civicrm_api('StateProvince', 'getvalue', [
      'return' => "id",
      'name' => "Alberta",
    ]);
    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', $province . '_first');
    $this->getSession()->getPage()->fillField('Last Name', $province . '_last');
    $this->getSession()->getPage()->fillField('Street Address', '39 Street');
    $this->getSession()->getPage()->fillField('City', 'City');
    $this->getSession()->getPage()->fillField('Postal Code', 'A0B 1C2');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_address_state_province_id', $provinceId);
    $this->getSession()->getPage()->fillField('Email', $province . '@example.com');

    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_financial_type_id', $financial_type_id);

    // KG - this is where I want my screenshots
    // $this->htmlOutputDirectory = '/Applications/MAMP/htdocs/d9civicrm.local/web/sites/default/files/simpletest/';
    // $this->createScreenshot($this->htmlOutputDirectory . 'KG.png');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertSession()->waitForField('wf-crm-billing-items');
    $this->htmlOutput();

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->assertSession()->elementTextContains('css', '.civicrm_1_membership_1', "Basic: {$province}_first {$province}_last");

    $this->fillCardAndSubmit();

    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Ok let's check the results
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $addresses = $this->utils->wf_crm_apivalues('address', 'get', [
      'sequential' => 1,
    ]);
    foreach ($addresses as $address) {
      if ($address['location_type_id'] == 5) {
        $this->assertEquals(1048, $address['state_province_id']);
      }
    }

    if ($province == 'Alberta') {
      $this->assertEquals(1, $api_result['count']);
      $contribution = reset($api_result['values']);
    }
    elseif ($province == 'Ontario') {
      $this->assertEquals(2, $api_result['count']);
      $contribution = next($api_result['values']);
    }

    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Member Dues', $contribution['financial_type']);
    $this->assertEquals($expected_total_amount, $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);

    // Also retrieve tax_amount (have to ask for it to be returned)
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
      'return' => 'tax_amount',
    ]);

    if ($province == 'Alberta') {
      $this->assertEquals(1, $api_result['count']);
      $contribution = reset($api_result['values']);
    }
    elseif ($province == 'Ontario') {
      $this->assertEquals(2, $api_result['count']);
      $contribution = next($api_result['values']);
    }

    $this->assertEquals($expected_tax_amount, $contribution['tax_amount']);
    $tax_total_amount = $contribution['tax_amount'];

    $api_result = $this->utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);

    if ($province == 'Alberta') {
      $this->assertEquals(1, $api_result['count']);
      $line_items = reset($api_result['values']);
    }
    elseif ($province == 'Ontario') {
      $this->assertEquals(2, $api_result['count']);
      $line_items = next($api_result['values']);
    }
    $priceFieldID = $this->utils->wf_civicrm_api('PriceField', 'get', [
      'sequential' => 1,
      'price_set_id' => 'default_membership_type_amount',
      'options' => ['limit' => 1],
    ])['id'] ?? NULL;

    $this->assertEquals('1.00', $line_items['qty']);
    $this->assertEquals('100.00', $line_items['unit_price']);
    $this->assertEquals('100.00', $line_items['line_total']);
    $this->assertEquals($priceFieldID, $line_items['price_field_id']);
    $this->assertEquals($expected_tax_amount, $line_items['tax_amount']);
    $this->assertEquals($financial_type_id, $line_items['financial_type_id']);

    // ToDo -> this was assigned by CiviCRM Core 'price_field_id' => '2',
  }
}
