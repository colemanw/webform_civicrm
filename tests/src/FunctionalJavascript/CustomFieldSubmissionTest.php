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
    $params = [
      'title' => "Custom",
      'extends' => 'Individual',
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('CustomGroup', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $customgroup_id = $result['id'];

    $params = [
      'custom_group_id' => $customgroup_id,
      'label' => 'Text',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
    ];
    $result = $utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "DateTime",
      'data_type' => "Date",
      'html_type' => "Select Date",
      'date_format' => "yy-mm-dd",
      'time_format' => 2,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

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
    $optiongroup_id = $result['id'];

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
      'html_type' => "CheckBox",
      'data_type' => "String",
      'option_group_id' => "checkboxes_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => "Custom",
      'label' => "Label for custom radio field",
      'html_type' => "Radio",
      'data_type' => "String",
      'option_group_id' => "radio_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

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
      'html_type' => "Select",
      'data_type' => "String",
      'option_group_id' => "list_1",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * Test submitting Custom Fields
   */
  public function testSubmitWebform() {

    $this->createCustomFields();

    $this->drupalLogin($this->adminUser);
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

    $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_1");
    $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_2");
    $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_2_timepart");
    $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_3");
    $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_4");
    $this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_5");

    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_1");
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_2");
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_2_timepart");
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_3");
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_4");
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_5");

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    // Change the Checkbox -> no Listbox (that should probably be the default)
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->waitForField('Checkboxes');
    $this->htmlOutput();

    $this->enableCheckboxOnElement('edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-3-operations');
    $this->enableCheckboxOnElement('edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-4-operations');

    // ToDo: Enable Static Option and Edit Label
    // $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contact-1-cg1-custom-3-operations"] a.webform-ajax-link');
    // $checkbox_edit_button->click();
    // $this->assertSession()->assertWaitOnAjaxRequest();
    // $this->htmlOutput();
    // $this->getSession()->getPage()->selectFieldOption("properties[civicrm_live_options]", 0);
    // $this->assertSession()->assertWaitOnAjaxRequest();
    // $this->assertSession()->waitForField('properties[options][options][civicrm_option_1][label]');
    // $this->getSession()->getPage()->fillField('properties[options][options][civicrm_option_1][label]', 'Red - Recommended');
    // $this->htmlOutput();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');
    $this->assertSession()->pageTextContains('Label for custom radio field');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');

    $this->getSession()->getPage()->fillField('Text', 'Lorem Ipsum');

    // ToDo - Could not figure out how to use $this->getSession()->getPage()->fillField so using javascript instead
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->executeScript("document.getElementById('edit-civicrm-1-contact-1-cg1-custom-2').setAttribute('value', '2020-12-12')");
    $driver->executeScript("document.getElementById('edit-civicrm-1-contact-1-cg1-custom-2-timepart').setAttribute('value', '10:20:00')");

    // Only check one Checkbox -> Red
    $this->assertSession()->pageTextContains('Red');
    $this->getSession()->getPage()->checkField('Red');

    $this->getSession()->getPage()->checkField('Yes');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Note: custom fields are on contact_id=3 (1=default org; 2=the drupal user)
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('CustomValue', 'get', [
      'sequential' => 1,
      'entity_id' => 3,
    ]);
    $this->assertEquals(5, $api_result['count']);
    // throw new \Exception(var_export($api_result, TRUE));
    $this->assertEquals('Lorem Ipsum', $api_result['values'][0]['latest']);
    $this->assertEquals('2020-12-12 10:20:00', $api_result['values'][1]['latest']);
    // Check the checkbox values
    // Red = 1; Green = 2;
    $this->assertEquals(1, $api_result['values'][2]['latest']['0']);

    $result = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => "checkboxes_1",
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(2, $result['count']);

    $first_colour = [];
    foreach ($result['values'] as $value) {
      if ($value['value'] == $api_result['values'][2]['latest']['0']) {
        $first_colour = $value['name'];
      }
    }
    $this->assertEquals('Red', $first_colour);

    $this->assertEquals(1, $api_result['values'][3]['latest']);

    // For the Select List - the default is OptionA - Check that it's stored properly in CiviCRM:
    $this->assertEquals('OptionA', $api_result['values'][4]['latest']);

    // throw new \Exception(var_export($api_result, TRUE));
  }

}
