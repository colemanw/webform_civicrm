<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\Tests\webform\Traits\WebformBrowserTestTrait;

abstract class WebformCivicrmTestBase extends CiviCrmTestBase {

  use WebformBrowserTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'webform',
    'webform_ui',
    'webform_civicrm',
  ];

  /**
   * {@inheritdoc}
   *
   * During tests configuration schema is validated. This module does not
   * provide schema definitions for its handler.
   *
   * To fix: webform.webform.civicrm_webform_test:handlers.webform_civicrm.settings
   *
   * @see \Drupal\Core\Test\TestSetupTrait::getConfigSchemaExclusions
   */
  protected static $configSchemaCheckerExclusions = [
    'webform.webform.civicrm_webform_test',
  ];

  /**
   * The test webform.
   *
   * @var \Drupal\webform\WebformInterface
   */
  protected $webform;

  /**
   * The test admin user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->adminUser = $this->createUser([
      'access content',
      'administer CiviCRM',
      'access CiviCRM',
      'access administration pages',
      'access webform overview',
      'administer webform',
      'edit all contacts',
      'view all activities',
    ]);
    $this->webform = $this->createWebform([
      'id' => 'civicrm_webform_test',
      'title' => 'CiviCRM Webform Test',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function initFrontPage() {
    parent::initFrontPage();
    // Fix hidden columns on build page.
    $this->getSession()->resizeWindow(1440, 900);
  }

  /**
   * Configures contact information fieldset into its own wizard page.
   */
  protected function configureContactInformationWizardPage() {
    $this->drupalGet($this->webform->toUrl('edit-form'));

    // Add the "Contact information" wizard page.
    $this->getSession()->getPage()->clickLink('Add page');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $element_form = $this->getSession()->getPage()->findById('webform-ui-element-form-ajax');
    $element_form->fillField('Title', 'Contact information');
    $this->assertSession()->waitForElementVisible('css', '.machine-name-value');
    $element_form->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Put contact elements into new page.
    $contact_information_page_row_handle = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-contact-information"] a.tabledrag-handle');
    // Move up twice to be the top-most element.
    $this->sendKeyPress($contact_information_page_row_handle, 38);
    $this->sendKeyPress($contact_information_page_row_handle, 38);
    $contact_information_page_row_handle->blur();
    $contact_fieldset_row_handle = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contact-1-fieldset-fieldset"] a.tabledrag-handle');
    $this->sendKeyPress($contact_fieldset_row_handle, 39);
    $contact_fieldset_row_handle->blur();
    $this->getSession()->getPage()->pressButton('Save elements');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Send a key press to an element.
   *
   * @var \Behat\Mink\Element\NodeElement $element
   *   The element.
   * @var string|int $char
   *   The character or char key code
   * @var string $modifier
   *   The modifier (could be 'ctrl', 'alt', 'shift' or 'meta').
   */
  protected function sendKeyPress(NodeElement $element, $char, $modifier = '') {
    $element->keyDown($char, $modifier);
    $element->keyUp($char, $modifier);
  }

  /**
   * Asserts the page has no error messages.
   */
  protected function assertPageNoErrorMessages() {
    $error_messages = $this->getSession()->getPage()->findAll('css', '.messages.messages--error');
    $this->assertCount(0, $error_messages, implode(', ', array_map(static function(NodeElement $el) {
      return $el->getValue();
    }, $error_messages)));
  }

  /**
   * Modify settings so the element displays as a checkbox.
   */
  protected function enableCheckboxOnElement($selector) {
    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="' . $selector . '"] a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->uncheckField('properties[extra][aslist]');
    $this->assertSession()->checkboxNotChecked('properties[extra][aslist]');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
  }

  /**
   * Modify settings so the element displays Static Option.
   */
  protected function enableStaticOptionOnElement($selector) {
    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="' . $selector . '"] a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->selectFieldOption("properties[civicrm_live_options]", 0);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForField('properties[options][options][civicrm_option_1][label]');
    $this->getSession()->getPage()->fillField('properties[options][options][civicrm_option_1][label]', 'Red - Recommended');

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
  }

  /**
   * Create Payment Processor.
   */
  protected function createPaymentProcessor() {
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

  /**
   * Enables civicrm on the webform.
   */
  public function enableCivicrmOnWebform() {
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->selectFieldOption('1_contact_type', 'individual');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Save the settings configured on the civicrm tab.
   */
  public function saveCiviCRMSettings() {
    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
  }

}
