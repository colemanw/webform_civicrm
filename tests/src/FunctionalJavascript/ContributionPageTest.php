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
final class ContributionPageTest extends WebformCivicrmTestBase {

  private function createPaymentProcessor() {
    $params = [
      'domain_id' => 1,
      'name' => 'Dummy',
      'payment_processor_type_id' => 'Dummy',
      'is_active' => 1,
      'is_default' => 1,
      'is_test' => 0,
      'user_name' => 'foo',
      'url_site' => 'http://dummy.com',
      'url_recur' => 'http://dummy.com',
      'class_name' => 'Payment_Dummy',
      'billing_mode' => 1,
      'is_recur' => 1,
      'payment_instrument_id' => 'Credit Card',
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('payment_processor', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    return current($result['values']);
  }

  private function setupSalesTax(int $financialTypeId, $accountParams = []) {
    // https://github.com/civicrm/civicrm-core/blob/master/tests/phpunit/CiviTest/CiviUnitTestCase.php#L3104
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

  public function testSubmitContribution() {
    $payment_processor = $this->createPaymentProcessor();

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

    $el = $this->getSession()->getPage()->findField('Payment Processor');
    $opts = $el->findAll('css', 'option');
    $this->assertCount(3, $opts, 'Payment processor values: ' . implode(', ', array_map(static function(NodeElement $el) {
      return $el->getValue();
    }, $opts)));
    $this->getSession()->getPage()->selectFieldOption('Payment Processor', $payment_processor['id']);

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
    $this->getSession()->getPage()->fillField('Line Item Amount', '20.00');
    $this->getSession()->getPage()->fillField('Line Item Amount 2', '29.50');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '60.98');

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
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('60.98', $contribution['total_amount']);
    $contribution_total_amount = $contribution['total_amount'];
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    // Also retrieve tax_amount (have to ask for it to be returned):
    $api_result = $utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
      'return' => 'tax_amount',
    ]);
    $contribution = reset($api_result['values']);
    $this->assertEquals('1.48', $contribution['tax_amount']);
    $tax_total_amount = $contribution['tax_amount'];

    $api_result = $utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals('10.00', $api_result['values'][0]['line_total']);
    $this->assertEquals('1', $api_result['values'][0]['financial_type_id']);
    $this->assertEquals('20.00', $api_result['values'][1]['line_total']);
    $this->assertEquals('1', $api_result['values'][1]['financial_type_id']);
    $this->assertEquals('29.50', $api_result['values'][2]['line_total']);
    $this->assertEquals('1.48', $api_result['values'][2]['tax_amount']);
    $this->assertEquals('2', $api_result['values'][2]['financial_type_id']);

    // throw new \Exception(var_export($api_result, TRUE));
    $sum_line_total = $api_result['values'][0]['line_total'] + $api_result['values'][1]['line_total'] + $api_result['values'][2]['line_total'];
    $sum_tax_amount = $api_result['values'][2]['tax_amount'];
    $this->assertEquals($tax_total_amount, $sum_tax_amount);
    $this->assertEquals($contribution_total_amount, $sum_line_total + $sum_tax_amount);
  }

}
