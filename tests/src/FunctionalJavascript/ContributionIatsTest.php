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
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Download installs and enables!
    $result = civicrm_api3('Extension', 'download', [
      'key' => "com.iatspayments.civicrm",
    ]);

    // Legacy
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
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('payment_processor', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->payment_processor_legacy = current($result['values']);

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
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('payment_processor', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->payment_processor_faps = current($result['values']);

    drupal_flush_all_caches();
  }

  private function setupSalesTax(int $financialTypeId, $accountParams = []) {
    $params = array_merge([
      'name' => 'Sales tax account ' . substr(sha1(rand()), 0, 4),
      'financial_account_type_id' => key(\CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL, " AND v.name LIKE 'Liability' ")),
      'is_tax' => 1,
      'tax_rate' => 5,
      'is_active' => 1,
    ], $accountParams);
    $account = \CRM_Financial_BAO_FinancialAccount::add($params);
    $entityParams = [
      'entity_table' => 'civicrm_financial_type',
      'entity_id' => $financialTypeId,
      'account_relationship' => key(\CRM_Core_PseudoConstant::accountOptionValues('account_relationship', NULL, " AND v.name LIKE 'Sales Tax Account is' ")),
    ];

    \Civi::$statics['CRM_Core_PseudoConstant']['taxRates'][$financialTypeId] = $params['tax_rate'];

    $dao = new \CRM_Financial_DAO_EntityFinancialAccount();
    $dao->copyValues($entityParams);
    $dao->find();
    if ($dao->fetch()) {
      $entityParams['id'] = $dao->id;
    }
    $entityParams['financial_account_id'] = $account->id;

    return \CRM_Financial_BAO_FinancialTypeAccount::add($entityParams);
  }

  public function testSubmit1stPayContribution() {
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
    $this->assertCount(4, $this->getOptions('Payment Processor'));
    $this->getSession()->getPage()->selectFieldOption('Payment Processor',  $this->payment_processor_faps['id']);
    $this->enableBillingSection();

    $this->saveCiviCRMSettings();

    // Setup contact information wizard page.
    $this->configureContactInformationWizardPage();

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
    $this->assertPageNoErrorMessages();
    $this->createScreenshot($this->htmlOutputDirectory . 'faps169.png');
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', '.webform-confirmation');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->assertPageNoErrorMessages();
  }

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

    // $this->getSession()->getPage()->fillField('Cryptogram', 'cryptogram');

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

  public function testSubmitContribution() {
    $financialAccount = $this->setupSalesTax(2, $accountParams = []);

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

    $this->assertCount(4, $this->getOptions('Payment Processor'));
    $this->getSession()->getPage()->selectFieldOption('Payment Processor',  $this->payment_processor_legacy['id']);

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

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    // Setup contact information wizard page.
    $this->configureContactInformationWizardPage();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->fillField('Line Item Amount', '1.75');
    $this->getSession()->getPage()->fillField('Line Item Amount 2', '5.00');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '3.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');

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
    // throw new \Exception(var_export($this->htmlOutputDirectory, TRUE));

    $this->createScreenshot($this->htmlOutputDirectory . '/legacy289.png');
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertContains(':', $contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('10.00', $contribution['total_amount']);
    $contribution_total_amount = $contribution['total_amount'];
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    // Also retrieve tax_amount (have to ask for it to be returned):
    $api_result = $utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
      'return' => ['tax_amount', 'payment_instrument_id'],
    ]);
    $contribution = reset($api_result['values']);
    $creditCardID = $utils->wf_civicrm_api('OptionValue', 'getvalue', [
      'return' => "value",
      'label' => "Credit Card",
      'option_group_id' => "payment_instrument",
    ]);
    $this->assertEquals('0.25', $contribution['tax_amount']);
    $this->assertEquals($creditCardID, $contribution['payment_instrument_id']);
    $tax_total_amount = $contribution['tax_amount'];

    $api_result = $utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);

    // throw new \Exception(var_export($api_result, TRUE));

    $this->assertEquals('3.00', $api_result['values'][0]['line_total']);
    $this->assertEquals('1', $api_result['values'][0]['financial_type_id']);
    $this->assertEquals('1.75', $api_result['values'][1]['line_total']);
    $this->assertEquals('1', $api_result['values'][1]['financial_type_id']);
    $this->assertEquals('5.00', $api_result['values'][2]['line_total']);
    $this->assertEquals('0.25', $api_result['values'][2]['tax_amount']);
    $this->assertEquals('2', $api_result['values'][2]['financial_type_id']);

    $sum_line_total = $api_result['values'][0]['line_total'] + $api_result['values'][1]['line_total'] + $api_result['values'][2]['line_total'];
    $sum_tax_amount = $api_result['values'][2]['tax_amount'];
    $this->assertEquals($tax_total_amount, $sum_tax_amount);
    $this->assertEquals($contribution_total_amount, $sum_line_total + $sum_tax_amount);
  }

}
