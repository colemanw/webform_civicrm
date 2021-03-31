<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with CiviCRM: single contact + custom fields.
 *
 * @group webform_civicrm
 */
final class CustomFieldSubmissionTest extends WebformCivicrmTestBase {

  private function createCustomFields() {
    $this->_customFields = [];
    $params = [
      'title' => "Custom",
      'extends' => 'Individual',
    ];
    $result = $this->utils->wf_civicrm_api('CustomGroup', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $customgroup_id = $result['id'];

    $params = [
      'custom_group_id' => $customgroup_id,
      'label' => 'Text',
      'name' => 'text',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
    ];
    $result = $this->utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields[current($result['values'])['name']] = $result['id'];

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "DateTime",
      'name' => 'DateTime',
      'data_type' => "Date",
      'html_type' => "Select Date",
      'date_format' => "yy-mm-dd",
      'time_format' => 2,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields[current($result['values'])['name']] = $result['id'];

    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "radio_1",
      'title' => "Label for custom radio field",
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
      'label' => "Checkboxes",
      'name' => 'color_checkboxes',
      'html_type' => "CheckBox",
      'data_type' => "String",
      'option_group_id' => "checkboxes_1",
      'is_active' => 1,
    ]);

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields[current($result['values'])['name']] = $result['id'];

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
      'label' => "Fruits",
      'name' => 'fruits',
      'html_type' => "CheckBox",
      'data_type' => "String",
      'option_group_id' => "fruits_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields[current($result['values'])['name']] = $result['id'];

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for custom radio field",
      'name' => 'single_radio',
      'html_type' => "Radio",
      'data_type' => "String",
      'option_group_id' => "radio_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields[current($result['values'])['name']] = $result['id'];

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
      'is_default' => 0,
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
      'label' => "List",
      'name' => 'select_list',
      'html_type' => "Select",
      'data_type' => "String",
      'option_group_id' => "list_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields[current($result['values'])['name']] = $result['id'];
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
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');

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
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption("properties[civicrm_live_options]", 0);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForField('properties[options][options][civicrm_option_1][label]');
    $this->getSession()->getPage()->fillField('properties[options][options][civicrm_option_1][label]', 'Red - Recommended');
    $this->htmlOutput();
    $this->createScreenshot($this->htmlOutputDirectory . '/afterlabelchange.png');
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
    $this->assertEquals('2020-12-12 10:20:00', $api_result['values'][$this->_customFields['DateTime']]['latest']);
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
  }

}
