<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Tests editing webform submissions.
 *
 * @group webform_civicrm
 */
final class SubmissionEditTest extends WebformCivicrmTestBase {

  public function testEditSubmission() {
    // Set up webform-civicrm with one contact, create-only
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->uncheckField("civicrm_1_contact_1_contact_existing");
    $this->saveCiviCRMSettings();

    $oldMax = $this->getMaxId();

    // Submit form to create a contact
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->getSession()->getPage()->fillField('First Name', 'Dummy');
    $this->getSession()->getPage()->fillField('Last Name', 'Tester');
    $this->getSession()->getPage()->pressButton('Submit');

    // Should have created one contact
    $newMax = $this->getMaxId();
    $this->assertEquals($oldMax + 1, $newMax);
    $this->assertEquals('Dummy', civicrm_api3('Contact', 'get', ['id' => $newMax])['values'][$newMax]['first_name']);

    $submission = WebformSubmission::load($this->getLastSubmissionId($this->webform));
    // Edit submission and save
    $this->drupalGet($submission->toUrl('edit-form'));
    $this->getSession()->getPage()->fillField('First Name', 'Smarty');
    $this->getSession()->getPage()->fillField('Last Name', 'Tester');
    $this->getSession()->getPage()->pressButton('Save');

    // Should have updated not created a new contact
    $this->assertEquals($newMax, $this->getMaxId());

    $this->assertEquals('Smarty', civicrm_api3('Contact', 'get', ['id' => $newMax])['values'][$newMax]['first_name']);

  }

  protected function getMaxId($entity = 'Contact') {
    return civicrm_api3($entity, 'get', [
      'options' => ['sort' => "id DESC", 'limit' => 1]
    ])['id'];
  }
}
