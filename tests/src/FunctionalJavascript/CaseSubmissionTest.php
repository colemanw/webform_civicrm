<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Case
 *
 * @group webform_civicrm
 */
final class CaseSubmissionTest extends WebformCivicrmTestBase {

  protected function setUp(): void {
    parent::setUp();
    $this->enableComponent('CiviCase');
    $this->drupalLogin($this->rootUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Configure Case tab.
    $this->getSession()->getPage()->clickLink('Cases');
    $this->getSession()->getPage()->selectFieldOption('case_number_of_case', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('Update Existing Case', 'Ongoing');
    $this->getSession()->getPage()->selectFieldOption('Case Type', 'Housing Support');
    $this->getSession()->getPage()->checkField('Case Subject');
    $this->getSession()->getPage()->checkField('Case Start Date');

    $this->saveCiviCRMSettings();
  }

  /**
   * Test Case Submission.
   */
  public function testCaseSubmission() {
    $this->_caseContact = $this->createIndividual();

    // Edit contact element and remove default section.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $editContact = [
      'selector' => "edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations",
      'widget' => 'Autocomplete',
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);

    $caseSubject = "Test Case" . substr(sha1(rand()), 0, 7);
    $this->submitCaseAndVerifyResult($caseSubject);
    $caseSubject = "Update Test Case" . substr(sha1(rand()), 0, 7);
    $this->submitCaseAndVerifyResult($caseSubject);
  }

  /**
   * Test Case Submission and update with non admin user.
   */
  public function testCaseSubmissionWithNonAdminUser() {
    $this->testUser = $this->createUser([
      'access content',
    ]);
    $ufContact = $this->getUFMatchRecord($this->testUser->id());
    $this->_caseContact = $this->utils->wf_civicrm_api('Contact', 'create', [
      'id' => $ufContact['contact_id'],
      'first_name' => 'Mark',
      'last_name' => 'Gibson',
    ])['values'][$ufContact['contact_id']];

    $this->drupalLogin($this->testUser);
    $caseSubject = "Test Case create with authenticated user";
    $this->submitCaseAndVerifyResult($caseSubject, FALSE);

    $caseSubject = "Test Case update with authenticated user";
    $this->submitCaseAndVerifyResult($caseSubject, FALSE);
  }


  /**
   * Test Case Submission for contact 2.
   */
  public function testCaseSubmissionForSecondContact() {
    $this->drupalLogin($this->rootUser);
    $this->enableComponent('CiviCase');
    $this->_caseContact = $this->createIndividual();

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption("number_of_contacts", 3);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->clickLink("Contact 2");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_existing");
    $this->assertSession()->checkboxChecked("civicrm_2_contact_1_contact_existing");

    $this->getSession()->getPage()->clickLink("Contact 3");
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField("civicrm_3_contact_1_contact_existing");
    $this->assertSession()->checkboxChecked("civicrm_3_contact_1_contact_existing");

    // Configure Case tab.
    $this->getSession()->getPage()->clickLink('Cases');
    $this->getSession()->getPage()->selectFieldOption('case_number_of_case', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('case_1_settings_existing_case_status[]', 'Ongoing');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_case_1_case_client_id', 'Contact 2');
    $this->getSession()->getPage()->selectFieldOption('Case Type', 'Housing Support');
    $this->getSession()->getPage()->checkField('Case Subject');
    $this->getSession()->getPage()->checkField('Case Start Date');

    $this->saveCiviCRMSettings();

    //Edit contact element and remove default section.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $editContact = [
      'selector' => "edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations",
      'widget' => 'Autocomplete',
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);
    $editContact = [
      'selector' => "edit-webform-ui-elements-civicrm-3-contact-1-contact-existing-operations",
      'widget' => 'Autocomplete',
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);

    $this->submitCaseAndVerifyResult('Test Create Case on second contact', TRUE, 2);
    $this->submitCaseAndVerifyResult('Test Update Case on second contact', TRUE, 2);
  }

  /**
   * Submit Case and verify the result.
   *
   * @param string $caseSubject
   * @param bool $fillAutocomplete
   */
  protected function submitCaseAndVerifyResult($caseSubject, $fillAutocomplete = TRUE, $c = 1) {
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    if ($fillAutocomplete) {
      $this->fillContactAutocomplete("token-input-edit-civicrm-{$c}-contact-1-contact-existing", $this->_caseContact['first_name']);
      $this->assertFieldValue("edit-civicrm-{$c}-contact-1-contact-first-name", $this->_caseContact['first_name']);
      $this->assertFieldValue("edit-civicrm-{$c}-contact-1-contact-last-name", $this->_caseContact['last_name']);
    }

    $this->getSession()->getPage()->fillField('Case Subject', $caseSubject);
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $case_result = $this->utils->wf_civicrm_api('Case', 'get', [
      'sequential' => 1,
      'contact_id' => $this->_caseContact['id'],
    ]);
    $this->assertEquals(1, $case_result['count']);
    $this->assertEquals($caseSubject, $case_result['values'][0]['subject']);
    $this->assertEquals(date('Y-m-d'), $case_result['values'][0]['start_date']);
  }

}
