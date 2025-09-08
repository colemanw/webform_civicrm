<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Case
 *
 * @group webform_civicrm
 */
final class CaseSubmissionTest extends WebformCivicrmTestBase {

  /**
   * @var array
   */
  private $_caseContact;

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
    $testUser = $this->createUser([
      'access content',
    ]);
    $ufContact = $this->getUFMatchRecord($testUser->id());
    $this->_caseContact = $this->utils->wf_civicrm_api('Contact', 'create', [
      'id' => $ufContact['contact_id'],
      'first_name' => 'Mark',
      'last_name' => 'Gibson',
    ])['values'][$ufContact['contact_id']];

    $this->drupalLogin($testUser);
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
