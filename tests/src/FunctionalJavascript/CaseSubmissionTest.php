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

    // Configure Activity Tab
    $this->getSession()->getPage()->clickLink('Activities');
    $this->getSession()->getPage()->selectFieldOption('activity_number_of_activity', 1);
    $this->assertSession()->waitForField('civicrm_1_activity_1_activity_subject');
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('Update Existing Activity', 'Scheduled');
    $this->getSession()->getPage()->selectFieldOption('File On Case', 'Case 1');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->getPage()->checkField('Activity Subject');
    $this->getSession()->getPage()->selectFieldOption('Activity Type', 'Follow up');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->saveCiviCRMSettings();
  }

  /**
   * Verify correct Case and related Activity
   * is loaded on the webform by default.
   */
  public function testCaseActivityDefaults() {
    $cases = [
      [
        'subject' => 'Case 1 subject',
        'status' => 'Closed',
        'activity_subject' => 'Followup Activity from Case 1',
      ],
      [
        'subject' => 'Case 2 subject',
        'status' => 'Open',
        'activity_subject' => 'Followup Activity from Case 2',
      ],
    ];
    // Create case 1 with status=Resolved
    // Create case 2 with status=Ongoing
    foreach ($cases as $params) {
      $case = \Civi\Api4\CiviCase::create(TRUE)
        ->addValue('case_type_id.name', 'housing_support')
        ->addValue('status_id:name', $params['status'])
        ->addValue('contact_id', $this->rootUserCid)
        ->addValue('subject', $params['subject'])
        ->addValue('creator_id', $this->rootUserCid)
        ->execute()
        ->first();

      // Add subject to the followup activity
      \Civi\Api4\Activity::update(TRUE)
        ->addValue('subject', $params['activity_subject'])
        ->addWhere('case_id', '=',  $case['id'])
        ->addWhere('activity_type_id:name', '=', 'Follow up')
        ->execute()
        ->first();
    }

    // Load the webform
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Ensure case subject and activity subject is populated correctly.
    $this->assertSession()->fieldValueEquals('civicrm_1_case_1_case_subject', 'Case 2 subject');
    $this->assertSession()->fieldValueEquals('civicrm_1_activity_1_activity_subject', 'Followup Activity from Case 2');
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
   * Submit Case and verify the result.
   *
   * @param string $caseSubject
   * @param bool $fillAutocomplete
   */
  protected function submitCaseAndVerifyResult($caseSubject, $fillAutocomplete = TRUE) {
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    if ($fillAutocomplete) {
      $this->fillContactAutocomplete('token-input-edit-civicrm-1-contact-1-contact-existing', $this->_caseContact['first_name']);
      $this->assertFieldValue('edit-civicrm-1-contact-1-contact-first-name', $this->_caseContact['first_name']);
      $this->assertFieldValue('edit-civicrm-1-contact-1-contact-last-name', $this->_caseContact['last_name']);
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
