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
    $result = $this->utils->wf_civicrm_api('ContactType', 'create', $params);
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
    $result = $this->utils->wf_civicrm_api('\'RelationshipType', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * Test removal of relationships.
   */
  public function testRelationshipRemoval() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->htmlOutput();

    $this->getSession()->getPage()->clickLink('2. Contact 2');
    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_existing");

    $this->getSession()->getPage()->selectFieldOption('contact_2_number_of_relationship', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_relationship_relationship_type_id[]', 'create_civicrm_webform_element');

    $this->createScreenshot($this->htmlOutputDirectory . '/adminscreen.png');
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_first_name', 'Frederick');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_last_name', 'Pabst');

    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_first_name', 'Mark');
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_last_name', 'Anthony');

    $this->getSession()->getPage()->checkField("Child of");
    $this->getSession()->getPage()->checkField("Partner of");

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    //Assert if relationship was created.
    $contact1 = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ]);
    $this->assertEquals(1, $contact1['count']);

    $contact2 = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'first_name' => 'Mark',
      'last_name' => 'Anthony',
    ]);
    $this->assertEquals(1, $contact2['count']);

    $contact1 = reset($contact1['values']);
    $contact2 = reset($contact2['values']);

    $relationships = $this->utils->wf_civicrm_api('Relationship', 'get', [
      'sequential' => 1,
      'contact_id_b' => $contact1['contact_id'],
      'is_active' => 1,
    ]);

    //Check only 2 relationships are created b/w the contacts.
    $this->assertEquals(2, $relationships['count']);
    $relationships = $relationships['values'];
    $partnerTypeID = $this->utils->wf_civicrm_api('RelationshipType', 'getvalue', [
      'return' => "id",
      'name_a_b' => "Partner of",
    ]);
    $childTypeID = $this->utils->wf_civicrm_api('RelationshipType', 'getvalue', [
      'return' => "id",
      'name_a_b' => "Child of",
    ]);
    $contactRelTypes = array_column($relationships, 'relationship_type_id');

    $this->assertEquals($contact2['id'], $relationships[0]['contact_id_a']);
    $this->assertEquals($contact2['id'], $relationships[1]['contact_id_a']);
    $this->assertTrue(in_array($childTypeID, $contactRelTypes));
    $this->assertTrue(in_array($partnerTypeID, $contactRelTypes));

    // Visit the webform with cid2 id in the url.
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contact1['id'], 'cid2' => $contact2['id']]]));
    $this->assertSession()->waitForField('First Name');
    // $this->createScreenshot($this->htmlOutputDirectory . '/relationship_selection.png');

    //Make sure the checkbox are enabled by default.
    $this->assertSession()->checkboxChecked("Child of");
    $this->assertSession()->checkboxChecked("Partner of");

    // Remove Partner of relationship with the contact.
    $this->getSession()->getPage()->uncheckField("Partner of");

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $relationships = $this->utils->wf_civicrm_api('Relationship', 'get', [
      'sequential' => 1,
      'contact_id_b' => $contact1['contact_id'],
      'options' => ['sort' => "is_active DESC"],
    ]);

    $this->assertEquals(2, $relationships['count']);
    $relationships = $relationships['values'];

    $this->assertEquals($contact2['id'], $relationships[0]['contact_id_a']);
    $this->assertEquals($childTypeID, $relationships[0]['relationship_type_id']);
    $this->assertEquals(1, $relationships[0]['is_active']);

    // Check if Partner relationship is expired.
    $this->assertEquals($contact2['id'], $relationships[1]['contact_id_a']);
    $this->assertEquals($partnerTypeID, $relationships[1]['relationship_type_id']);
    $this->assertEquals(0, $relationships[1]['is_active']);

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
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
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
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_relationship_relationship_type_id[]', 'School is');

    $this->saveCiviCRMSettings();

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
    $api_result = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ]);
    $this->assertEquals(1, $api_result['count']);
    $student = reset($api_result['values']);
    $this->assertEquals('Student', implode($student['contact_sub_type']));

    // Check that the relationship is created:
    $api_result = $this->utils->wf_civicrm_api('Relationship', 'get', [
      'sequential' => 1,
      'contact_id_b' => $student['contact_id'],
    ]);
    $relationship = reset($api_result['values']);
    // throw new \Exception(var_export($relationship, TRUE));

    // This is the school:
    $api_result = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'contact_id' => $relationship['contact_id_a'],
    ]);
    $contact = reset($api_result['values']);
    $this->assertEquals('Western Canada High', $contact['organization_name']);

    // This is the relationship type:
    $api_result = $this->utils->wf_civicrm_api('RelationshipType', 'get', [
      'sequential' => 1,
      'id' =>  $relationship['relationship_type_id'],
    ]);
    // throw new \Exception(var_export($api_result, TRUE));

    $relationshipType = reset($api_result['values']);
    // throw new \Exception(var_export($relationshipType, TRUE));

    $this->assertEquals('Student of', $relationshipType['label_b_a']);

    $this->drupalLogin($this->adminUser);
    // Edit Contact Element and enable select widget.
    $this->drupalGet($this->webform->toUrl('edit-form'));

    $contactElementEdit = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations"] a.webform-ajax-link');
    $contactElementEdit->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-contact-defaults"]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('Set default contact from', 'Relationship to...');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $loadedRelationshipTypes = $this->getOptions('Specify Relationship(s)');
    $type = array_search('School is Contact 1', $loadedRelationshipTypes);
    $this->getSession()->getPage()->selectFieldOption('Specify Relationship(s)', $type);
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $student['contact_id']]]));
    $this->assertPageNoErrorMessages();

    // Check if School name is pre-populated.
    $this->assertSession()->fieldValueEquals('Organization Name', 'Western Canada High');
  }

  /**
   * Verify default relationship load on contact subtypes.
   */
  function testSubTypeRelationship() {
    // Create subtype 1
    $subType1 = $this->utils->wf_civicrm_api('ContactType', 'create', [
      'parent_id' => "Organization",
      'name' => "Team",
    ]);
    // Create subtype 2
    $subType2 = $this->utils->wf_civicrm_api('ContactType', 'create', [
      'parent_id' => "Organization",
      'name' => "Sponsor",
    ]);

    // Create Relationship Type
    $relType = $this->utils->wf_civicrm_api('RelationshipType', 'create', [
      'name_a_b' => "Test Relationship",
      'name_b_a' => "Test Relationship",
      'contact_type_a' => "Organization",
      'contact_type_b' => "Organization",
      'contact_sub_type_a' => "Team",
      'contact_sub_type_b' => "Sponsor",
    ]);

    // Create 3 organization contacts and relate to each other.
    $teamOrg1 = $this->utils->wf_civicrm_api('Contact', 'create', [
      'contact_type' => "Organization",
      'contact_sub_type' => "Team",
      'organization_name' => "Team Org1",
    ]);
    $teamOrg2 = $this->utils->wf_civicrm_api('Contact', 'create', [
      'contact_type' => "Organization",
      'contact_sub_type' => "Team",
      'organization_name' => "Team Org2",
    ]);
    $sponsorOrg1 = $this->utils->wf_civicrm_api('Contact', 'create', [
      'contact_type' => "Organization",
      'contact_sub_type' => "Sponsor",
      'organization_name' => "Sponsor Org",
    ]);
    foreach ([$teamOrg1['id'], $teamOrg2['id']] as $teamID) {
      $result = $this->utils->wf_civicrm_api('Relationship', 'create', [
        'contact_id_a' => $teamID,
        'contact_id_b' => $sponsorOrg1['id'],
        'relationship_type_id' => "Test Relationship",
      ]);
    }

    // Create webform with 3 organization contacts.
    $this->drupalLogin($this->rootUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption("number_of_contacts", 3);
    $this->htmlOutput();
    foreach ([1, 2, 3] as $c) {
      $this->getSession()->getPage()->clickLink("Contact {$c}");
      $this->getSession()->getPage()->selectFieldOption("{$c}_contact_type", 'Organization');
      $this->assertSession()->assertWaitOnAjaxRequest();

      $subType = $c == 2 ? 'Sponsor' : 'Team';
      $this->getSession()->getPage()->selectFieldOption("civicrm_{$c}_contact_1_contact_contact_sub_type[]", $subType);
      $this->assertSession()->assertWaitOnAjaxRequest();

      if ($c > 1) {
        $this->getSession()->getPage()->checkField("civicrm_{$c}_contact_1_contact_existing");
        $this->assertSession()->checkboxChecked("civicrm_{$c}_contact_1_contact_existing");
      }
      $this->getSession()->getPage()->checkField("civicrm_{$c}_contact_1_contact_organization_name");
      $this->assertSession()->checkboxChecked("civicrm_{$c}_contact_1_contact_organization_name");
    }
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Edit contact element 2.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations',
      'widget' => 'Static',
      'default' => 'relationship',
      'default_relationship' => [
        'default_relationship_to' => 'Contact 1',
        'default_relationship' => 'Test Relationship Contact 1',
      ],
      'filter' => [
        'filter_relationship_types' => 'Test Relationship Contact 1'
      ],
    ];
    $this->editContactElement($editContact);

    // Edit contact element 3.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-3-contact-1-contact-existing-operations',
      'widget' => 'Static',
      'default' => 'relationship',
      'default_relationship' => [
        'default_relationship_to' => 'Contact 2',
        'default_relationship' => 'Test Relationship Contact 2',
      ],
    ];
    $this->editContactElement($editContact);

    // Check if related contacts are loaded on the webform.
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $teamOrg1['id']]]));
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    $this->assertSession()->fieldValueEquals('civicrm_2_contact_1_contact_organization_name', 'Sponsor Org');
    $this->assertSession()->fieldValueEquals('civicrm_3_contact_1_contact_organization_name', 'Team Org2');
  }

}
