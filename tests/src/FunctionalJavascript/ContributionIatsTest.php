<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with CiviCRM: Contribution with Line Items and Sales Tax
 *
 * @group webform_civicrm
 */
final class ContributionIatsTest extends WebformCivicrmTestBase {

  /**
   * @var array
   */
  private $payment_processor_legacy;

  /**
   * @var array
   */
  private $payment_processor_legacy_acheft;

  /**
   * @var array
   * @todo the test that uses this is commented out. Is it still needed?
   */
  private $payment_processor_faps;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setUpExtension('com.iatspayments.civicrm');

    // Legacy CC
    $params = [
      'domain_id' => 1,
      'name' => 'iATS Credit Card - TE4188',
      'payment_processor_type_id' => 'iATS Payments Credit Card',
      'financial_account_id' => 12,
      'is_test' => FALSE,
      'is_active' => 1,
      'user_name' => 'TE4188',
      'password' => 'abcde01',
      'url_site' => 'https://www.iatspayments.com/NetGate/ProcessLinkv2.asmx?WSDL',
      'url_recur' => 'https://www.iatspayments.com/NetGate/ProcessLinkv2.asmx?WSDL',
      'class_name' => 'Payment_iATSService',
      'is_recur' => 1,
      'sequential' => 1,
      'payment_type' => 1,
      'payment_instrument_id' => 'Credit Card',
    ];
    $result = $this->utils->wf_civicrm_api('payment_processor', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->payment_processor_legacy = current($result['values']);

    // Legacy ACH/EFT
    $params = [
      'domain_id' => 1,
      'name' => 'iATS ACHEFT - TE4188',
      'payment_processor_type_id' => 'iATS Payments ACH/EFT',
      'financial_account_id' => 12,
      'is_test' => FALSE,
      'is_active' => 1,
      'user_name' => 'TE4188',
      'password' => 'abcde01',
      'url_site' => 'https://www.iatspayments.com/NetGate/ProcessLinkv2.asmx?WSDL',
      'url_recur' => 'https://www.iatspayments.com/NetGate/ProcessLinkv2.asmx?WSDL',
      'class_name' => 'Payment_iATSServiceACHEFT',
      'is_recur' => 1,
      'sequential' => 1,
      'payment_type' => 1,
      'payment_instrument_id' => 'Debit Card',
    ];
    $result = $this->utils->wf_civicrm_api('payment_processor', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->payment_processor_legacy_acheft = current($result['values']);

    // 1st Pay
    $params = [
      'domain_id' => 1,
      'name' => 'iATS Credit Card - 098',
      'payment_processor_type_id' => 'iATS Payments 1stPay Credit Card',
      'financial_account_id' => 12,
      'is_test' => FALSE,
      'is_active' => 1,
      'user_name' => '300098',
      'password' => '216142',
      'signature' => '1b3b0c7b-38ba-4b5a-bc45-d06f952c6a42',
      'url_site' => 'https://secure.1stpaygateway.net/secure/RestGW/Gateway/Transaction/',
      'class_name' => 'Payment_Faps',
      'is_recur' => 1,
      'sequential' => 1,
      'payment_type' => 1,
      'payment_instrument_id' => 'Credit Card',
    ];
    $result = $this->utils->wf_civicrm_api('payment_processor', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->payment_processor_faps = current($result['values']);

    drupal_flush_all_caches();
  }

  /*public function testSubmit1stPayContribution() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->clickLink('Contribution');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('You must enable an email field for Contact 1 in order to process transactions.');
    $this->getSession()->getPage()->pressButton('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');
    $this->getSession()->getPage()->selectFieldOption('Financial Type', 'Donation');
    // throw new \Exception(var_export($this->getOptions('Payment Processor'), TRUE));
    $this->assertCount(5, $this->getOptions('Payment Processor'));
    $this->getSession()->getPage()->selectFieldOption('Payment Processor',  $this->payment_processor_faps['id']);
    $this->enableBillingSection();

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
      'Email' => 'fred@example.com',
    ];
    $this->postSubmission($this->webform, $edit, 'Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->filliATSCryptogram();
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
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->createScreenshot($this->htmlOutputDirectory . 'faps169.png');
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', '.webform-confirmation');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->assertPageNoErrorMessages();

    // ToDo: load the Contribution and check the values
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('10.00', $contribution['total_amount']);
    $contribution_total_amount = $contribution['total_amount'];
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);
  }*/

  /**
   * Fill values for the iATS Cryptogram.
   */
  private function filliATSCryptogram() {
    $this->htmlOutput();
    $expYear = date('y') + 1;
    // Wait for the credit card form to load in.

    $this->getSession()->wait(5000);

    $this->getSession()->switchToIFrame('firstpay-iframe');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSession()->waitForElementVisible('css', 'input[name="text-card-number"]');
    $this->getSession()->getPage()->fillField('text-card-number', '4222222222222220');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('text-cvv', '123');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('select-expiration-month', '11');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('select-expiration-year', $expYear);
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->wait(5000);

    $this->getSession()->switchToIFrame();
  }

  /**
   * Create a webform with contribution.
   */
  public function configureWebform() {
    $this->setupSalesTax(2);
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();
    $params = [
      'payment_processor_id' => $this->payment_processor_legacy['id'],
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $this->assertCount(5, $this->getOptions('Payment Processor'));

    $this->enableBillingSection();
    $this->getSession()->getPage()->selectFieldOption('lineitem_1_number_of_lineitem', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_1_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_1_contribution_line_total");
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_2_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_2_contribution_line_total");
    // Set the Financial Type for the second line item to Member Dues (which has Sales Tax on it).
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_lineitem_2_contribution_financial_type_id', 2);

    $this->saveCiviCRMSettings();
  }

  /**
   * Submit the form using iATS card details.
   */
  public function submitWebForm() {
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->fillField('Line Item Amount', '1.75');
    $this->getSession()->getPage()->fillField('Line Item Amount 2', '5.00');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertSession()->waitForField('Contribution Amount');
    $this->getSession()->getPage()->fillField('Contribution Amount', '3.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');

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
    // throw new \Exception(var_export($this->htmlOutputDirectory, TRUE));
    $this->createScreenshot($this->htmlOutputDirectory . '/legacy289.png');
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();
    $this->assertSession()->waitForText('New submission added to CiviCRM Webform Test.');
  }

  /**
   * Verify payment values in CiviCRM.
   */
  public function verifyResults() {
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertStringContainsString(':', $contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('10.00', $contribution['total_amount']);
    $contribution_total_amount = $contribution['total_amount'];
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    // Also retrieve tax_amount (have to ask for it to be returned):
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
      'return' => ['tax_amount', 'payment_instrument_id'],
    ]);
    $contribution = reset($api_result['values']);
    $creditCardID = $this->utils->wf_civicrm_api('OptionValue', 'getvalue', [
      'return' => "value",
      'label' => "Credit Card",
      'option_group_id' => "payment_instrument",
    ]);
    $this->assertEquals('0.25', $contribution['tax_amount']);
    $this->assertEquals($creditCardID, $contribution['payment_instrument_id']);
    $tax_total_amount = $contribution['tax_amount'];

    $contriPriceFieldID = $this->utils->wf_civicrm_api('PriceField', 'get', [
      'sequential' => 1,
      'price_set_id' => 'default_contribution_amount',
      'options' => ['limit' => 1],
    ])['id'] ?? NULL;

    $api_result = $this->utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals('3.00', $api_result['values'][0]['line_total']);
    $this->assertEquals('1', $api_result['values'][0]['financial_type_id']);
    $this->assertEquals($contriPriceFieldID, $api_result['values'][0]['price_field_id']);

    $this->assertEquals('1.75', $api_result['values'][1]['line_total']);
    $this->assertEquals('1', $api_result['values'][1]['financial_type_id']);
    $this->assertEquals($contriPriceFieldID, $api_result['values'][1]['price_field_id']);

    $this->assertEquals('5.00', $api_result['values'][2]['line_total']);
    $this->assertEquals('0.25', $api_result['values'][2]['tax_amount']);
    $this->assertEquals('2', $api_result['values'][2]['financial_type_id']);
    $this->assertEquals($contriPriceFieldID, $api_result['values'][2]['price_field_id']);

    $sum_line_total = $api_result['values'][0]['line_total'] + $api_result['values'][1]['line_total'] + $api_result['values'][2]['line_total'];
    $sum_tax_amount = $api_result['values'][2]['tax_amount'];
    $this->assertEquals($tax_total_amount, $sum_tax_amount);
    $this->assertEquals($contribution_total_amount, $sum_line_total + $sum_tax_amount);
  }

  /**
   * Test Payment using iATS processor.
   */
  public function testSubmitContribution() {
    $this->configureWebform();
    $this->submitWebForm();
    $this->verifyResults();
  }

  /**
   * Test Payment on AJAX webform.
   */
  public function testSubmitContributionAjaxEnabled() {
    $this->configureWebform();
    // Enable AJAX on the form.
    $this->drupalGet($this->webform->toUrl('settings'));
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField('Use Ajax');
    $this->getSession()->getPage()->pressButton('Save');

    $this->submitWebForm();
    $this->verifyResults();
  }

  public function testSubmitACHEFTContribution() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->clickLink('Contribution');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('You must enable an email field for Contact 1 in order to process transactions.');
    $this->getSession()->getPage()->pressButton('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');
    $this->getSession()->getPage()->selectFieldOption('Financial Type', 1);

    $this->assertCount(5, $this->getOptions('Payment Processor'));
    $this->getSession()->getPage()->selectFieldOption('Payment Processor',  $this->payment_processor_legacy_acheft['id']);

    $this->enableBillingSection();

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '99.00');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '99.00');

    // Wait for the ACHEFT form to load in.
    $this->assertSession()->waitForField('account_holder');
    $this->getSession()->getPage()->fillField('Account Holder', 'CiviCRM user');
    $this->getSession()->getPage()->fillField('Account No.', '12345678');
    $this->getSession()->getPage()->fillField('Bank Identification Number', '111111111');
    $this->getSession()->getPage()->fillField('Bank Name', 'Bank of CiviCRM');
    $this->getSession()->getPage()->selectFieldOption('bank_account_type', 'Savings');
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

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('99.00', $contribution['total_amount']);
    $this->assertEquals('Pending', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

  }

  public function testSubmitRecurringContribution() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->clickLink('Contribution');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('You must enable an email field for Contact 1 in order to process transactions.');
    $this->getSession()->getPage()->pressButton('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');
    $this->getSession()->getPage()->selectFieldOption('Financial Type', 1);

    $this->assertCount(5, $this->getOptions('Payment Processor'));
    $this->getSession()->getPage()->selectFieldOption('Payment Processor',  $this->payment_processor_legacy['id']);

    $this->enableBillingSection();

    // Enable Recurring bits
    $this->getSession()->getPage()->selectFieldOption('Frequency of Installments', 'month');
    $this->getSession()->getPage()->checkField('Number of Installments');
    $this->htmlOutput();

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    // Test 1: $120 -> paid in 12 instalments -> $10/month
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '120.00');
    $this->getSession()->getPage()->fillField('Number of Installments', '12.00');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '120.00');

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

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('10.00', $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    $this->assertNotEmpty($contribution['contribution_recur_id']);
    $api_result = $this->utils->wf_civicrm_api('ContributionRecur', 'get', [
      'sequential' => 1,
    ]);
    $contributionRecur = reset($api_result['values']);
    $this->assertEquals('month', $contributionRecur['frequency_unit']);
    $this->assertEquals('10.00', $contributionRecur['amount']);
    $this->assertEquals('USD', $contributionRecur['currency']);
    $this->assertEquals('1', $contributionRecur['frequency_interval']);
    $this->assertEquals('12', $contributionRecur['installments']);

    $api_result = $this->utils->wf_civicrm_api('PaymentToken', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $paymentToken = reset($api_result['values']);
    // throw new \Exception(var_export($paymentToken, TRUE));
    $this->assertNotEmpty($paymentToken['token']);

    // Test 2: $120 -> paid monthly -> $120/month
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '120.00');
    $this->getSession()->getPage()->fillField('Number of Installments', '0');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '120.00');

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

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(2, $api_result['count']);
    // I need the second Contribution!
    $contribution = $api_result['values'][1];
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('120.00', $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    $this->assertNotEmpty($contribution['contribution_recur_id']);
    $api_result = $this->utils->wf_civicrm_api('ContributionRecur', 'get', [
      'sequential' => 1,
    ]);
    $contributionRecur = $api_result['values'][1];

    $this->assertEquals('month', $contributionRecur['frequency_unit']);
    $this->assertEquals('120.00', $contributionRecur['amount']);
    $this->assertEquals('USD', $contributionRecur['currency']);
    $this->assertEquals('1', $contributionRecur['frequency_interval']);
    $this->assertEquals('0', $contributionRecur['installments']);

    $api_result = $this->utils->wf_civicrm_api('PaymentToken', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(2, $api_result['count']);
    $paymentToken = $api_result['values'][1];
    // throw new \Exception(var_export($paymentToken, TRUE));
    $this->assertNotEmpty($paymentToken['token']);
  }

}
