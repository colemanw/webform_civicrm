<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\Entity\Webform;
use Drupal\Core\Serialization\Yaml;

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
    // We ignore newlines so that the length of the website's URL (appearing in
    // $this->cidURL) doesn't cause a failure due to variations in line
    // wrapping.
    $this->assertEquals(strtr("Submitted Values Are -

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
", "\n", ' '), strtr($sent_email[0]['body'], "\n", ' '));
  }

  /**
   *  Define test-contact parameters and create a subset of them in Civi.
   *
   *  @return array
   *    contains parameter arrays for each test-contact
   */
  private function addcontactinfo2() {
    $contact = [
      0 => [ // cid = 3 (will overwrite existing contact)
        'contact_id' => 3,
        'first_name' => 'Jimmy',
        'last_name' => 'Page',
        'job_title' => "Guitarist",
        'contact_type' => 'Individual'
      ],
      1 => [ // cid = 4
        'first_name' => 'Robert',
        'last_name' => 'Plant',
        'job_title' => "Vocalist",
        'contact_type' => 'Individual'
      ],
      2 => [ // cid = 5
        'first_name' => 'John Paul',
        'last_name' => 'Jones',
        'job_title' => "Bassist",
        'contact_type' => 'Individual'
      ],
      3 => [ // cid = 6
        'first_name' => 'John',
        'last_name' => 'Bonham',
        'job_title' => "Drummer",
        'contact_type' => 'Individual'
      ],
      4 => [ // cid = 7
        'first_name' => 'Janis',
        'last_name' => 'Joplin',
        'job_title' => "Singer",
        'contact_type' => 'Individual'
      ],
      5 => [ // not initiallly created
        'first_name' => 'Marvin',
        'last_name' => 'Gaye',
        'job_title' => "Vocals",
        'contact_type' => 'Individual'
      ],
      6 => [ // not initiallly created
        'first_name' => 'Bob',
        'last_name' => 'Dylan',
        'job_title' => "Vocals, Harmonica",
        'contact_type' => 'Individual'
      ],
      7 => [ // null contact, not initiallly created
        'first_name' => '',
        'last_name' => '',
        'job_title' => '',
        'contact_type' => 'Individual'
      ],
      8 => [ // cid = 8
        'first_name' => 'Prince',
        'last_name' => '',
        'job_title' => "Guitar, vocals",
        'contact_type' => 'Individual'
      ],
      9 => [ // cid = 9
        'first_name' => 'Madona',
        'last_name' => '',
        'job_title' => "Vocals, drummer",
        'contact_type' => 'Individual'
      ],
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    foreach ($contact as $key => $c) {
      if (in_array($key, [0, 1, 2, 3, 4, 8, 9])) {
        $result = $utils->wf_civicrm_api('Contact', 'create', $c);
        $this->assertEquals(0, $result['is_error']);
        $this->assertEquals(1, $result['count']);
      }
    }
    return $contact;
  }

  /**
   * Sets the contact fields used by testNextPrevSaveLoad()
   *
   * @param array $contact
   *   contact parameters to be set
   */
  private function setContactFields($contact) {
    $this->getSession()->getPage()->fillField('First Name', $contact['first_name']);
    $this->getSession()->getPage()->fillField('Last Name',  $contact['last_name']);
    $this->getSession()->getPage()->fillField('Job Title',  $contact['job_title']);
  }

  /**
   * Checks the contact fields used by testNextPrevSaveLoad()
   *
   * @param array $contact
   *   contact parameters to be checked
   */
  private function checkContactFields($contact) {
    $this->assertSession()->fieldValueEquals('First Name', $contact['first_name']);
    $this->assertSession()->fieldValueEquals('Last Name',  $contact['last_name']);
    $this->assertSession()->fieldValueEquals('Job Title',  $contact['job_title']);
  }

  /**
  * Test locked/unlocked and blank/filled fields during Next/Previous/Save Draft/Load Draft/Submit operations
  */
  public function testNextPrevSaveLoad() {
    if (version_compare(\Drupal::VERSION, '10.3', '>=')) {
      $this->markTestSkipped('retrieving $elements gives blank in 10.3 for some reason');
      return;
    }

    $contact = $this->addcontactinfo2();

    $this->drupalLogin($this->rootUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Enable 3 contacts each with first name, last name, job title
    $this->getSession()->getPage()->selectFieldOption("number_of_contacts", 3);
    foreach ([1, 2, 3] as $c) {
      $this->getSession()->getPage()->clickLink("Contact {$c}");
      $this->getSession()->getPage()->checkField("civicrm_{$c}_contact_1_contact_existing");
      $this->assertSession()->checkboxChecked("civicrm_{$c}_contact_1_contact_existing");
      $this->getSession()->getPage()->checkField("civicrm_{$c}_contact_1_contact_job_title");
      $this->assertSession()->checkboxChecked("civicrm_{$c}_contact_1_contact_job_title");
    }

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));

    // Edit contact element 1.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations',
      'title' => 'Contact 1',
      'widget' => 'Select List',
      'hide_fields' => 'Name',
      'hide_method' => 'Disabled',
      'no_hide_blank' => TRUE,
      'submit_disabled' => TRUE,
      'default' => 'Specified Contact',
      'default_contact_id' => 3
    ];
    $this->editContactElement($editContact);

    // Edit contact element 2.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations',
      'title' => 'Contact 2',
      'widget' => 'Select List',
      'hide_fields' => 'Name',
      'hide_method' => 'Disabled',
      'no_hide_blank' => TRUE,
      'submit_disabled' => TRUE,
      'default' => 'None',
      //'default_contact_id' => 4
    ];
    $this->editContactElement($editContact);

    // Edit contact element 3.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-3-contact-1-contact-existing-operations',
      'title' => 'Contact 3',
      'widget' => 'Select List',
      'hide_fields' => 'Name',
      'hide_method' => 'Disabled',
      'no_hide_blank' => TRUE,
      'submit_disabled' => TRUE,
      'default' => 'Specified Contact',
      'default_contact_id' => 5
    ];
    $this->editContactElement($editContact);

    // Make first/last name required for all contacts
    $this->getSession()->getPage()->checkField("webform_ui_elements[civicrm_1_contact_1_contact_first_name][required]");
    $this->getSession()->getPage()->checkField("webform_ui_elements[civicrm_2_contact_1_contact_first_name][required]");
    $this->getSession()->getPage()->checkField("webform_ui_elements[civicrm_3_contact_1_contact_first_name][required]");
    $this->getSession()->getPage()->checkField("webform_ui_elements[civicrm_1_contact_1_contact_last_name][required]");
    $this->getSession()->getPage()->checkField("webform_ui_elements[civicrm_2_contact_1_contact_last_name][required]");
    $this->getSession()->getPage()->checkField("webform_ui_elements[civicrm_3_contact_1_contact_last_name][required]");
    $this->getSession()->getPage()->pressButton('Save elements');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->htmlOutput();

    // Place fields for each contact on their own page and enable saving drafts
    $webform =  Webform::load($this->webform->getOriginalId());
    $elements = Yaml::decode($webform->get('elements'));
    $elements_new = [
      'page1' => ['#type' => 'webform_wizard_page', '#title' => 'Page 1', 'civicrm_1_contact_1_fieldset_fieldset' => $elements["civicrm_1_contact_1_fieldset_fieldset"]],
      'page2' => ['#type' => 'webform_wizard_page', '#title' => 'Page 2', 'civicrm_2_contact_1_fieldset_fieldset' => $elements["civicrm_2_contact_1_fieldset_fieldset"]],
      'page3' => ['#type' => 'webform_wizard_page', '#title' => 'Page 3', 'civicrm_3_contact_1_fieldset_fieldset' => $elements["civicrm_3_contact_1_fieldset_fieldset"]],
    ];
    $webform->set('elements', Yaml::encode($elements_new));
    $webform->setSetting('draft', 'all');
    $webform->save();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->htmlOutput();

    $this->drupalGet($this->webform->toUrl('canonical'));

    $this->assertPageNoErrorMessages();
    $this->htmlOutput();


    //** Setup complete Begin tests. **
    // "{Contacts: x, y, z}" below refers to the current form contents (three elements of $contacts[] array)

    // Page 1 {Contacts: 0, none, 2}: Check initial values.
    $this->checkContactFields($contact[0]);

    // Confirm first name is disabled
    $field_disabled = $this->getSession()->evaluateScript("document.getElementById('edit-civicrm-1-contact-1-contact-first-name').disabled");
    $this->assertEquals(true, $field_disabled, 'First name is disabled');
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 0, none, 2}: Check initial values.
    $this->checkContactFields($contact[7]); // 7 is the blank contact

    // Page 2 {Contacts: 0, none, 2}: Confirm that locked blank fields can be modified
    $this->getSession()->getPage()->fillField('First Name', 'FIRST');
    $this->assertSession()->fieldValueEquals('First Name', 'FIRST');

    // Page 2 {Contacts: 0, none, 2}: Select $contact[1].
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "{$contact[1]['first_name']} {$contact[1]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[1]);

    // Page 2 {Contacts: 0, 1, 2}: Test that locked nonblank fields are disabled.
    $field_disabled = $this->getSession()->evaluateScript("document.getElementById('edit-civicrm-2-contact-1-contact-first-name').disabled");
    $this->assertEquals(true, $field_disabled, 'First name is disabled');
    $this->getSession()->getPage()->pressButton('Next >');
    return; // @TODO: Additional parts of this test will be enabled in susbequent PRs
    $this->assertPageNoErrorMessages();

    // Page 3 {Contacts: 0, 1, 2}: Check initial values.
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 2 {Contacts: 0, 1, 2}: Check entered contact data ($contact[1]).
    $this->checkContactFields($contact[1]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1 {Contacts: 0, 1, 2}: check initial values.
    $this->checkContactFields($contact[0]);

    // Page 1 {Contacts: 0, 1, 2}: Select $contact[3]
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_existing', "{$contact[3]['first_name']} {$contact[3]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 3, 1, 2}: Check still has $contact[1]
    $this->checkContactFields($contact[1]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1: {Contacts: 3, 1, 2}: Check still has $contact[3]
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 3, 1, 2}: Check still has $contact[1]
    $this->checkContactFields($contact[1]);

    // Page 2 {Contacts: 3, 1, 2}: Create a new contact ($contact[4])
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "+ Create new +");
    $this->setContactFields($contact[4]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1 {Contacts: 3, 4, 2}: check still has $contact[3]
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 3, 4, 2}: Check still has $contact[4]
    $this->checkContactFields($contact[4]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 3, 4, 2}: Check initial state
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 2 {Contacts: 3, 4, 2}: check still has $contact[4]
    $this->checkContactFields($contact[4]);

    // Page 2 {Contacts: 3, 4, 2}: Create a new contact ($contact[5])
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "+ Create new +");
    $this->setContactFields($contact[5]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 3, 5, 2}: Check initial state
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 2 {Contacts: 3, 5, 2}: check still has $contact[5]
    $this->checkContactFields($contact[5]);

    // Page 2 {Contacts: 3, 5, 2}: Create a new contact ($contact[6])
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "+ Create new +");
    $this->setContactFields($contact[6]);

    // Page 2 {Contacts: 3, 6, 2}: Save draft
    $this->getSession()->getPage()->pressButton('Save Draft');
    $this->assertSession()->pageTextContains('Submission saved. You may return to this form later and it will restore the current values.');

    // Page 2 {Contacts: 3, 6, 2}: Reload form, check still has $contact[6]
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->pageTextContains('A partially-completed form was found. Please complete the remaining portions.');
    $this->checkContactFields($contact[6]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1 {Contacts: 3, 6, 2}: Check still has $contact[3]
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 3, 6, 2}: Check still has $contact[6]
    $this->checkContactFields($contact[6]);


    //*** Test sequence: modify, prev, save draft, load, next, next, ***
    // Page 2 {Contacts: 3, 6, 2}: Select $contact[1]
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "{$contact[1]['first_name']} {$contact[1]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[1]);

    // Page 2 {Contacts: 3, 6, 2}: Modify the job field
    $contact['1m'] = $contact[1];
    $contact['1m']['job_title'] = 'MODIFIED JOB TITLE 1';
    $this->getSession()->getPage()->fillField('Job Title', $contact['1m']['job_title']);
    $this->getSession()->getPage()->pressButton('< Prev');
    $this->checkContactFields($contact[3]);

    // Page 1 {Contacts: 3, 1m, 2}: Save/load the draft
    $this->getSession()->getPage()->pressButton('Save Draft');
    $this->assertSession()->pageTextContains('Submission saved. You may return to this form later and it will restore the current values.');
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->pageTextContains('A partially-completed form was found. Please complete the remaining portions.');

    // Page 1 {Contacts: 3, 1m, 2}: Confirm contact
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 3, 1m, 2}: Confirm modified contact
    $this->checkContactFields($contact['1m']);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 3, 1m, 2}: Confirm the job is still modified
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 2 {Contacts: 3, 1m, 2}: Confirm the contact
    $this->checkContactFields($contact['1m']);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1 {Contacts: 3, 1m, 2}: Confirm the contact
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Next >');


    //*** Test sequence: modify, next, save, load draft, prev, prev, next, next  ***
    // Page 2 {Contacts: 3, 6, 2}: Select $contact[1] (must first select a different $contact)
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "{$contact[0]['first_name']} {$contact[0]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "{$contact[1]['first_name']} {$contact[1]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[1]);

    // Page 2 {Contacts: 3, 6, 2}: Modify the job field
    $contact['1m'] = $contact[1];
    $contact['1m']['job_title'] = 'MODIFIED JOB TITLE 1A';
    $this->getSession()->getPage()->fillField('Job Title', $contact['1m']['job_title']);
    $this->getSession()->getPage()->pressButton('Next >');
    $this->checkContactFields($contact[2]);

    // Page 3 {Contacts: 3, 1m, 2}: Save/load the draft
    $this->getSession()->getPage()->pressButton('Save Draft');
    $this->assertSession()->pageTextContains('Submission saved. You may return to this form later and it will restore the current values.');
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->pageTextContains('A partially-completed form was found. Please complete the remaining portions.');

    // Page 3 {Contacts: 3, 1m, 2}: Confirm contact
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 2 {Contacts: 3, 1m, 2}: Confirm modified contact
    $this->checkContactFields($contact['1m']);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1 {Contacts: 3, 1m, 2}: Confirm the job is still modified
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 3, 1m, 2}: Confirm the contact
    $this->checkContactFields($contact['1m']);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 3, 1m, 2}: Confirm the job is still modified
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->pressButton('< Prev');


    // Page 2 {Contacts: 3, 6, 2}: Select $contact[4]
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "{$contact[4]['first_name']} {$contact[4]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[4]);

    // Page 2 {Contacts: 3, 4, 2}: Save draft
    $this->getSession()->getPage()->pressButton('Save Draft');
    $this->assertSession()->pageTextContains('Submission saved. You may return to this form later and it will restore the current values.');

    // Page 2 {Contacts: 3, 4, 2}: Reload form, check still has $contact[4]
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->pageTextContains('A partially-completed form was found. Please complete the remaining portions.');
    $this->checkContactFields($contact[4]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 3, 4, 2}: Check initial state
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 2  {Contacts: 3, 4, 2}: Check still has $contact[4]
    $this->checkContactFields($contact[4]);

    // Page 2 {Contacts: 3, 4, 2}: Create a new contact ($contact[5])
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "+ Create new +");
    $this->setContactFields($contact[5]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 3, 5, 2}: Check initial state
    $this->checkContactFields($contact[2]);

    // Page 3  {Contacts: 3, 5, 2}: create a new contact ($contact[6])
    $this->getSession()->getPage()->selectFieldOption('civicrm_3_contact_1_contact_existing', "+ Create new +");
    $this->setContactFields($contact[6]);

    // Page 3  {Contacts: 3, 5, 6}: Submit
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Confirm existing $contact[3] is unchanged, and $contact[5,6] have been created in Civi
    foreach ([3,5,6] as $key) {
      $result = $this->utils->wf_civicrm_api('Contact', 'get', [
        'first_name' => $contact[$key]['first_name'],
        'last_name' => $contact[$key]['last_name'],
        'job_title' => $contact[$key]['job_title'],
      ]);
      $this->assertEquals(0, $result['is_error']);
      $this->assertEquals(1, $result['count']);
    }


    //*** Check handling of existing contact with blank required field ***
    $this->drupalGet($this->webform->toUrl('canonical'));

    // Page 1 {Contacts: 0, none, 2}: Check initial values.
    $this->assertSession()->pageTextContains('You have already submitted this webform. View your previous submission.');
    $this->checkContactFields($contact[0]);

    // Page 1 {Contacts: 0, none, 2}: Select $contact[8] (no last name)
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_existing', "{$contact[8]['first_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[8]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 1 {Contacts: 8, none, 2}: Still on Page 1 because Last Name is blank and required
    $this->checkContactFields($contact[8]);
    $field_valid = $this->getSession()->evaluateScript("document.getElementById('edit-civicrm-1-contact-1-contact-last-name').reportValidity()");
    $this->assertEquals(false, $field_valid, 'Last Name field is not invalid.');

    $contact['8m'] = $contact[8];
    $contact['8m']['last_name'] = 'CONTACT 8 LAST NAME';
    $this->getSession()->getPage()->fillField('Last Name', $contact['8m']['last_name']);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 8m, none, 2}: Check $contact[7] (null contact)
    $this->checkContactFields($contact[7]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1 {Contacts: 8m, none, 2}: Check $contact[8m]
    $this->checkContactFields($contact['8m']);


    //*** Check Draft Save/Load with blank required field ***
    $this->drupalGet($this->webform->toUrl('canonical'));

    // Page 1 {Contacts: 0, none, 2}: Check initial values.
    $this->assertSession()->pageTextContains('You have already submitted this webform. View your previous submission.');
    $this->checkContactFields($contact[0]);

    // Page 1 {Contacts: 0, none, 2}: Select $contact[8] (no last name)
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_existing', "{$contact[8]['first_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[8]);
    $this->getSession()->getPage()->pressButton('Save Draft');
    $this->assertSession()->pageTextContains('Submission saved. You may return to this form later and it will restore the current values.');

    // Page 1 {Contacts: 8, none, 2}: Reload form, check still has $contact[8]
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->pageTextContains('A partially-completed form was found. Please complete the remaining portions.');
    $this->checkContactFields($contact[8]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 1 {Contacts: 8, none, 2}: Still on Page 1 because Last Name is blank and required
    $this->checkContactFields($contact[8]);
    $field_valid = $this->getSession()->evaluateScript("document.getElementById('edit-civicrm-1-contact-1-contact-last-name').reportValidity()");
    $this->assertEquals(false, $field_valid, 'Last Name field is not invalid.');

    // Page 1 {Contacts: 8, none, 2}: Add last name to $contact[8]
    $contact['8m'] = $contact[8];
    $contact['8m']['last_name'] = 'CONTACT 8 LAST NAME';
    $this->getSession()->getPage()->fillField('Last Name', $contact['8m']['last_name']);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 8m, none, 2}: Check $contact[7] (null contact)
    $this->checkContactFields($contact[7]);
    $this->getSession()->getPage()->pressButton('< Prev');

    // Page 1 {Contacts: 8m, none, 2}: Check $contact[8m]
    $this->checkContactFields($contact['8m']);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 8m, none, 2}: Check $contact[7] (null contact)
    $this->checkContactFields($contact[7]);

    // Page 2 {Contacts: 8m, none, 2}: Select $contact[5]
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "{$contact[5]['first_name']} {$contact[5]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 8m, 5, 2}: Check initial state
    $this->checkContactFields($contact[2]);

    // Page 3 {Contacts: 8m, 5, 2}: Select $contact[9] and submit
    $this->getSession()->getPage()->selectFieldOption('civicrm_3_contact_1_contact_existing', "{$contact[9]['first_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact['9']);
    $this->getSession()->getPage()->pressButton('Submit');

    // Page 3 {Contacts: 8m, 5, 9}: Still on Page 3 because Last Name is blank and required
    $this->checkContactFields($contact['9']);
    $field_valid = $this->getSession()->evaluateScript("document.getElementById('edit-civicrm-3-contact-1-contact-last-name').reportValidity()");
    $this->assertEquals(false, $field_valid, 'Last Name field is not invalid.');

    // Page 3 {Contacts: 8m, 5, 9}: Add last name and submit
    $contact['9m'] = $contact[9];
    $contact['9m']['last_name'] = 'CONTACT 9 LAST NAME';
    $this->getSession()->getPage()->fillField('Last Name', $contact['9m']['last_name']);
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    // Page 3 {Contacts: 8m, 5, 9m}: Confirm submit OK
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Confirm existing $contact[5] is unchanged, and $contact[8,9] now have a last name.
    foreach (['8m', 5, '9m'] as $key) {
      $result = $this->utils->wf_civicrm_api('Contact', 'get', [
        'first_name' => $contact[$key]['first_name'],
        'last_name' => $contact[$key]['last_name'],
        'job_title' => $contact[$key]['job_title'],
      ]);
      $this->assertEquals(0, $result['is_error']);
      $this->assertEquals(1, $result['count']);
    }


    //*** Check Draft Save/Load, change selected contact, Submit ***
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    // Page 1 {Contacts: 0, none, 2}: Check initial values.
    $this->checkContactFields($contact[0]);
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 2 {Contacts: 0, none, 2}: Check initial values.
    $this->checkContactFields($contact[7]);

    // Page 2 {Contacts: 0, none, 2}: Select $contact[5]
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', "{$contact[5]['first_name']} {$contact[5]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->pressButton('Next >');

    // Page 3 {Contacts: 0, 5, 2}: Check initial state, select $contact[3], save draft
    $this->checkContactFields($contact[2]);
    $this->getSession()->getPage()->selectFieldOption('civicrm_3_contact_1_contact_existing', "{$contact[3]['first_name']} {$contact[3]['last_name']}");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Save Draft');
    $this->checkContactFields($contact[3]);
    $this->assertSession()->pageTextContains('Submission saved. You may return to this form later and it will restore the current values.');
    $this->htmlOutput();

    // Page 3 {Contacts: 0, 5, 3}: Reload form, check still has $contact[3] and submit
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->pageTextContains('A partially-completed form was found. Please complete the remaining portions.');
    $this->checkContactFields($contact[3]);
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $submission = WebformSubmission::load($this->getLastSubmissionId($this->webform));
    $sub_data = $submission->getData();
    $this->assertEquals($contact[3]['first_name'], $sub_data['civicrm_3_contact_1_contact_first_name'], 'Submission first name');
    $this->assertEquals($contact[3]['last_name'], $sub_data['civicrm_3_contact_1_contact_last_name'], 'Submission last name');
    $this->assertEquals($contact[3]['job_title'], $sub_data['civicrm_3_contact_1_contact_job_title'], 'Submission job title name');
  }
}
