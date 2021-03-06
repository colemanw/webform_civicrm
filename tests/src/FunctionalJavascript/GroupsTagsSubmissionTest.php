<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Tags
 *
 * @group webform_civicrm
 */
final class GroupsTagsSubmissionTest extends WebformCivicrmTestBase {

  public function testSubmitWebform() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->assertSession()->assertWaitOnAjaxRequest();

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
    $this->getSession()->getPage()->selectFieldOption("civicrm_1_contact_1_other_tag[]", 'create_civicrm_webform_element');
    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->waitForField('Checkboxes');
    $this->htmlOutput();
    $majorDonorTagID = $this->utils->wf_civicrm_api('Tag', 'get', [
      'name' => "Major Donor",
    ])['id'];
    //Make Major Donor as the default option.
    $this->editCivicrmOptionElement('edit-webform-ui-elements-civicrm-1-contact-1-other-tag-operations', TRUE, FALSE, $majorDonorTagID);
    //Ensure default option is loaded.
    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contact-1-other-tag-operations"] a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    //Verify if default radio is selected.
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-properties-options-default"][value=' . $majorDonorTagID . ']')->isChecked();

    //Create another tag and confirm if it loads fine on the webform.
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

    $this->getSession()->getPage()->checkField('Volunteer');
    $this->assertSession()->checkboxChecked("Volunteer");
    $this->htmlOutput();

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $contactID = $this->utils->wf_civicrm_api('Contact', 'get', $params)['id'];
    $contactTags = explode(',', $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'return' => ["tag"],
      'contact_id' => $contactID,
    ])['values'][0]['tags']);
    $this->assertArrayHasKey('Major Donor', array_flip($contactTags));
    $this->assertArrayHasKey('Volunteer', array_flip($contactTags));
    //Ensure option labels are present on result page.
    $this->drupalGet($this->webform->toUrl('results-submissions'));
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('Major Donor');
    $this->assertSession()->pageTextContains('Volunteer');
  }

}
