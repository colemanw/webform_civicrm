<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM and a single contact.
 *
 * @group webform_civicrm
 */
final class CustomFieldSubmissionTest extends WebformCivicrmTestBase {

  private function createCustomFields() {
    $params = [
      'title' => "Custom",
      'extends' => 'Individual',
    ];
    $result = \wf_civicrm_api('CustomGroup', 'create', $params);
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
    $result = \wf_civicrm_api('CustomField', 'create', $params);
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
    // throw new \Exception(var_export($result, TRUE));
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

    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_1");
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_2");
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_2_timepart");

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');

    $this->getSession()->getPage()->fillField('Text', 'Lorem Ipsum');
    // ToDo
    // $this->getSession()->getPage()->fillField('DateTime - date', '2020-12-12');
    $this->getSession()->getPage()->fillField('DateTime - date', '20170301');
    // $this->getSession()->getPage()->fillField('DateTime - time', '2017-03-01T20:02:00');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Note: custom fields are on contact_id=3 (1=default org; 2=the drupal user)
    $api_result = wf_civicrm_api('CustomValue', 'get', [
      'sequential' => 1,
      'entity_id' => 3,
    ]);
    $this->assertEquals(2, $api_result['count']);
    // throw new \Exception(var_export($api_result, TRUE));

    $this->assertEquals('Lorem Ipsum', $api_result['values'][0]['latest']);
    $this->assertEquals('2020-12-21', $api_result['values'][1]['latest']);
  }

}
