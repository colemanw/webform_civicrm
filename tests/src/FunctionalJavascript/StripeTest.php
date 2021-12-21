<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contribution with Line Items
 *
 * @group webform_civicrm
 */
final class StripeTest extends WebformCivicrmTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Download installs and enables!
    $this->utils->wf_civicrm_api('Extension', 'download', [
      'key' => "mjwshared",
    ]);
    $this->utils->wf_civicrm_api('Extension', 'download', [
      'key' => "com.drastikbydesign.stripe",
    ]);
    $params = [];
    $result = $this->utils->wf_civicrm_api('Stripe', 'setuptest', $params);
    $this->paymentProcessorID = $result['id'];
    $this->utils->wf_civicrm_api('PaymentProcessor', 'create', [
      'id' => $this->paymentProcessorID,
      'is_test' => 0,
    ]);

    $this->utils->wf_civicrm_api('Setting', 'create', [
      'stripe_nobillingaddress' => 1,
    ]);
    drupal_flush_all_caches();
  }

  /**
   * Test webform submission using stripe processor.
   * Verifies the payment with 1 contribution and 2 line item amounts.
   */
  public function testSubmitContribution() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->setUpSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
      'Email' => 'fred@example.com',
      'Line Item Amount' => '20.00',
      'Line Item Amount 2' => '29.50',
    ];
    $this->postSubmission($this->webform, $edit, 'Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '59.50');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->fillStripeCardWidget();

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', '.webform-confirmation');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->assertPageNoErrorMessages();

    $this->verifyPaymentResult();
  }

  /**
   * Test webform submission using stripe processor with AJAX enabled.
   */
  public function testAjaxSubmitContribution() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->webform->setSetting('ajax', TRUE);
    $this->webform->save();
    $this->setUpSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
      'Email' => 'fred@example.com',
      'Line Item Amount' => '20.00',
      'Line Item Amount 2' => '29.50',
    ];
    $this->postSubmission($this->webform, $edit, 'Next >');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '59.50');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->fillStripeCardWidget();

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', '.webform-confirmation');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->assertPageNoErrorMessages();

    $this->verifyPaymentResult();
  }

  /**
   * Fill values on the stripe card element.
   */
  private function fillStripeCardWidget() {
    $expYear = date('y') + 1;
    // Wait for the credit card form to load in.
    $stripeCardElement = $this->assertSession()->waitForElementVisible('xpath', '//div[contains(@class, "StripeElement")]/div/iframe');
    $this->assertNotEmpty($stripeCardElement);
    $this->getSession()->switchToIFrame($stripeCardElement->getAttribute('name'));
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSession()->waitForElementVisible('css', 'input[name="cardnumber"]');
    $this->getSession()->getPage()->fillField('cardnumber', '4111 1111 1111 1111');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('exp-date', '11 / ' . $expYear);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('cvc', '123');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('postal', '12345');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->switchToIFrame();
  }

  /**
   * Verify Payment values.
   */
  private function verifyPaymentResult() {
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'contribution_status_id' => 'Completed',
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('59.50', $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    $creditCardID = $this->utils->wf_civicrm_api('OptionValue', 'getvalue', [
      'return' => "value",
      'label' => "Credit Card",
      'option_group_id' => "payment_instrument",
    ]);
    $this->assertEquals($creditCardID, $contribution['payment_instrument_id']);

    $lineItems = $this->utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ])['values'];
    $lineTotals = array_column($lineItems, 'line_total');
    $expectedLineTotals = ['10.00', '20.00', '29.50'];
    $this->assertEquals($expectedLineTotals, $lineTotals);

    $financialTypeIds = array_column($lineItems, 'financial_type_id');
    $expectedFTIds = ['1', '1', '2'];
    $this->assertEquals($expectedFTIds, $financialTypeIds);
    $this->assertEquals($contribution['total_amount'], array_sum($lineTotals));

    $priceFieldID = $utils->wf_civicrm_api('PriceField', 'get', [
      'sequential' => 1,
      'price_set_id' => 'default_contribution_amount',
      'options' => ['limit' => 1],
    ])['id'] ?? NULL;
    foreach ($lineItems as $item) {
      $this->assertEquals($priceFieldID, $item['price_field_id']);
    }
  }

  /**
   * Setup CiviCRM settings.
   */
  protected function setUpSettings() {
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

    $this->assertCount(3, $this->getOptions('Payment Processor'));
    $this->getSession()->getPage()->selectFieldOption('Payment Processor', $this->paymentProcessorID);
    $this->enableBillingSection();

    $this->getSession()->getPage()->selectFieldOption('lineitem_1_number_of_lineitem', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_1_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_1_contribution_line_total");
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_2_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_2_contribution_line_total");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_lineitem_2_contribution_financial_type_id', 2);

    $this->saveCiviCRMSettings();
  }

}
