<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contribution with Line Items
 *
 * @group webform_civicrm
 */
final class AuthnetExtensionTest extends WebformCivicrmTestBase {
  protected $failOnJavascriptConsoleErrors = TRUE;
  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setUpExtension('mjwshared,firewall,com.donordepot.authnetecheck');
    $this->paymentProcessorID = $this->createAuthnetProcessor();

    drupal_flush_all_caches();
  }

  /**
   * Test webform submission using Authnet Extension processor.
   * Verifies the payment with 1 contribution and 2 line item amounts.
   */
  public function testSubmitContribution() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->setUpSettings();

    $this->drupalLogout();
    
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
    $this->fillCardAndSubmit();

    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', '.webform-confirmation');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->assertPageNoErrorMessages();

    $this->verifyPaymentResult();
  }

  /**
   * Verify Payment values.
   */
  private function verifyPaymentResult() {
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'contribution_status_id' => 'Completed',
      'is_test' => 1,                                         
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('59.50', $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('CAD', $contribution['currency']);

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
    $this->pressButtonOverride('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $this->getSession()->getPage()->selectFieldOption('Currency', 'CAD');
    $this->getSession()->getPage()->selectFieldOption('Financial Type', 'Donation');

    $this->getSession()->getPage()->selectFieldOption('Payment Processor Mode', 'Test Mode');
    $this->createScreenshot($this->htmlOutputDirectory . '/righthere1.png');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->getPage()->selectFieldOption('Payment Processor', 'AuthorizeNetCreditcard');
    // I need to do this twice on webform-civicrm.io UI for some reason - so let's do it twice here:
    
    $this->getSession()->getPage()->selectFieldOption('Payment Processor', 'AuthorizeNetCreditcard');

    $this->enableBillingSection();
    $this->createScreenshot($this->htmlOutputDirectory . '/righthere2.png');

    $this->getSession()->getPage()->selectFieldOption('lineitem_1_number_of_lineitem', 2);
    $this->createScreenshot($this->htmlOutputDirectory . '/righthere3.png');

    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_1_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_1_contribution_line_total");
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_2_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_2_contribution_line_total");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_lineitem_2_contribution_financial_type_id', 2);

    $this->saveCiviCRMSettings();
  }

  private function createAuthnetProcessor(): int {
    // make live one first which we don't use, just to be more realistic
    $params = [
      'name' => 'AuthorizeNetCreditcard',
      'domain_id' => \CRM_Core_Config::domainID(),
      'payment_processor_type_id' => 'AuthorizeNetCreditcard',
      'title' => 'Authorize.net (Credit Card) - Extension',
      'is_active' => 1,
      'is_default' => 0,
      'is_test' => 0,
      'is_recur' => 1,
      // magic thing to avoid status check errors
      'user_name' => 'AUTHNETECHECK_SKIP_WEBHOOK_CHECKS',
      'password' => '8Z9nm683Z4aDF5e9',
      'signature_label' => '9DF8BB26F5617270F0CF96DA85372A8DEBC6898B1CA606652203B5688A6E60B82DDACF7D06F9168666950E7C7695B4FC6C16DB0D5C3F102686F0E7F74E04EAE6',
      'url_site' => 'https://unused.org',
      'url_recur' => 'https://unused.org',
      'class_name' => 'Payment_AuthNetCreditcard',
      'billing_mode' => 1
    ];
    // First see if it already exists.
    $result = $this->utils->wf_civicrm_api('PaymentProcessor', 'get', $params);
    if ($result['count'] != 1) {
      $result = $this->utils->wf_civicrm_api('PaymentProcessor', 'create', $params);
    }

    // now make test one
    $params = [
      'name' => 'AuthorizeNetCreditcard',
      'domain_id' => \CRM_Core_Config::domainID(),
      'payment_processor_type_id' => 'AuthorizeNetCreditcard',
      'title' => 'Authorize.net (Credit Card) - Extension',
      'is_active' => 1,
      'is_default' => 1,
      'is_test' => 1,
      'is_recur' => 1,
      'user_name' => '6Ys5aL6ug',
      'password' => '8Z9nm683Z4aDF5e9',
      'signature_label' => '9DF8BB26F5617270F0CF96DA85372A8DEBC6898B1CA606652203B5688A6E60B82DDACF7D06F9168666950E7C7695B4FC6C16DB0D5C3F102686F0E7F74E04EAE6',
      'url_site' => 'https://unused.org',
      'url_recur' => 'https://unused.org',
      'class_name' => 'Payment_AuthNetCreditcard',
      'billing_mode' => 1
    ];
    // First see if it already exists.
    $result = $this->utils->wf_civicrm_api('PaymentProcessor', 'get', $params);
    if ($result['count'] != 1) {
      $result = $this->utils->wf_civicrm_api('PaymentProcessor', 'create', $params);
    }
    return $result['id'];
  }

}
