<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contribution with Line Items and Sales Tax
 *
 * @group webform_civicrm
 */
final class ContributionDummyTest extends WebformCivicrmTestBase {

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

  /**
   * Test One-page donation
   */
  public function testOnePageDonation() {
    $payment_processor = $this->createPaymentProcessor();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->configureContributionTab(FALSE, $payment_processor['id']);
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");

    $this->getSession()->getPage()->clickLink('Additional Settings');
    $this->getSession()->getPage()->checkField("Disable Contact Paging");
    $this->assertSession()->checkboxChecked("Disable Contact Paging");

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->pageTextNotContains('contact_pagebreak');

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');

    $this->fillCardAndSubmit();

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
  }

  public function testSubmitContribution() {
    $payment_processor = $this->createPaymentProcessor();

    $this->setupSalesTax(2, $accountParams = []);
    $this->cid2 = $this->createIndividual(['first_name' => 'Mark', 'last_name' => 'Cooper']);

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->clickLink('2. Contact 2');
    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_existing");

    $this->configureContributionTab(FALSE, $payment_processor['id']);
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");
    $el = $this->getSession()->getPage()->findField('Payment Processor');
    $opts = $el->findAll('css', 'option');
    $this->assertCount(3, $opts, 'Payment processor values: ' . implode(', ', array_map(static function(NodeElement $el) {
        return $el->getValue();
      }, $opts)));

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

    $this->drupalGet($this->webform->toUrl('canonical',  ['query' => ['cid2' => $this->cid2['id']]]));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->fillField('Line Item Amount', '20.00');
    $this->getSession()->getPage()->fillField('Line Item Amount 2', '29.50');

    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-first-name', 'Mark');
    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-last-name', 'Cooper');
    $this->addFieldValue('civicrm_2_contact_1_contact_first_name', 'MarkUpdated');
    $this->addFieldValue('civicrm_2_contact_1_contact_last_name', 'CooperUpdated');
    $this->getSession()->getPage()->pressButton('Next >');

    $this->assertSession()->waitForField('edit-wizard-prev');
    $this->getSession()->getPage()->pressButton('edit-wizard-prev');
    $this->assertSession()->waitForField('edit-civicrm-2-contact-1-contact-first-name');
    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-first-name', 'MarkUpdated');
    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-last-name', 'CooperUpdated');
    $this->getSession()->getPage()->pressButton('Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '60.98');

    $this->fillCardAndSubmit();

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
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
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
      'return' => 'tax_amount',
    ]);
    $contribution = reset($api_result['values']);
    $this->assertEquals('1.48', $contribution['tax_amount']);
    $tax_total_amount = $contribution['tax_amount'];

    $api_result = $this->utils->wf_civicrm_api('line_item', 'get', [
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

  public function testOverThousand() {
    $payment_processor = $this->createPaymentProcessor();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');

    $this->configureContributionTab(FALSE, $payment_processor['id']);
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $el = $this->getSession()->getPage()->findField('Payment Processor');
    $opts = $el->findAll('css', 'option');
    $this->assertCount(3, $opts, 'Payment processor values: ' . implode(', ', array_map(static function(NodeElement $el) {
        return $el->getValue();
      }, $opts)));

    $this->enableBillingSection();
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '1200.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '1,200');

    $this->fillCardAndSubmit();

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('1200.00', $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    $api_result = $this->utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals('1.00', $api_result['values'][0]['qty']);
    $this->assertEquals('1200.00', $api_result['values'][0]['unit_price']);
    $this->assertEquals('1200.00', $api_result['values'][0]['line_total']);
    $this->assertEquals('1', $api_result['values'][0]['financial_type_id']);
  }

  /**
   * Fill Card Details and submit.
   */
  public function fillCardAndSubmit() {
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
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }

}
