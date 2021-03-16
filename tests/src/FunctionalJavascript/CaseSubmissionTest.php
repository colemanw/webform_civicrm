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
   * Test Case Submission.
   */
  public function testCaseSubmission() {
    $this->drupalLogin($this->rootUser);
    $this->enableComponent('CiviCase');
    $this->_caseContact = $this->createIndividual();

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

    $this->saveCiviCRMSettings();

    //Edit contact element and remove default section.
    $this->drupalGet($this->webform->toUrl('edit-form'));

    $contactElementEdit = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations"] a.webform-ajax-link');
    $contactElementEdit->click();
    $this->htmlOutput();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-form"]')->click();

    $this->assertSession()->waitForField('properties[widget]');
    $this->getSession()->getPage()->selectFieldOption('Form Widget', 'Autocomplete');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForElementVisible('css', '[data-drupal-selector="edit-properties-search-prompt"]');
    $this->getSession()->getPage()->fillField('Search Prompt', '- Select Contact -');

    $this->htmlOutput();
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-contact-defaults"]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('Set default contact from', '- None -');
    $this->getSession()->getPage()->uncheckField('properties[allow_url_autofill]');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $caseSubject = "Test Case" . substr(sha1(rand()), 0, 7);
    $this->submitCaseAndVerifyResult($caseSubject);
    $caseSubject = "Update Test Case" . substr(sha1(rand()), 0, 7);
    $this->submitCaseAndVerifyResult($caseSubject);

  }

  /**
   * Submit Case and verify the result.
   *
   * @param string $caseSubject
   */
  protected function submitCaseAndVerifyResult($caseSubject) {
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->fillContactAutocomplete('token-input-edit-civicrm-1-contact-1-contact-existing', $this->_caseContact['first_name']);

    $this->assertSession()->fieldValueEquals('First Name', $this->_caseContact['first_name']);
    $this->assertSession()->fieldValueEquals('Last Name', $this->_caseContact['last_name']);

    $this->getSession()->getPage()->fillField('Case Subject', $caseSubject);
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $case_result = $this->utils->wf_civicrm_api('Case', 'get', [
      'sequential' => 1,
      'contact_id' => $this->_caseContact['id'],
    ]);
    $this->assertEquals(1, $case_result['count']);
    $this->assertEquals($caseSubject, $case_result['values'][0]['subject']);
  }

}
