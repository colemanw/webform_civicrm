<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: single contact + custom fields.
 *
 * @group webform_civicrm
 */
final class CustomFieldSubmissionTest extends WebformCivicrmTestBase {

  /**
   * @var array
   */
  private $_customFields;

  private function createCustomFields() {
    $this->_customFields = [];
    $result = $this->createCustomGroup();
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $customgroup_id = $result['id'];

    $params = [
      'custom_group_id' => $customgroup_id,
      'label' => 'Label for Custom Text field',
      'name' => 'text',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
    ];
    $result = $this->utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['text'] = $result['id'];

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for Custom DateTime field",
      'name' => 'date_time',
      'data_type' => "Date",
      'html_type' => "Select Date",
      'date_format' => "yy-mm-dd",
      'time_format' => 2,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['date_time'] = $result['id'];

    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "radio_1",
      'title' => "Label for Custom radio field",
      'data_type' => "String",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "radio_1",
      'name' => "Yes",
      'label' => "Yes",
      'value' => 1,
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    // Add Radio options for empty submission.
    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "test_radio_2",
      'title' => "Test Radio 2",
      'data_type' => "String",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "test_radio_2",
      'name' => "radiooptionone",
      'label' => "Radio Option One",
      'value' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "test_radio_2",
      'name' => "radiooptiontwo",
      'label' => "Radio Option Two",
      'value' => 2,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "test_radio_2",
      'name' => "radiooptionthree",
      'label' => "Radio Option Three",
      'value' => 3,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for Custom EmptyRadio field",
      'name' => 'test_radio_2',
      'html_type' => "Radio",
      'data_type' => "String",
      'option_group_id' => "test_radio_2",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['test_radio_2'] = $result['id'];

    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "checkboxes_1",
      'title' => "Checkboxes",
      'data_type' => "String",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "checkboxes_1",
      'name' => "Red",
      'label' => "Red",
      'value' => 1,
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "checkboxes_1",
      'name' => "Green",
      'label' => "Green",
      'value' => 2,
      'is_default' => 0,
      'weight' => 2,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for Custom Checkbox field",
      'name' => 'color_checkboxes',
      'html_type' => "CheckBox",
      'data_type' => "String",
      'option_group_id' => "checkboxes_1",
      'is_active' => 1,
    ]);

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['color_checkboxes'] = $result['id'];

    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "fruits_1",
      'title' => "Fruits",
      'data_type' => "String",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "fruits_1",
      'name' => "Apple",
      'label' => "Apple",
      'value' => "Apple",
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "fruits_1",
      'name' => "Mango",
      'label' => "Mango",
      'value' => "Mango",
      'is_default' => 0,
      'weight' => 2,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "fruits_1",
      'name' => "Orange",
      'label' => "Orange",
      'value' => "Orange",
      'is_default' => 0,
      'weight' => 3,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for Custom Fruit Checkbox field",
      'name' => 'fruits',
      'html_type' => "CheckBox",
      'data_type' => "String",
      'option_group_id' => "fruits_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['fruits'] = $result['id'];

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for Custom Radio field",
      'name' => 'single_radio',
      'html_type' => "Radio",
      'data_type' => "String",
      'option_group_id' => "radio_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['single_radio'] = $result['id'];

    // Add OptionGroup for Select element
    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "list_1",
      'title' => "Select",
      'data_type' => "String",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    // Add First OptionValue to OptionGroup for Select element
    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "list_1",
      'name' => "Option A",
      'label' => "Option A",
      'value' => 'OptionA',
      'is_default' => 1,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    // Add Second OptionValue to OptionGroup for Select element
    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "list_1",
      'name' => "Option B",
      'label' => "Option B",
      'value' => 'OptionB',
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    // Add Field of type Select
    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for Custom List field",
      'name' => 'select_list',
      'html_type' => "Select",
      'data_type' => "String",
      'default_value' => 'OptionA',
      'option_group_id' => "list_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['select_list'] = $result['id'];
  }

  /**
   * Test dynamic custom fields.
   */
  public function testDynamicCustomFields() {
    drupal_flush_all_caches();
    $this->createCustomFields();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_cg1', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField("contact_1_settings_dynamic_custom_cg1");
    $this->assertSession()->checkboxChecked("contact_1_settings_dynamic_custom_cg1");

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    $customFieldLabels = [
      'Label for Custom Text field',
      'Label for Custom DateTime field',
      'Label for Custom EmptyRadio field',
      'Label for Custom Checkbox field',
      'Label for Custom Fruit Checkbox field',
      'Label for Custom Radio field',
      'Label for Custom List field',
    ];
    foreach ($customFieldLabels as $label) {
      $this->assertSession()->pageTextContains($label);
    }
    //Disable Custom field.
    $fieldURL = Url::fromUri('internal:/civicrm/admin/custom/group/field/update', [
      'absolute' => TRUE,
      'query' => ['reset' => 1, 'action' => 'update', 'gid' => 1, 'id' => $this->_customFields['color_checkboxes']]
    ])->toString();
    $this->drupalGet($fieldURL);
    $this->getSession()->getPage()->uncheckField(version_compare(\CRM_Core_BAO_Domain::version(), '5.75.alpha1', '<') ? 'Active?' : 'Active');
    // $this->createScreenshot($this->htmlOutputDirectory . '/custom_field.png');
    $this->getSession()->getPage()->pressButton('_qf_Field_done-bottom');

    //Reload the webform page - the custom field should be removed.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Verify if the custom field is removed from the page.
    $this->assertSession()->pageTextNotContains('Label for Custom Checkbox field');

    //Re-enable the field.
    $this->drupalGet($fieldURL);
    $this->getSession()->getPage()->checkField(version_compare(\CRM_Core_BAO_Domain::version(), '5.75.alpha1', '<') ? 'Active?' : 'Active');
    $this->getSession()->getPage()->pressButton('_qf_Field_done-bottom');

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Verify if the custom field is back on the page.
    $this->assertSession()->pageTextContains('Label for Custom Checkbox field');

    //Change single radio to static.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Enable static option on radio field.
    $this->editCivicrmOptionElement("edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-{$this->_customFields['single_radio']}-operations", FALSE, TRUE);

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();
    $this->assertSession()->checkboxNotChecked("Yes");

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }

  /**
   * Test submitting Custom Fields
   */
  public function testSubmitWebform() {

    $this->createCustomFields();

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_cg1', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Enable custom fields.
    foreach ($this->_customFields as $name => $id) {
      $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_{$id}");
      $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_{$id}");
    }
    $this->saveCiviCRMSettings();

    // Change the Checkbox -> no Listbox (that is now the default - so this may not be required anymore)
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->waitForField('Checkboxes');
    $this->htmlOutput();
    // Enable static option on radio field.
    $this->editCivicrmOptionElement("edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-{$this->_customFields['single_radio']}-operations", FALSE, TRUE);

    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-' . $this->_customFields['color_checkboxes'] . '-operations"] a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->assertSession()->waitForField('drupal-off-canvas');
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption("properties[civicrm_live_options]", 0);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForField('properties[options][options][civicrm_option_1][label]');
    $this->getSession()->getPage()->fillField('properties[options][options][civicrm_option_1][label]', 'Red - Recommended');
    $this->htmlOutput();
    // $this->createScreenshot($this->htmlOutputDirectory . '/afterlabelchange.png');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');
    $this->assertSession()->pageTextContains('Label for custom radio field');

    $params = [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ];
    $this->getSession()->getPage()->fillField('First Name', $params['first_name']);
    $this->getSession()->getPage()->fillField('Last Name', $params['last_name']);

    $this->getSession()->getPage()->fillField('Text', 'Lorem Ipsum');

    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_cg1_custom_2[date]', '12-12-2020');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_cg1_custom_2[time]', '10:20:00');

    // Only check one Checkbox -> Red
    $this->assertSession()->pageTextContains('Red - Recommended');
    $this->getSession()->getPage()->checkField('Red - Recommended');

    $this->getSession()->getPage()->checkField('Apple');
    $this->getSession()->getPage()->checkField('Orange');
    $this->getSession()->getPage()->checkField('Yes');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Note: custom fields are on contact_id=3 (1=default org; 2=the drupal user)
    $contactID = $this->utils->wf_civicrm_api('Contact', 'get', $params)['id'];
    $api_result = $this->utils->wf_civicrm_api('CustomValue', 'get', [
      'entity_id' => $contactID,
    ]);
    $this->assertEquals(count($this->_customFields), $api_result['count']);
    $this->assertEquals('Lorem Ipsum', $api_result['values'][$this->_customFields['text']]['latest']);
    $this->assertEquals('2020-12-12 10:20:00', $api_result['values'][$this->_customFields['date_time']]['latest']);
    // Check the checkbox values
    // Red = 1; Green = 2;
    $this->assertEquals(1, $api_result['values'][$this->_customFields['color_checkboxes']]['latest']['0']);

    $result = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => "checkboxes_1",
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(2, $result['count']);

    $first_colour = [];
    foreach ($result['values'] as $value) {
      if ($value['value'] == $api_result['values'][$this->_customFields['color_checkboxes']]['latest']['0']) {
        $first_colour = $value['name'];
      }
    }
    $this->assertEquals('Red', $first_colour);

    $this->assertEquals(1, $api_result['values'][$this->_customFields['single_radio']]['latest']);

    // For the Select List - the default is OptionA - Check that it's stored properly in CiviCRM:
    $this->assertEquals('OptionA', $api_result['values'][$this->_customFields['select_list']]['latest']);
    $fruitVal = $api_result['values'][$this->_customFields['fruits']]['latest'];

    // Check the fruit situation
    $this->assertCount(2, $fruitVal);
    $this->assertArrayHasKey('Apple', array_flip($fruitVal));
    $this->assertArrayHasKey('Orange', array_flip($fruitVal));
    $this->assertArrayNotHasKey('Mango', array_flip($fruitVal));

    // Ensure the element is still accessible.
    $this->drupalLogin($this->rootUser);

    // Delete Custom field options.
    $listOptions = civicrm_api3('OptionValue', 'get', [
      'sequential' => 1,
      'option_group_id' => "list_1",
    ]);
    foreach ($listOptions['values'] as $val) {
      $result = civicrm_api3('OptionValue', 'delete', [
        'id' => $val['id'],
      ]);
    }

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->waitForField('Checkboxes');
    $this->htmlOutput();

    $checkbox_edit_button = $this->assertSession()->elementExists('css', "[data-drupal-selector='edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-{$this->_customFields['select_list']}-operations'] a.webform-ajax-link");
    $checkbox_edit_button->click();
    $this->assertSession()->waitForField('drupal-off-canvas');
    $this->htmlOutput();

    $this->assertSession()->elementExists('css', ".empty.message");
    $this->assertSession()->elementTextContains('css', "[data-drupal-selector='edit-properties-options-options']", 'Nothing');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Ensure webform default values are loaded when the contact
   * in civicrm does not have a value set on it.
   */
  public function testCustomFieldWebformDefaults() {
    $this->createCustomFields();
    $createParams = [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
      'custom_' . $this->_customFields['text'] => 'Lorem Ipsum',
    ];
    $contactID = $this->createIndividual($createParams)['id'];

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_cg1', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Enable custom fields.
    foreach ($this->_customFields as $name => $id) {
      $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_{$id}");
      $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_{$id}");
    }
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));

    $this->setDefaultValue("edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-{$this->_customFields['test_radio_2']}-operations", 3);
    $this->setDefaultValue("edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-{$this->_customFields['color_checkboxes']}-operations", 2);
    $this->setDefaultValue("edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-{$this->_customFields['fruits']}-operations", 'Mango, Orange');

    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contactID]]));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Ensure default values are loaded.
    $this->assertFieldValue("edit-civicrm-1-contact-1-cg1-custom-{$this->_customFields['text']}", 'Lorem Ipsum');

    // This is loaded from webform default since no value is set in civi.
    $this->assertSession()->checkboxNotChecked("Red");
    $this->assertSession()->checkboxChecked("Green");

    $this->assertSession()->checkboxChecked("Mango");
    $this->assertSession()->checkboxChecked("Orange");
    $this->assertSession()->checkboxNotChecked("Apple");
  }

  /**
   * Test Contact Values loaded via ajax, i.e,
   * on selecting a contact from autocomplete, select, etc.
   */
  public function testAjaxLoadOfContactValues() {
    $this->createCustomFields();
    $createParams = [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
      'custom_' . $this->_customFields['text'] => 'Lorem Ipsum',
      'custom_' . $this->_customFields['date_time'] => '12-12-2020 10:20',
      'custom_' . $this->_customFields['test_radio_2'] => 'Radio Option Two',
      'custom_' . $this->_customFields['color_checkboxes'] => 'Red',
      'custom_' . $this->_customFields['fruits'] => ['Mango', 'Orange'],
      'custom_' . $this->_customFields['single_radio'] => 1,
      'custom_' . $this->_customFields['select_list'] => 'OptionB',
    ];
    $this->createIndividual($createParams);

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_cg1', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Enable custom fields.
    foreach ($this->_customFields as $name => $id) {
      $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_{$id}");
      $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_{$id}");
    }
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));

    //Change contact element to autocomplete + remove default load.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);

    //Visit the webform.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    $this->fillContactAutocomplete('token-input-edit-civicrm-1-contact-1-contact-existing', 'Frederick');

    $this->htmlOutput();

    // Ensure all fields are loaded correctly.
    $this->verifyDefaults();
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $params = [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ];
    $contactID = $this->utils->wf_civicrm_api('Contact', 'get', $params)['id'];

    $this->utils->wf_civicrm_api('Contact', 'create', [
      'id' => $contactID,
      "custom_{$this->_customFields['select_list']}" => "",
    ]);
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contactID]]));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Select OptionB and submit the webform
    $this->getSession()->getPage()->selectFieldOption("civicrm_1_contact_1_cg1_custom_{$this->_customFields['select_list']}", 'OptionB');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contactID]]));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();
    $this->verifyDefaults();
  }

  public function verifyDefaults() {
    $this->assertFieldValue('edit-civicrm-1-contact-1-contact-first-name', 'Frederick');
    $this->assertFieldValue('edit-civicrm-1-contact-1-contact-last-name', 'Pabst');
    $this->assertFieldValue("edit-civicrm-1-contact-1-cg1-custom-{$this->_customFields['text']}", 'Lorem Ipsum');
    $this->assertFieldValue("edit-civicrm-1-contact-1-cg1-custom-{$this->_customFields['date_time']}-date", '2020-12-12');
    $this->assertFieldValue("edit-civicrm-1-contact-1-cg1-custom-{$this->_customFields['date_time']}-time", '10:20');
    $this->assertSession()->checkboxChecked("Red");
    $this->assertSession()->checkboxChecked("Mango");
    $this->assertSession()->checkboxChecked("Orange");
    $this->assertSession()->checkboxNotChecked("Apple");
    $this->assertSession()->checkboxNotChecked("Green");

    $this->assertFieldValue("edit-civicrm-1-contact-1-cg1-custom-{$this->_customFields['select_list']}", 'OptionB');
    $this->assertFieldValue("civicrm_1_contact_1_cg1_custom_3", 2, TRUE);
    $this->assertFieldValue("civicrm_1_contact_1_cg1_custom_{$this->_customFields['single_radio']}", 1, TRUE);
  }

}
