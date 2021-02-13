<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
* Tests submitting a Webform with CiviCRM: single contact + custom fields.
*
* @group webform_civicrm
*/
final class ContactRelationshipTestAdd extends WebformCivicrmTestBase {

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

  private function createRelationshipType() {
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
    $this->createRelationshipType();

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

    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_existing");
    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_organization_name");
    $this->assertSession()->checkboxChecked("civicrm_2_contact_1_contact_organization_name");
    $this->getSession()->getPage()->selectFieldOption('contact_2_number_of_relationship', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_relationship_relationship_type_id[]', '- User Select -');

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    // View and Submit!
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->selectFieldOption('Relationship to Contact 1 Relationship Type(s)', 'School is');
    // $this->createScreenshot($this->htmlOutputDirectory . '/relationship_user_select.png');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }

}
