<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\Core\Test\AssertMailTrait;

/**
 * Tests submitting a Webform with CiviCRM: existing contact element.
 *
 * @group webform_civicrm
 */
final class ExistingContactElementTest extends WebformCivicrmTestBase {

  use AssertMailTrait;

  private function addcontactinfo() {
    $currentUserUF = $this->getUFMatchRecord($this->rootUser->id());
    $params = [
      'contact_id' => $currentUserUF['contact_id'],
      'first_name' => 'Maarten',
      'last_name' => 'van der Weijden',
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('Contact', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  public function testSubmitWebform() {

    $this->addcontactinfo();

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    // The Default Existing Contact Element behaviour is: load logged in User
    // The test here is to check if the fields on the form populate with Contact details belonging to the logged in User:
    $this->assertSession()->fieldValueEquals('First Name', 'Maarten');
    $this->assertSession()->fieldValueEquals('Last Name', 'van der Weijden');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }


  /**
   * Verify if existing contact element is loaded as expected.
   */
  function testRenderingOfExistingContactElement() {
    $this->addcontactinfo();
    $childContact = [
      'first_name' => 'Fred',
      'last_name' => 'Pinto',
    ];
    $childContactId = $this->createIndividual($childContact)['id'];
    $this->utils->wf_civicrm_api('Relationship', 'create', [
      'contact_id_a' => $childContactId,
      'contact_id_b' => $this->rootUserCid,
      'relationship_type_id' => "Child of",
    ]);

    $this->drupalLogin($this->rootUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->getPage()->selectFieldOption("number_of_contacts", 4);
    $this->htmlOutput();

    foreach ([2, 3, 4] as $c) {
      $this->getSession()->getPage()->clickLink("Contact {$c}");
      //Make second contact as household contact.
      if ($c == 2) {
        $this->getSession()->getPage()->selectFieldOption("{$c}_contact_type", 'Household');
        $this->assertSession()->assertWaitOnAjaxRequest();
      }
      elseif ($c == 3) {
        $this->getSession()->getPage()->checkField("edit-civicrm-{$c}-contact-1-contact-job-title");
        $this->assertSession()->checkboxChecked("edit-civicrm-{$c}-contact-1-contact-job-title");
      }
      $this->getSession()->getPage()->checkField("civicrm_{$c}_contact_1_contact_existing");
      $this->assertSession()->checkboxChecked("civicrm_{$c}_contact_1_contact_existing");
    }

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Edit contact element 1.
    $editContact = [
      'title' => 'Primary Contact',
      'selector' => 'edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations',
      'widget' => 'Static',
      'description' => 'Description of the static contact element.',
      'hide_fields' => 'Email',
    ];
    $this->editContactElement($editContact);

    // Edit contact element 2.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations',
      'widget' => 'Static',
    ];
    $this->editContactElement($editContact);

    // Edit contact element 3.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-3-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
    ];
    $this->editContactElement($editContact);

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Set a default value for Job title.
    $this->setDefaultValue('edit-webform-ui-elements-civicrm-3-contact-1-contact-job-title-operations', 'Accountant');

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Edit contact element 4.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-4-contact-1-contact-existing-operations',
      'widget' => 'Static',
      'default' => 'relationship',
      'default_relationship' => [
        'default_relationship_to' => 'Contact 3',
        'default_relationship' => 'Child of Contact 3',
      ],
    ];
    $this->editContactElement($editContact);

    // Visit the webform.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    // Check if static title is displayed.
    $this->assertSession()->pageTextContains('Primary Contact');
    $this->assertSession()->pageTextContains('Description of the static contact element');
    //Make sure email field is not loaded.
    $this->assertFalse($this->getSession()->getDriver()->isVisible($this->cssSelectToXpath('.form-type-email')));

    // Check if "None Found" text is present in the static element.
    $this->assertSession()->elementTextContains('css', '[id="edit-civicrm-2-contact-1-fieldset-fieldset"]', '- None Found -');

    // Check if c4 contains the text for "create new".
    $this->assertSession()->elementTextContains('css', '[id="edit-civicrm-4-contact-1-fieldset-fieldset"]', '+ Create new +');

    // Enter contact 3.
    $this->fillContactAutocomplete('token-input-edit-civicrm-3-contact-1-contact-existing', 'Maarten');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertFieldValue('edit-civicrm-3-contact-1-contact-job-title', 'Accountant');

    // Check if related contact is loaded on c4.
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '[id="edit-civicrm-4-contact-1-fieldset-fieldset"]', 'Fred Pinto');
  }

