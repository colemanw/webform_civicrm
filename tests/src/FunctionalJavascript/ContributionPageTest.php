<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with a contribution page.
 *
 * @group webform_civicrm
 */
final class ContributionPageTest extends WebformCivicrmTestBase {

  /**
   * {@inheritdoc}
   */
  protected function initFrontPage() {
    parent::initFrontPage();
    // Fix hidden columns on build page.
    $this->getSession()->resizeWindow(1440, 900);
  }

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
    // $payment_processor['id'];
    $payment_processor = $this->createPaymentProcessor();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet($this->webform->toUrl('settings'));
    $this->getSession()->getPage()->find('css', 'nav.tabs')->clickLink('CiviCRM');
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
    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->getSession()->getPage()->clickLink('Build');
    $this->getSession()->getPage()->clickLink('Add page');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $element_form = $this->getSession()->getPage()->findById('webform-ui-element-form-ajax');
    $element_form->fillField('Title', 'Contact information');
    $this->assertSession()->waitForElementVisible('css', '.machine-name-value');
    $element_form->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Put contact elements into new page.
    $contact_information_page_row_handle = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-contact-information"] a.tabledrag-handle');
    $contact_information_page_row_handle->keyDown(38);
    $contact_information_page_row_handle->keyUp(38);
    $contact_information_page_row_handle->keyDown(38);
    $contact_information_page_row_handle->keyUp(38);
    $contact_information_page_row_handle->blur();
    $contact_fieldset_row_handle = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contact-1-fieldset-fieldset"] a.tabledrag-handle');
    $contact_fieldset_row_handle->keyDown(39);
    $contact_fieldset_row_handle->keyUp(39);
    $contact_fieldset_row_handle->blur();
    $this->getSession()->getPage()->pressButton('Save elements');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $error_messages = $this->getSession()->getPage()->findAll('css', '.messages.messages--error');
    $this->assertCount(0, $error_messages, implode(', ', array_map(static function(NodeElement $el) {
      return $el->getValue();
    }, $error_messages)));
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '25.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '25.00');

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

    // Select2 is being difficult; unhide the country select.
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->executeScript("document.getElementById('billing_country_id-5').style.display = 'block';");
    $this->getSession()->getPage()->fillField('billing_country_id-5', '1228');

    // @todo find a way to better wait for state/pronvince to populate.
    $this->assertSession()->assertWaitOnAjaxRequest();
    sleep(1);

    // Select2 is being difficult; unhide the state/province. select.
    $driver->executeScript("document.getElementById('billing_state_province_id-5').style.display = 'block';");
    $this->getSession()->getPage()->fillField('billing_state_province_id-5', '1048');
    $this->createScreenshot($this->htmlOutputDirectory . '/select2_state.png');

    $this->getSession()->getPage()->fillField('Postal Code', '53177');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->createScreenshot($this->htmlOutputDirectory . '/webform_complete.png');
    $this->htmlOutput();
    $error_messages = $this->getSession()->getPage()->findAll('css', '.messages.messages--error');
    $this->assertCount(0, $error_messages);
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('25.00', $contribution['net_amount']);
    $this->assertEquals('25.00', $contribution['total_amount']);
  }

}
