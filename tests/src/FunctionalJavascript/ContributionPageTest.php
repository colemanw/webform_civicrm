<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;

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
    $this->getSession()->getPage()->clickLink('CiviCRM');
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
    $this->htmlOutput();
    $element_form = $this->getSession()->getPage()->findById('webform-ui-element-form-ajax');
    $element_form->fillField('Title', 'Contact information');
    // @todo Regular tricks waiting for the machine name fail, here.
    $element_form->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('Key field is required.');
    $this->getSession()->getPage()->fillField('Key', 'contact_information');
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
    $this->htmlOutput();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->pressButton('Next >');
    // @todo need to figure out why the credit card form does not render.
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('Contribution Amount', '25.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '25.00');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();
    $this->assertSession()->pageTextNotContains('Card Number field is required.');
  }

}
