<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: single contact + custom fields.
 *
 * @group webform_civicrm
 */
final class ContactRelationshipTest extends WebformCivicrmTestBase {

  private function createContactSubtype() {
    $params = [
      'name' => "Student",
      'is_active' => 1,
      'parent_id' => "Individual",
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('ContactType', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  private function createRelationship() {
    $params = [
      'name_a_b' => "School is",
      'name_b_a' => "Student of",
      'contact_type_a' => "Organization",
      'contact_type_b' => "Individual",
      'contact_sub_type_b' => "Student",
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('\'RelationshipType', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * Test submitting Contact - Matching Rule
   */
  public function testSubmitWebform() {

    $this->createContactSubtype();
    $this->createRelationship();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->htmlOutput();

    $this->assertSession()->waitForText('Number of Contacts');
    $this->assertSession()->waitForField('number_of_contacts');
    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Configuring Contact 1 - Student
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_contact_sub_type[]', 'Student');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Configuring Contact 2 - School (Organization)
    $this->getSession()->getPage()->clickLink('2. Contact 2');
    $this->getSession()->getPage()->selectFieldOption('2_contact_type', 'Organization');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_organization_name");
    $this->assertSession()->checkboxChecked("civicrm_2_contact_1_contact_organization_name");
    $this->getSession()->getPage()->selectFieldOption('contact_2_number_of_relationship', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_relationship_relationship_type_id[]', 'School is');

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Organization Name', 'Western Canada High');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Note: Frederick is contact_id=3 (1=default org; 2=the drupal user) and Western Canada High tis contact_id=4
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'contact_id' => 3,
    ]);
    $contact = reset($api_result['values']);
    $this->assertEquals('Frederick', $contact['first_name']);
    $this->assertEquals('Pabst', $contact['last_name']);
    $this->assertEquals('Student', implode($contact['contact_sub_type']));

    // Check that the relationship is created:
    $api_result = $utils->wf_civicrm_api('Relationship', 'get', [
      'sequential' => 1,
      'contact_id_b' => 3,
    ]);
    $relationship = reset($api_result['values']);
    // throw new \Exception(var_export($relationship, TRUE));

    // This is the school:
    $api_result = $utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'contact_id' => $relationship['contact_id_a'],
    ]);
    $contact = reset($api_result['values']);
    $this->assertEquals('Western Canada High', $contact['organization_name']);

    // This is the relationship type:
    $api_result = $utils->wf_civicrm_api('RelationshipType', 'get', [
      'sequential' => 1,
      'id' =>  $relationship['relationship_type_id'],
    ]);
    // throw new \Exception(var_export($api_result, TRUE));

    $relationshipType = reset($api_result['values']);
    // throw new \Exception(var_export($relationshipType, TRUE));

    $this->assertEquals('Student of', $relationshipType['label_b_a']);
  }

}
