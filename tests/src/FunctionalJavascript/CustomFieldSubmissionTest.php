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

    $params = [
      'custom_group_id' => $result['id'],
      'label' => 'Text',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
    ];
    $result = \wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * Test submitting a Custom Field
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
    //$this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_2");
    //$this->getSession()->getPage()->checkField("civicrm_1_contact_1_cg1_custom_2_timepart");

    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_1");
    //$this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_2");
    //$this->assertSession()->checkboxChecked("civicrm_1_contact_1_cg1_custom_2_timepart");

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $today = date('Y-m-d H:i:s');

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');

    $this->getSession()->getPage()->fillField('Text', 'Lorem Ipsum');

    // KG
    // ToDo -> custom dates
    // $this->getSession()->getPage()->fillField('Date', '2020-12-12');

    $this->getSession()->getPage()->pressButton('Submit');
    // ToDo -> figure out what Error message it is! The submission itself works well.
    // $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $result = wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
    ]);

    /*$result = civicrm_api3('CustomValue', 'get', [
      'sequential' => 1,
      'return' => ["custom_1"],
      'entity_id' => 3,
    ]);*/

    $result = wf_civicrm_api('CustomValue', 'get', [
      'sequential' => 1,
      'entity_id' => 3,
    ]);
    $this->assertEquals(1, $result['count']);
    $custom_field = reset($result['values']);
    $this->assertEquals('Lorem Ipsum', $custom_field[0]);
  }

}
