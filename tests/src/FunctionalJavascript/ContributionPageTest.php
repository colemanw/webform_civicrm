<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with a contribution page.
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
    $result = \wf_civicrm_api('payment_processor', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    return current($result['values']);
  }

  public function testSubmitContribution() {
    $payment_processor = $this->createPaymentProcessor();
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

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    // Setup contact information wizard page.
    $this->configureContactInformationWizardPage();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->fillField('Line Item Amount', '704.5454');
    $this->getSession()->getPage()->fillField('Line Item Amount 2', '70.45454');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '785.00');

    // Wait for the credit card form to load in.
    $this->assertSession()->waitForField('credit_card_number');
    $this->getSession()->getPage()->fillField('Card Number', '4111111111111111');
    $this->getSession()->getPage()->fillField('Security Code', '123');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[M]', '11');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[Y]', '2023');
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

    $api_result = wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('785.00', $contribution['net_amount']);
    $this->assertEquals('785.00', $contribution['total_amount']);

    $api_result = wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(3, $api_result['count']);
    $this->assertEquals('10.00', $api_result['values'][0]['line_total']);
    $this->assertEquals('704.55', $api_result['values'][1]['line_total']);
    $this->assertEquals('70.45', $api_result['values'][2]['line_total']);
    $sum_line_items = $api_result['values'][0]['line_total'] + $api_result['values'][1]['line_total'] + $api_result['values'][2]['line_total'];
    $this->assertEquals($contribution['total_amount'], $sum_line_items);
    // throw new \Exception(var_export($api_result, TRUE));
  }

}