  /**
   * Check if autocomplete widget results is
   * searchable with all display field values.
   */
  public function testDisplayFields() {
    $this->createIndividual([
      'first_name' => 'James',
      'last_name' => 'Doe',
      'source' => 'Webform Testing',
    ]);

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->saveCiviCRMSettings();
    $this->drupalGet($this->webform->toUrl('edit-form'));

    // Edit contact element and add source to display fields.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
      'results_display' => ['display_name', 'source'],
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);

    // Search on first name and verify if the contact is selected.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->fillContactAutocomplete('token-input-edit-civicrm-1-contact-1-contact-existing', 'James');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertFieldValue('edit-civicrm-1-contact-1-contact-first-name', 'James');
    $this->assertFieldValue('edit-civicrm-1-contact-1-contact-last-name', 'Doe');

    // Search on source value and verify if the contact is selected.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->fillContactAutocomplete('token-input-edit-civicrm-1-contact-1-contact-existing', 'Webform Testing');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertFieldValue('edit-civicrm-1-contact-1-contact-first-name', 'James');
    $this->assertFieldValue('edit-civicrm-1-contact-1-contact-last-name', 'Doe');
  }

  /**
   * Test submission of hidden fields.
   */
  public function testHiddenField() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

     // Enable Email address
     $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
     $this->assertSession()->assertWaitOnAjaxRequest();
     $this->assertSession()->checkboxChecked("civicrm_1_contact_1_email_email");
     $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_email_location_type_id', 'Main');

     $this->saveCiviCRMSettings();
     $this->drupalGet($this->webform->toUrl('edit-form'));

    // Edit contact element and hide email field.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
      'hide_fields' => 'Email',
      'no_hide_blank' => TRUE,
      'submit_disabled' => TRUE,
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);

    $this->setDefaultValue('edit-webform-ui-elements-civicrm-1-contact-1-email-email-operations', 'email@example.com');

    $contact = $this->createIndividual();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->fillContactAutocomplete('token-input-edit-civicrm-1-contact-1-contact-existing', $contact['first_name']);

    //Ensure email field is not visible.
    $this->assertFalse($this->getSession()->getDriver()->isVisible($this->cssSelectToXpath('.form-type-email')));

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $result = $this->utils->wf_civicrm_api('Contact', 'get', [
      'first_name' => $contact['first_name'],
      'last_name' => $contact['last_name'],
      'email' => "email@example.com",
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    //Update contact email to something else.
    $this->utils->wf_civicrm_api('Email', 'create', [
      'contact_id' => $contact['id'],
      'email' => "updated_email@example.com",
      'is_primary' => 1,
    ]);

    // Load the webform.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->fillContactAutocomplete('token-input-edit-civicrm-1-contact-1-contact-existing', $contact['first_name']);
    $this->getSession()->wait(5000);
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Ensure existing contact email is not overwritten.
    $result = $this->utils->wf_civicrm_api('Contact', 'get', [
      'first_name' => $contact['first_name'],
      'last_name' => $contact['last_name'],
      'email' => "updated_email@example.com",
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * Test Tokens in Email.
   */
  public function testTokensInEmail() {
    // Create 2 meeting activities for the contact.
    $actID1 = $this->utils->wf_civicrm_api('Activity', 'create', [
      'source_contact_id' => $this->rootUserCid,
      'activity_type_id' => "Meeting",
      'target_id' => $this->rootUserCid,
    ])['id'];
    $actID2 = $this->utils->wf_civicrm_api('Activity', 'create', [
      'source_contact_id' => $this->rootUserCid,
      'activity_type_id' => "Meeting",
      'target_id' => $this->rootUserCid,
    ])['id'];

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Enable Email address
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_email_email");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_email_location_type_id', 'Main');

    // Enable Address fields.
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_address', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Country');
    $this->assertSession()->checkboxChecked('Country');

    $this->getSession()->getPage()->clickLink('Activities');
    $this->getSession()->getPage()->selectFieldOption('activity_number_of_activity', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->saveCiviCRMSettings();

    $email = [
      'to_mail' => '[webform_submission:values:civicrm_1_contact_1_email_email:raw]',
      'body' => 'Submitted Values Are - [webform_submission:values] Existing Contact - [webform_submission:values:civicrm_1_contact_1_contact_existing]. Activity 1 ID - [webform_submission:activity-id:1]. Activity 2 ID - [webform_submission:activity-id:2]. Webform CiviCRM Contacts IDs - [webform_submission:contact-id:1]. Webform CiviCRM Contacts Links - [webform_submission:contact-link:1] Country - [webform_submission:values:civicrm_1_contact_1_address_country_id]. State/Province - [webform_submission:values:civicrm_1_contact_1_address_state_province_id].',
    ];
    $this->addEmailHandler($email);
    $this->drupalGet($this->webform->toUrl('handlers'));
    // tabledrag results into a console js error, possibly a drupal core bug.
    // $civicrm_handler = $this->assertSession()->elementExists('css', '[data-webform-key="webform_civicrm"] a.tabledrag-handle');
    // Move up to be the top-most handler.
    // $this->sendKeyPress($civicrm_handler, 38);
    $this->getSession()->getPage()->pressButton('Save handlers');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['activity1' => $actID1, 'activity2' => $actID2]]));
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'frederick@pabst.io');

    $countryID = $this->utils->wf_civicrm_api4('Country', 'get', [
      'where' => [
        ['name', '=', 'United States'],
      ],
    ], 0)['id'];
    $stateProvinceID = $this->utils->wf_civicrm_api4('StateProvince', 'get', [
      'where' => [
        ['abbreviation', '=', 'NJ'],
        ['country_id', '=', $countryID],
      ],
    ], 0)['id'];
    $this->getSession()->getPage()->fillField('Street Address', '123 Milwaukee Ave');
    $this->getSession()->getPage()->fillField('City', 'Milwaukee');
    $this->getSession()->getPage()->fillField('Postal Code', '53177');
    $this->getSession()->getPage()->selectFieldOption('Country', $countryID);
    $this->getSession()->wait(1000);
    $this->getSession()->getPage()->selectFieldOption('State/Province', $stateProvinceID);

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $sent_email = $this->getMails();

    $cidURL = Url::fromUri('internal:/civicrm/contact/view', [
      'absolute' => TRUE,
      'query' => ['reset' => 1, 'cid' => $this->rootUserCid]
    ])->toString();
    // Check if email was sent to contact 1.
    $this->assertStringContainsString('frederick@pabst.io', $sent_email[0]['to']);

    // Something new in 10.3
    $weirdoExtraSpaces = version_compare(\Drupal::VERSION, '10.3.2', '>=') ? '  ' : '';
    // And now there is no longer a newline
    $weirdoNewline = version_compare(\Drupal::VERSION, '10.3.2', '<') ? "\n" : '';

    // Verify tokens are rendered correctly.
    $this->assertEquals("Submitted Values Are -

-------- Contact 1 {$weirdoNewline}-----------------------------------------------------------

*Existing Contact*
Frederick Pabst
*First Name*
Frederick
*Last Name*
Pabst
*Street Address*
123 Milwaukee Ave
*City*
Milwaukee
*Postal Code*
53177
*Country*
United States
*State/Province*
New Jersey
*Email*
frederick@pabst.io [1]
Existing Contact - Frederick Pabst. Activity 1 ID - {$actID1}. Activity 2 ID - {$actID2}.{$weirdoExtraSpaces}
Webform CiviCRM Contacts IDs - {$this->rootUserCid}. Webform CiviCRM Contacts Links -{$weirdoExtraSpaces}
{$cidURL} Country - United{$weirdoExtraSpaces}
States. State/Province - New Jersey.

[1] mailto:frederick@pabst.io
", $sent_email[0]['body']);
  }

}
