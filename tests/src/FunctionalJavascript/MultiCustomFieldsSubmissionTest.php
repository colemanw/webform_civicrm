<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: single contact + custom fields.
 *
 * @group webform_civicrm
 */
final class MultiCustomFieldsSubmissionTest extends WebformCivicrmTestBase {

  private function createMultiValueCustomFields() {
    $this->_customFields = [];
    $params = [
      'title' => "Monthly Data",
      'extends' => 'Contact',
      'is_multiple' => 1,
      'style' => "Tab with table",
    ];
    $result = $this->utils->wf_civicrm_api('CustomGroup', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_cgID = $result['id'];

    $params = [
      'custom_group_id' => $this->_cgID,
      'label' => 'Month',
      'name' => 'month',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
    ];
    $result = $this->utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields[current($result['values'])['name']] = $result['id'];

    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "data",
      'title' => "Data",
      'data_type' => "String",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "data",
      'name' => "100",
      'label' => "100",
      'value' => 100,
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "data",
      'name' => "200",
      'label' => "200",
      'value' => 200,
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => $this->_cgID,
      'label' => "Data",
      'name' => 'data',
      'html_type' => "Radio",
      'data_type' => "String",
      'option_group_id' => "data",
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
    $totalMV = 5;
    $this->createMultiValueCustomFields();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption("contact_1_number_of_cg{$this->_cgID}", $totalMV);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Enable custom fields.
    foreach ($this->_customFields as $id) {
      for ($i = 1; $i <= $totalMV; $i++) {
        $fldName = "civicrm_1_contact_{$i}_cg{$this->_cgID}_custom_{$id}";
        $this->getSession()->getPage()->checkField($fldName);
        $this->assertSession()->checkboxChecked($fldName);
      }
    }
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    //Enter values for the custom fields and save.
    $params = [
      'Month' => 'Jan',
      'Month 2' => 'Feb',
      'Month 3' => 'March',
      'Month 4' => 'April',
      'Month 5' => 'May',
    ];
    $data = [100, 200];
    for ($i = 1; $i <= $totalMV; $i++) {
      $params["civicrm_1_contact_{$i}_cg1_custom_2"] = $data[array_rand($data)];
    }
    $this->postSubmission($this->webform, $params);
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $this->verifyCustomValues($params, $totalMV);

    //Visit the webform again.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Verify if field values are loaded on the webform.
    foreach ($params as $key => $val) {
      if (strpos($key, 'Month') !== false) {
        $this->assertSession()->fieldValueEquals('Month', 'Jan');
      }
      else {
        $this->assertSession()->elementExists('css', '[name="' . $key . '"][value=' . $val . ']')->isChecked();
      }
    }

    //Update values for the custom fields and save.
    $params = [
      'Month' => 'JanEdited',
      'Month 2' => 'FebEdited',
      'Month 3' => 'MarchEdited',
      'Month 4' => 'AprilEdited',
      'Month 5' => 'MayEdited',
    ];
    $data = [100, 200];
    for ($i = 1; $i <= $totalMV; $i++) {
      $params["civicrm_1_contact_{$i}_cg1_custom_2"] = $data[array_rand($data)];
    }
    $this->postSubmission($this->webform, $params);
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Check if updated values are stored on the contact.
    $this->verifyCustomValues($params, $totalMV);
  }

  /**
   * Verify stored values.
   */
  private function verifyCustomValues($params, $totalMV) {
    $customValues = $this->utils->wf_civicrm_api('CustomValue', 'get', [
      'entity_id' => $this->rootUserCid,
    ])['values'];
    $monthValues = $customValues[$this->_customFields['month']];
    $dataValues = $customValues[$this->_customFields['data']];

    // Assert if submitted params are present in the custom values.
    $this->assertEquals($params['Month'], $monthValues[1]);
    for ($i = 1; $i <= $totalMV; $i++) {
      if ($i != 1) {
        $this->assertEquals($params["Month {$i}"], $monthValues[$i]);
      }
      $this->assertEquals($params["civicrm_1_contact_{$i}_cg1_custom_2"], $dataValues[$i]);
    }
  }

}