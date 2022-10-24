<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contribution using AuthorizeNet.
 *
 * @group webform_civicrm
 */
final class AuthorizeNetTest extends WebformCivicrmTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $result = $this->utils->wf_civicrm_api('PaymentProcessor', 'create', [
      'payment_processor_type_id' => 'AuthNet',
      'financial_account_id' => 'Payment Processor Account',
      'name' => 'Credit Card',
      // 'user_name' => 'xxxx',
      // 'password' => 'xxxx',
      'url_site' => 'https://test.authorize.net/gateway/transact.dll',
      'accepted_credit_cards' => [
        'Visa' => 'Visa',
        'Mastercard' => 'Mastercard',
        'Amex' => 'Amex',
        'Discover' => 'Discover',
      ],
    ]);

    $this->paymentProcessorID = $result['id'];

    drupal_flush_all_caches();
  }

  /**
   * Test webform submission using AuthorizeNet processor.
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
    ];
    $this->postSubmission($this->webform, $edit, 'Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->getSession()->getPage()->fillField('Billing First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Billing Last Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Street Address', '123 Milwaukee Ave');
    $this->getSession()->getPage()->fillField('City', 'Milwaukee');
    $this->getSession()->getPage()->fillField('Postal Code', '53177');
    $this->getSession()->getPage()->fillField('Country', '1228');
    $this->getSession()->getPage()->fillField('State/Province', '1048');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->fillAuthorizeNetCardWidget();
    $this->createScreenshot($this->htmlOutputDirectory . '/test.png');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', '.webform-confirmation');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->assertPageNoErrorMessages();
  }

  /**
   * Test webform submission using AuthorizeNet processor with AJAX enabled.
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
    ];
    $this->postSubmission($this->webform, $edit, 'Next >');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->getSession()->getPage()->fillField('Billing First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Billing Last Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Street Address', '123 Milwaukee Ave');
    $this->getSession()->getPage()->fillField('City', 'Milwaukee');
    $this->getSession()->getPage()->fillField('Postal Code', '53177');
    $this->getSession()->getPage()->fillField('Country', '1228');
    $this->getSession()->getPage()->fillField('State/Province', '1048');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->fillAuthorizeNetCardWidget();

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', '.webform-confirmation');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->assertPageNoErrorMessages();
  }

  /**
   * Fill values on the AuthorizeNet card element.
   */
  private function fillAuthorizeNetCardWidget() {
    $authorizeNetCardElement = $this->assertSession()->waitForElementVisible('css', '#billing-payment-block');
    $this->assertNotEmpty($authorizeNetCardElement);

    $this->getSession()->getPage()->fillField('credit_card_number', '4111111111111111');
    $this->getSession()->getPage()->fillField('credit_card_exp_date[M]', '11');
    $this->getSession()->getPage()->fillField('credit_card_exp_date[Y]', date('Y') + 1);
    $this->getSession()->getPage()->fillField('cvv2', '123');
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

    $this->saveCiviCRMSettings();
  }

}
