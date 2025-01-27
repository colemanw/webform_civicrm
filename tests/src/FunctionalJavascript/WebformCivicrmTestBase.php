<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\Tests\webform\Traits\WebformBrowserTestTrait;
use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\ExpectationException;
use Drupal\Core\Url;
use Drupal\webform\WebformInterface;

abstract class WebformCivicrmTestBase extends CiviCrmTestBase {

  use WebformBrowserTestTrait;
  use \Drupal\Tests\mink_civicrm_helpers\Traits\Utils;
  use \Drupal\Tests\system\Traits\OffCanvasTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'webform',
    'webform_ui',
    'webform_civicrm',
    'token',
    'ckeditor5',
    'off_canvas_test',
    'mink_civicrm_helpers',
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
   * @var \Drupal\webform_civicrm\UtilsInterface
   */
  protected $utils;

  /**
   * @var int
   */
  protected $rootUserCid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->utils = \Drupal::service('webform_civicrm.utils');

    // Make sure we are using distinct default and administrative themes for
    // the duration of these tests.
    \Drupal::service('theme_installer')->install(['olivero', 'claro']);
    $this->config('system.theme')
      ->set('default', 'olivero')
      ->set('admin', 'claro')
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
    // Retrieve CiviCRM version
    $result = civicrm_api3('System', 'get', [
      'sequential' => 1,
    ]);
    $CiviCRM_version = $result['values'][0]['version'];
    $this->webform = $this->createWebform([
      'id' => 'civicrm_webform_test',
      'title' => 'CiviCRM Webform Test.' . $CiviCRM_version,
    ]);
    if (version_compare(\CRM_Core_BAO_Domain::version(), '5.79.alpha1', '<')) {
      $this->rootUserCid = $this->createIndividual()['id'];
      // Create CiviCRM contact for rootUser.
      $this->utils->wf_civicrm_api('UFMatch', 'create', [
        'uf_id' => $this->rootUser->id(),
        'uf_name' => $this->rootUser->getAccountName(),
        'contact_id' => $this->rootUserCid,
      ]);
    }
    else {
      $this->rootUserCid = $this->getUFMatchRecord($this->rootUser->id())['contact_id'];
    }
  }

  protected function tearDown(): void {
    // store the civi log in the downloadable artifacts
    $logfile = \Civi::$statics['CRM_Core_Error']['logger_file'] ?? NULL;
    if ($logfile && file_exists($logfile)) {
      copy($logfile, '/home/runner/drupal/web/sites/simpletest/browser_output/' . \CRM_Utils_File::makeFilenameWithUnicode($this->getName()) . '.log');
    }
    parent::tearDown();
  }

  /**
   * Redirect civicrm emails to database.
   */
  public function redirectEmailsToDB() {
    $url = Url::fromUri('internal:/civicrm/admin/setting/smtp', [
      'absolute' => TRUE,
      'query' => ['reset' => 1]
    ])->toString();
    $this->drupalGet($url);

    $this->getSession()->getPage()->selectFieldOption('outBound_option', 5);

    $this->getSession()->getPage()->pressButton('_qf_Smtp_next');
  }

  /**
   * @return string
   */
  public function getMostRecentEmail() {
    $msg = '';
    $result = \Drupal::database()->query("SELECT headers, body FROM civicrm_mailing_spool ORDER BY id DESC LIMIT 1");
    while ($content = $result->fetchAssoc()) {
      $msg = $content['headers'] . "\n\n" . $content['body'];
    }
    return $msg;
  }

  /**
   * Create Membership Type
   */
  protected function createMembershipType($amount = 0, $autoRenew = FALSE, $name = 'Basic', $financialTypeId = 'Member Dues') {
    $result = civicrm_api3('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id' => $financialTypeId,
      'duration_unit' => "year",
      'duration_interval' => 1,
      'period_type' => "rolling",
      'minimum_fee' => $amount,
      'name' => $name,
      'auto_renew' => $autoRenew,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    return array_pop($result['values']);
  }

  /**
   * Create Financial Type
   */
  protected function createFinancialType($name) {
    $result = civicrm_api3('FinancialType', 'create', [
      'name' => $name,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    return array_pop($result['values']);
  }

  protected function setupSalesTax(int $financialTypeId, $accountParams = [], $tax_rate= 5) {
    $params = array_merge([
      'name' => 'Sales tax account ' . substr(sha1(rand()), 0, 4),
      'financial_account_type_id' => key(\CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL, " AND v.name LIKE 'Liability' ")),
      'is_tax' => 1,
      'tax_rate' => $tax_rate,
      'is_active' => 1,
    ], $accountParams);
    $account = \CRM_Financial_BAO_FinancialAccount::writeRecord($params);
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
   * Create custom group.
   */
  protected function createCustomGroup($params = [], $extends = 'Individual') {
    $params = array_merge([
      'title' => "Custom",
      'extends' => $extends,
    ], $params);
    return $this->utils->wf_civicrm_api('CustomGroup', 'create', $params);
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
   * Configure contribution tab on civicrm settings page.
   *
   * @param array $params
   */
  protected function configureContributionTab($params = []) {
    //Configure Contribution tab.
    $this->getSession()->getPage()->clickLink('Contribution');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('You must enable an email field for Contact 1 in order to process transactions.');
    $this->getSession()->getPage()->pressButton('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');
    $this->getSession()->getPage()->selectFieldOption('Financial Type', $params['financial_type_id'] ?? 1);
    $this->assertSession()->assertWaitOnAjaxRequest();

    if (!empty($params['payment_processor_id'])) {
      $this->getSession()->getPage()->selectFieldOption('Payment Processor', $params['payment_processor_id']);
    }
    if (!empty($params['soft'])) {
      $this->getSession()->getPage()->selectFieldOption('Soft Credit To', $params['soft']);
      $this->getSession()->getPage()->selectFieldOption('Soft Credit Type', $params['soft_credit_type_id']);
    }

    if (!empty($params['receipt'])) {
      $this->getSession()->getPage()->selectFieldOption('Enable Receipt?', 'Yes');
      $this->assertSession()->assertWaitOnAjaxRequest();
      foreach ($params['receipt'] as $k => $v) {
        $this->getSession()->getPage()->fillField("receipt_1_number_of_receipt_{$k}", $v);
      }
    }
    else {
      $this->getSession()->getPage()->selectFieldOption('Enable Receipt?', 'No');
      $this->assertSession()->assertWaitOnAjaxRequest();
    }
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
      return $el->getText();
    }, $error_messages)));
    // Check for fatal errors. Doing it this way so that the text itself which
    // contains the stack trace is automatically output to the github action
    // log.
    $the_text = $this->getSession()->getPage()->getText();
    $this->assertStringNotContainsString('The website encountered an unexpected error', $the_text);
  }

  /**
   * Copy of TraversableElement::fillField, but it replaces the existing value on the element rather than appending to it.
   *
   * Fills in field (input, textarea, select) with specified locator.
   *
   * @param string $locator input id, name or label
   * @param string $value   value
   *
   * @throws ElementNotFoundException
   *
   * @see NodeElement::setValue
   */
  public function addFieldValue($locator, $value) {
    $field = $this->getSession()->getPage()->findField($locator);
    if (null === $field) {
      throw new ElementNotFoundException($this->getDriver(), 'form field', 'id|name|label|value|placeholder', $locator);
    }
    $field->doubleClick();
    $field->setValue($value);
  }

  /**
   * Assert populated values on the field.
   * fieldValueEquals() fails for populated values on chromedriver > 91
   *
   * @param $selector
   * @param $value
   * @param $isRadio
   */
  public function assertFieldValue($selector, $value, $isRadio = FALSE) {
    $driver = $this->getSession()->getDriver();
    if ($isRadio) {
      $fieldVal = $driver->evaluateScript("document.querySelector(\"input[type=radio][name='{$selector}']:checked\").value;");
    }
    else {
      $fieldVal = $driver->evaluateScript("document.getElementById('{$selector}').value;");
    }
    $this->assertEquals($fieldVal, $value);
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
   * @param string $singleOption
   *  TRUE if only one option is enabled on the element.
   * @param string $asList
   *  TRUE if element need to be rendered as select element.
   * @param string $secondarySelector
   *  optional secondary selector
   */
  protected function editCivicrmOptionElement($selector, $multiple = TRUE, $enableStatic = FALSE, $default = NULL, $type = NULL, $singleOption = FALSE, $asList = FALSE, $secondarySelector = 'li.edit') {
    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="' . $selector . '"] ' . ($secondarySelector ? "$secondarySelector " : '') . 'a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->waitForOffCanvasArea();
    $this->htmlOutput();
    if ($type) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-change-type"]')->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector='edit-elements-{$type}-operation']", 5000)->click();
      $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector='edit-cancel']", 5000);
    }

    if ($enableStatic) {
      $this->getSession()->getPage()->selectFieldOption("properties[civicrm_live_options]", 0);
      $this->assertSession()->waitForField('properties[options][options][civicrm_option_1][enabled]', 5000);

      if ($singleOption) {
        $select_all_checkbox = $this->assertSession()->elementExists('css', '.select-all input.form-checkbox');
        $select_all_checkbox->click();
        $this->getSession()->wait(1000);
        $select_all_checkbox->click();
        $this->getSession()->wait(1000);

        $this->getSession()->getPage()->checkField('properties[options][options][civicrm_option_1][enabled]');
        $this->assertSession()->checkboxChecked('properties[options][options][civicrm_option_1][enabled]');
      }
    }
    if ($default) {
      $this->getSession()->getPage()->selectFieldOption("properties[options][default]", $default);
    }
    if (!$type || $type == 'civicrm-options') {
      if ($asList) {
        $this->getSession()->getPage()->checkField('properties[extra][aslist]');
        $this->assertSession()->checkboxChecked('properties[extra][aslist]');
        $this->htmlOutput();
      }
      else {
        $this->getSession()->getPage()->uncheckField('properties[extra][aslist]');
        $this->assertSession()->checkboxNotChecked('properties[extra][aslist]');
        $this->htmlOutput();
      }
      if (!$multiple) {
        $this->getSession()->getPage()->uncheckField('properties[extra][multiple]');
        $this->assertSession()->checkboxNotChecked('properties[extra][multiple]');
      }
    }
    if ($multiple) {
      $this->getSession()->getPage()->checkField('properties[extra][multiple]');
      $this->assertSession()->checkboxChecked('properties[extra][multiple]');
    }
    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->waitForText('has been updated.');
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
   * Enables CiviCRM on the webform.
   */
  public function enableCivicrmOnWebform() {
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->selectFieldOption('1_contact_type', 'individual');
  }

  /**
   * Save the settings configured on the civicrm tab.
   *
   * @param boolean $fieldDeleted
   */
  public function saveCiviCRMSettings($fieldDeleted = FALSE) {
    $this->getSession()->getPage()->pressButton('Save Settings');
    if (!$fieldDeleted) {
      $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    }
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
   * Set default value on webform element.
   */
  protected function setDefaultValue($selector, $value) {
    $this->assertSession()->elementExists('css', "[data-drupal-selector='{$selector}'] a.webform-ajax-link")->click();
    $this->htmlOutput();
    $this->assertSession()->waitForElementVisible('xpath', '//a[contains(@id, "--advanced")]');
    $this->assertSession()->elementExists('xpath', '//a[contains(@id, "--advanced")]')->click();
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-default"]')->click();
    $this->getSession()->getPage()->fillField('properties[default_value]', $value);
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains(' has been updated');
  }

  /**
   * Edit contact element on the build form.
   *
   * @param array $params
   *   Example Usage -
   *    $params = [
   *     'selector' => 'edit-webform-ui-elements-civicrm-4-contact-1-contact-existing-operations',
   *     'widget' => 'Static',
   *     'default' => 'relationship',
   *     'filter' => [
   *        'group' => group_id,
   *      ],
   *     'default_relationship' => [
   *       'default_relationship_to' => 'Contact 3',
   *       'default_relationship' => 'Child of Contact 3',
   *     ],
   *   ];
   */
  protected function editContactElement($params) {
    $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector=\"{$params['selector']}\"] a.webform-ajax-link");

    $contactElementEdit = $this->assertSession()->elementExists('css', "[data-drupal-selector=\"{$params['selector']}\"] a.webform-ajax-link");
    $contactElementEdit->click();
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', "button.webform-details-toggle-state");
    $expandLink = $this->cssSelect('button.webform-details-toggle-state')[0];
    if ($expandLink->getText() == 'Expand all') {
      $expandLink->click();
    }
    if (!empty($params['title'])) {
      $this->getSession()->getPage()->fillField('title', $params['title']);
    }
    if (!empty($params['description'])) {
      $this->fillCKEditor('properties[description][value]', $params['description']);
    }
    if (!empty($params['hide_fields'])) {
      $this->getSession()->getPage()->selectFieldOption('properties[hide_fields][]', $params['hide_fields']);
    }
    if (!empty($params['hide_method'])) {
      $this->getSession()->getPage()->selectFieldOption('properties[hide_method]', $params['hide_method']);
    }
    if (!empty($params['submit_disabled'])) {
      $this->getSession()->getPage()->checkField("properties[submit_disabled]");
    }
    if (!empty($params['no_hide_blank'])) {
      $this->getSession()->getPage()->checkField("properties[no_hide_blank]");
    }

    $this->assertSession()->waitForElementVisible('xpath', '//select[@name="properties[widget]"]');
    if ($params['widget'] == 'Static') {
      try {
        $this->getSession()->getPage()->selectFieldOption('properties[show_hidden_contact]', 1);
      }
      catch (\WebDriver\Exception\ElementNotVisible $e) {
        // for some reason the section didn't open probably - click it again ??
        $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-form"]')->click();
        $this->getSession()->getPage()->selectFieldOption('properties[show_hidden_contact]', 1);
      }
    }
    else {
      $this->getSession()->getPage()->selectFieldOption('Form Widget', $params['widget']);
      $this->assertSession()->assertWaitOnAjaxRequest();
      if ($params['widget'] == 'Autocomplete') {
        $this->assertSession()->waitForElementVisible('css', '[data-drupal-selector="edit-properties-search-prompt"]');
        $this->getSession()->getPage()->fillField('Search Prompt', '- Select Contact -');
      }
    }
    $this->htmlOutput();
    if (!empty($params['results_display'])) {
      $this->addFieldValue('properties[results_display][]', $params['results_display']);
    }

    if (!empty($params['default'])) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-contact-defaults"]')->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->selectFieldOption('Set default contact from', $params['default']);

      if ($params['default'] == 'Specified Contact') {
        $this->getSession()->getPage()->fillField('default-contact-id', $params['default_contact_id']);
      }

      if ($params['default'] == 'relationship') {
        $this->getSession()->getPage()->selectFieldOption('properties[default_relationship_to]', $params['default_relationship']['default_relationship_to']);
        $this->assertSession()->assertWaitOnAjaxRequest();
        $this->getSession()->getPage()->selectFieldOption('properties[default_relationship][]', $params['default_relationship']['default_relationship']);
      }
    }

    // Apply contact filter.
    if (!empty($params['filter'])) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-filters"]')->click();
      if (!empty($params['filter']['group'])) {
        $this->getSession()->getPage()->selectFieldOption('Groups', $params['filter']['group']);
      }
      if (!empty($params['filter']['filter_relationship_types'])) {
        $this->getSession()->getPage()->selectFieldOption('properties[filter_relationship_types][]', $params['filter']['filter_relationship_types']);
      }
      if (isset($params['filter']['check_permissions']) && empty($params['filter']['check_permissions'])) {
        $this->getSession()->getPage()->uncheckField('properties[check_permissions]');
      }
    }

    if (!empty($params['remove_default_url'])) {
      $this->getSession()->getPage()->uncheckField('properties[allow_url_autofill]');
    }
    if (!empty($params['required'])) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-validation"]')->click();
      $this->getSession()->getPage()->checkField('properties[required]');
    }

    // Wait for ajax message from previous click of Save button to no longer be
    // visible to avoid falling through the following waitForElementVisible
    // prematurely.
    do {
      $ajax_message_visible = $this->assertSession()->waitForElementVisible('css', '.webform-ajax-messages', 100);
    } while ($ajax_message_visible);

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->waitForElementVisible('css', '.webform-ajax-messages');
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
    // $this->createScreenshot($this->htmlOutputDirectory . '/autocomplete.png');

    $page->find('xpath', '//li[contains(@class, "token-input-dropdown")][1]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Asserts that a select option in the current page is checked.
   *
   * @param string $id
   *   ID of select field to assert.
   * @param string $option
   *   Option to assert.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages with t(). If left blank, a default message will be displayed.
   */
  protected function assertOptionSelected($id, $option, $message = NULL) {
    $option_field = $this->assertSession()->optionExists($id, $option);
    $message = $message ?: "Option $option for field $id is not selected.";
    $this->assertTrue($option_field->hasAttribute('selected'), $message);
  }

  /**
   * Create test contact of type individual.
   */
  protected function createIndividual($params = []) {
    $params = array_merge([
      'contact_type' => 'Individual',
      'first_name' => substr(sha1(rand()), 0, 7),
      'last_name' => substr(sha1(rand()), 0, 7),
    ], $params);
    return current($this->utils->wf_civicrm_api('contact', 'create', $params)['values']);
  }

  /**
   * Create test contact of type individual.
   */
  protected function createHousehold($params = []) {
    $params = array_merge([
      'contact_type' => 'Household',
      'household_name' => substr(sha1(rand()), 0, 7),
    ], $params);
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
    $this->getSession()->wait(1000);
    $this->getSession()->getPage()->selectFieldOption('State/Province', $params['state_province']);

    $this->getSession()->getPage()->fillField('Postal Code', $params['postal_code']);
  }

  /**
   * Fill Card Details and submit.
   *
   * @param array $billingValues
   *  Assert values populated in billing address fields after sameas checkbox is enabled.
   */
  protected function fillCardAndSubmit($billingValues = []) {
    if (!empty($billingValues)) {
      $this->getSession()->getPage()->checkField("civicrm_1_contribution_1_contribution_billing_address_same_as");
      $this->getSession()->wait(1000);

      // Verify populated values for billing fields.
      foreach ($billingValues as $key => $val) {
        $billingKey = 'civicrm_1_contribution_1_contribution_billing_address_' . $key;
        $this->assertSession()->fieldValueEquals($billingKey, $val);
      }
    }
    else {
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
    }
    // Wait for the credit card form to load in.
    $this->assertSession()->waitForField('credit_card_number');
    $this->getSession()->getPage()->fillField('credit_card_number', '4222222222222220');
    $this->getSession()->getPage()->fillField('cvv2', '123');
    $this->getSession()->getPage()->selectFieldOption($this->getCreditCardMonthFieldName(), '11');
    $this_year = date('Y');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[Y]', $this_year + 1);

    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();
    $this->assertSession()->waitForText('New submission added to CiviCRM Webform Test.');
  }

  /**
   * Fill CKEditor field.
   *
   * @param string $locator
   * @param string $value
   */
  public function fillCKEditor($locator, $value) {
    $format = \Drupal::config('webform.settings')->get('html_editor.element_format');
    if ($format === 'webform_default') {
      $locator .= '[value]';
    }

    $el = $this->getSession()->getPage()->findField($locator);
    if (empty($el)) {
      throw new ExpectationException('Could not find WYSIWYG with locator: ' . $locator, $this->getSession()->getDriver());
    }
    $fieldId = $el->getAttribute('id');
    if (empty($fieldId)) {
      throw new Exception('Could not find an id for field with locator: ' . $locator);
    }

    // Fill value on the wysiwyg editor.
    $this->getSession()->executeScript("
      const element = document.getElementById(\"$fieldId\");
      const editor = Drupal.CKEditor5Instances.get(
        element.getAttribute('data-ckeditor5-id'),
      );
      editor.setData(\"$value\");
    ");
  }

  /**
   * Add email handler
   *
   * @param array $params
   */
  protected function addEmailHandler($params) {
    $this->drupalGet("admin/structure/webform/manage/civicrm_webform_test/handlers/add/email");
    if (!empty($params['to_mail'])) {
      $this->getSession()->getPage()->selectFieldOption('settings[to_mail][select]', $params['to_mail']);
    }

    $this->getSession()->getPage()->selectFieldOption('edit-settings-body', '_other_');
    $this->fillCKEditor('settings[body_custom_html][value]', $params['body']);
    $this->getSession()->getPage()->pressButton('Save');
  }

  /**
   * Returns the ID of the last submission on a given webform.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform entity object.
   *
   * @return int|null
   *   The ID of the last submission on the webform, or null if there are no
   *   submissions.
   */
  protected function getLastSubmissionId(WebformInterface $webform) {
    $submission_ids = \Drupal::entityQuery('webform_submission')
      ->accessCheck(TRUE)
      ->condition('webform_id', $webform->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    return reset($submission_ids);
  }

  /**
   * Can remove this once only testing 5.65+
   */
  protected function getCreditCardMonthFieldName(): string {
    if (version_compare(\CRM_Core_BAO_Domain::version(), '5.65.alpha1', '<')) {
      return 'credit_card_exp_date[M]';
    }
    return 'credit_card_exp_date[m]';
  }

}
