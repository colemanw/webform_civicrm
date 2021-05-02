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
    'token',
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
    $this->utils = \Drupal::service('webform_civicrm.utils');

    // Make sure we are using distinct default and administrative themes for
    // the duration of these tests.
    \Drupal::service('theme_installer')->install(['bartik', 'seven']);
    $this->config('system.theme')
      ->set('default', 'bartik')
      ->set('admin', 'seven')
      ->save();

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
    $this->rootUserCid = $this->createIndividual()['id'];
    //Create civi contact for rootUser.
    $this->utils->wf_civicrm_api('UFMatch', 'create', [
      'uf_id' => $this->rootUser->id(),
      'uf_name' => $this->rootUser->getAccountName(),
      'contact_id' => $this->rootUserCid,
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
    $element->blur();
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
   * Modify settings so the element displays as a checkbox
   *
   * @param string $selector
   * @param bool $multiple
   * @param bool $enableStatic
   *   TRUE if static radio option should be enabled.
   * @param bool $default
   * @param string $type
   *  possible values - checkboxes, radios, select, civicrm-options
   */
  protected function editCivicrmOptionElement($selector, $multiple = TRUE, $enableStatic = FALSE, $default = NULL, $type = NULL) {
    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="' . $selector . '"] a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    if ($type) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-change-type"]')->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector='edit-elements-{$type}-operation']")->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector='edit-cancel']");
    }

    if ($enableStatic) {
      $this->getSession()->getPage()->selectFieldOption("properties[civicrm_live_options]", 0);
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->waitForField('properties[options][options][civicrm_option_1][enabled]');
    }
    if ($default) {
      $this->getSession()->getPage()->selectFieldOption("properties[options][default]", $default);
    }
    if (!$type || $type == 'civicrm-options') {
      $this->getSession()->getPage()->uncheckField('properties[extra][aslist]');
      $this->assertSession()->checkboxNotChecked('properties[extra][aslist]');
      $this->htmlOutput();
    }
    if ($multiple) {
      $this->getSession()->getPage()->checkField('properties[extra][multiple]');
      $this->assertSession()->checkboxChecked('properties[extra][multiple]');
    }
    elseif (!$type || $type == 'civicrm-options') {
      $this->getSession()->getPage()->uncheckField('properties[extra][multiple]');
      $this->assertSession()->checkboxNotChecked('properties[extra][multiple]');
    }
    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
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
    $result = $this->utils->wf_civicrm_api('payment_processor', 'create', $params);
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
    $this->assertPageNoErrorMessages();
  }

  /**
   * Return UF Match record.
   *
   * @param int $ufID
   */
  protected function getUFMatchRecord($ufID) {
    return $this->utils->wf_civicrm_api('UFMatch', 'getsingle', [
      'uf_id' => $ufID,
    ]);
  }

  /**
   * Edit contact element on the build form.
   *
   * @param string $selector
   * @param string $widget
   * @param string $default
   * @param bool $removeDefaultURL
   */
  protected function editContactElement($selector, $widget, $default = NULL, $removeDefaultURL = FALSE) {
    $contactElementEdit = $this->assertSession()->elementExists('css', "[data-drupal-selector='{$selector}'] a.webform-ajax-link");
    $contactElementEdit->click();
    $this->htmlOutput();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-form"]')->click();

    $this->assertSession()->waitForField('properties[widget]');
    $this->getSession()->getPage()->selectFieldOption('Form Widget', $widget);
    $this->assertSession()->assertWaitOnAjaxRequest();
    if ($widget == 'Autocomplete') {
      $this->assertSession()->waitForElementVisible('css', '[data-drupal-selector="edit-properties-search-prompt"]');
      $this->getSession()->getPage()->fillField('Search Prompt', '- Select Contact -');
    }
    $this->htmlOutput();

    if ($default) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-contact-defaults"]')->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->selectFieldOption('Set default contact from', $default);
    }

    if ($removeDefaultURL) {
      $this->getSession()->getPage()->uncheckField('properties[allow_url_autofill]');
    }

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Fill Contact Autocomplete widget.
   *
   * @param string $id
   * @param string $value
   */
  protected function fillContactAutocomplete($id, $value) {
    $page = $this->getSession()->getPage();
    $driver = $this->getSession()->getDriver()->getWebDriverSession();
    $elementXpath = $page->findField($id)->getXpath();

    $this->assertSession()->elementExists('css', "#" . $id)->click();
    $driver->element('xpath', $elementXpath)->postValue(['value' => [$value]]);

    $this->assertSession()->waitForElementVisible('xpath', '//li[contains(@class, "token-input-dropdown")][1]');
    $this->createScreenshot($this->htmlOutputDirectory . '/autocomplete.png');

    $page->find('xpath', '//li[contains(@class, "token-input-dropdown")][1]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Create test contact of type individual.
   */
  protected function createIndividual() {
    $params = [
      'contact_type' => 'Individual',
      'first_name' => substr(sha1(rand()), 0, 7),
      'last_name' => substr(sha1(rand()), 0, 7),
    ];
    return current($this->utils->wf_civicrm_api('contact', 'create', $params)['values']);
  }

  /**
   * Enable Component in CiviCRM.
   *
   * @param string $componentName
   */
  protected function enableComponent($componentName) {
    $enabledComponents = $this->utils->wf_crm_get_civi_setting('enable_components');
    if (in_array($componentName, $enabledComponents)) {
      // component is already enabled
      return;
    }
    $enabledComponents[] = $componentName;
    $this->utils->wf_civicrm_api('Setting', 'create', [
      'enable_components' => $enabledComponents,
    ]);
  }

  /**
   * Enable Billing Section on the contribution tab.
   */
  protected function enableBillingSection() {
    $this->getSession()->getPage()->selectFieldOption('Enable Billing Address?', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->assertSession()->checkboxChecked("Billing First Name");
    $this->assertSession()->checkboxNotChecked("Billing Middle Name");
    $this->assertSession()->checkboxChecked("Billing Last Name");
    $this->assertSession()->checkboxChecked("Street Address");
    $this->assertSession()->checkboxChecked("Postal Code");
    $this->assertSession()->checkboxChecked("City");
    $this->assertSession()->checkboxChecked("Country");
    $this->assertSession()->checkboxChecked("State/Province");
  }

  /**
   * Insert values in billing fields.
   *
   * @param array $params
   */
  protected function fillBillingFields($params) {
    $this->getSession()->getPage()->fillField('Billing First Name', $params['first_name']);
    $this->getSession()->getPage()->fillField('Billing Last Name', $params['last_name']);
    $this->getSession()->getPage()->fillField('Street Address', $params['street_address']);
    $this->getSession()->getPage()->fillField('City', $params['city']);

    $this->getSession()->getPage()->selectFieldOption('Country', $params['country']);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('State/Province', $params['state_province']);

    $this->getSession()->getPage()->fillField('Postal Code', $params['postal_code']);
  }

}
