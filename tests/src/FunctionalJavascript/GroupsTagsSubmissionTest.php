<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Tags
 *
 * @group webform_civicrm
 */
final class GroupsTagsSubmissionTest extends WebformCivicrmTestBase {

  private $groups = [];
  private $tags = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['GroupA', 'GroupB', 'GroupC'] as $group) {
      $this->groups[$group] = $this->utils->wf_civicrm_api('Group', 'create', [
        'title' => $group,
      ])['id'];
    }
  }

  /**
   * Test the display of public groups on webform.
   */
  public function testPublicGroups() {
    // Make GroupA and GroupB as public
    $this->utils->wf_civicrm_api('Group', 'create', [
      'id' => $this->groups['GroupA'],
      'visibility' => "Public Pages",
    ]);
    $this->utils->wf_civicrm_api('Group', 'create', [
      'id' => $this->groups['GroupB'],
      'visibility' => "Public Pages",
    ]);

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Enable Groups Field and then set it to -User Select (Public Group)-
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_other', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption("civicrm_1_contact_1_other_group[]", 'public_groups');
    $this->htmlOutput();
    $this->saveCiviCRMSettings();

    // Visit the form.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->pageTextContains('GroupA');
    $this->assertSession()->pageTextContains('GroupB');
    $this->assertSession()->pageTextNotContains('GroupC');
  }

  public function testSubmitWebform() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Scenario: admin user is configuring the form - for some admin to data enter volunteer contacts
    $this->getSession()->getPage()->uncheckField('Existing Contact');
    $this->assertSession()->checkboxNotChecked('Existing Contact');
    $this->assertSession()->checkboxChecked('First Name');
    $this->assertSession()->checkboxChecked('Last Name');

    // Enable Email address
    // The Default Unsupervised Matching Rule in CiviCRM is: Email so we need to get it on the webform:
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_email_email");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_email_location_type_id', 'Main');

    // Enable Tags and Groups Fields and then set Tag(s) to -User Select-
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_other', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption("civicrm_1_contact_1_other_group[]", 'create_civicrm_webform_element');
    $this->getSession()->getPage()->selectFieldOption("civicrm_1_contact_1_other_tag[]", 'create_civicrm_webform_element');
    $this->htmlOutput();
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->htmlOutput();

    // Change type of group field to checkbox.
    $this->editCivicrmOptionElement('edit-webform-ui-elements-civicrm-1-contact-1-other-group-operations', FALSE, FALSE, NULL, 'checkboxes');

    $majorDonorTagID = $this->utils->wf_civicrm_api('Tag', 'get', [
      'name' => (version_compare(\CRM_Core_BAO_Domain::version(), '5.68.alpha1', '<') ? "Major Donor" : "Major_Donor"),
    ])['id'];
    // Make Major Donor as the default option.
    $this->editCivicrmOptionElement('edit-webform-ui-elements-civicrm-1-contact-1-other-tag-operations', TRUE, FALSE, $majorDonorTagID);
    // Ensure default option is loaded.
    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contact-1-other-tag-operations"] a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->assertSession()->waitForField('properties[options][default]', 5000);
    $this->htmlOutput();
    // Verify if default radio is selected.
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-properties-options-default"][value=' . $majorDonorTagID . ']')->isChecked();

    // Create another tag and confirm if it loads fine on the webform.
    $tagID = $this->utils->wf_civicrm_api('Tag', 'create', [
      'name' => "Tag" . substr(sha1(rand()), 0, 4),
    ])['id'];

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->assertSession()->elementExists('css', "#edit-civicrm-1-contact-1-other-tag-{$tagID}");
    $this->assertSession()->waitForField('First Name');
    $params = [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ];
    $this->getSession()->getPage()->fillField('First Name', $params['first_name']);
    $this->getSession()->getPage()->fillField('Last Name', $params['last_name']);
    $this->getSession()->getPage()->fillField('Email', 'frederick@pabst.io');

    $this->getSession()->getPage()->checkField('GroupB');
    $this->assertSession()->checkboxChecked("GroupB");
    $this->getSession()->getPage()->checkField('GroupC');
    $this->assertSession()->checkboxChecked("GroupC");
    $this->getSession()->getPage()->checkField('Volunteer');
    $this->assertSession()->checkboxChecked("Volunteer");
    $this->htmlOutput();

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $contactID = $this->utils->wf_civicrm_api('Contact', 'get', $params)['id'];
    $contact = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'return' => ["tag", "group"],
      'contact_id' => $contactID,
    ])['values'][0];
    $contactTags = explode(',', $contact['tags']);
    $contactGroups = explode(',', $contact['groups']);

    $this->assertTrue(in_array($this->groups['GroupB'], $contactGroups));
    $this->assertTrue(in_array($this->groups['GroupC'], $contactGroups));
    $this->assertTrue(in_array(version_compare(\CRM_Core_BAO_Domain::version(), '5.68.alpha1', '<') ? "Major Donor" : "Major_Donor", $contactTags));
    $this->assertTrue(in_array('Volunteer', $contactTags));

    // Ensure option labels are present on result page.
    $this->drupalGet($this->webform->toUrl('results-submissions'));
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('Frederick Pabst');
    $this->assertSession()->pageTextContains('Major Donor');
    $this->assertSession()->pageTextContains('Volunteer');
    $this->assertSession()->pageTextContains('GroupB');
    $this->assertSession()->pageTextContains('GroupC');

    // Now let's reload the form and Uncheck Volunteer - then confirm it has been unchecked
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->getSession()->getPage()->checkField('Existing Contact');
    $this->assertSession()->checkboxChecked('Existing Contact');
    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    // Frederick is $contactID = 3
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contactID]]));
    $this->assertPageNoErrorMessages();
    $this->assertSession()->waitForField('Volunteer');
    $this->getSession()->getPage()->checkField('GroupA');
    $this->assertSession()->checkboxChecked("GroupA");
    $this->getSession()->getPage()->uncheckField('GroupB');
    $this->assertSession()->checkboxNotChecked('GroupB');

    $this->getSession()->getPage()->uncheckField('Volunteer');
    $this->assertSession()->checkboxNotChecked('Volunteer');
    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $contactID = $this->utils->wf_civicrm_api('Contact', 'get', $params)['id'];
    $contact = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'return' => ["tag", "group"],
      'contact_id' => $contactID,
    ])['values'][0];
    $contactTags = explode(',', $contact['tags']);
    $contactGroups = explode(',', $contact['groups']);
    $this->assertTrue(in_array(version_compare(\CRM_Core_BAO_Domain::version(), '5.68.alpha1', '<') ? "Major Donor" : "Major_Donor", $contactTags));
    $this->assertFalse(in_array('Volunteer', $contactTags));

    $this->assertTrue(in_array($this->groups['GroupA'], $contactGroups));
    $this->assertFalse(in_array($this->groups['GroupB'], $contactGroups));
  }

  /**
   * Verify if child tag is listed correctly on contact element.
   * Also, assert submission of child tag values.
   */
  public function testChildTagSubmission() {
    $this->tags['tagA'] = $this->utils->wf_civicrm_api('Tag', 'create', [
      'name' => 'Child Tag A',
      'parent_id' => "Company",
    ])['id'];
    $this->tags['tagB'] = $this->utils->wf_civicrm_api('Tag', 'create', [
      'name' => 'Child Tag B',
      'parent_id' => "Company",
    ])['id'];

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_other', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Ensure child tags are present in the tags dropdown.
    $this->assertSession()->optionExists('civicrm_1_contact_1_other_tag[]', $this->tags['tagA']);
    $this->assertSession()->optionExists('civicrm_1_contact_1_other_tag[]', $this->tags['tagB']);
    $this->getSession()->getPage()->selectFieldOption("civicrm_1_contact_1_other_tag[]", 'create_civicrm_webform_element');
    $this->htmlOutput();
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->htmlOutput();

    $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector='edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations'] a.webform-ajax-link");
    $contactElementEdit = $this->assertSession()->elementExists('css', "[data-drupal-selector='edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations'] a.webform-ajax-link");
    $contactElementEdit->click();
    $this->htmlOutput();
    $this->assertSession()->waitForElementVisible('css', '[data-drupal-selector="edit-form"]');

    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-filters"]')->click();

    $this->assertSession()->optionExists('properties[tag][]', $this->tags['tagA']);
    $this->assertSession()->optionExists('properties[tag][]', $this->tags['tagB']);

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');

    $this->getSession()->getPage()->checkField('Volunteer');
    $this->assertSession()->checkboxChecked("Volunteer");
    $this->getSession()->getPage()->checkField("- Child Tag A");
    $this->assertSession()->checkboxChecked("- Child Tag A");

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $contact = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'return' => ["tag"],
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ])['values'][0];
    $contactTags = explode(',', $contact['tags']);
    $this->assertTrue(in_array('Child Tag A', $contactTags));
    $this->assertTrue(in_array('Volunteer', $contactTags));
  }

}
