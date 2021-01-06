<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with CiviCRM: single contact + custom fields.
 *
 * @group webform_civicrm
 */
final class ContactDedupeTest extends WebformCivicrmTestBase {

  private function createContactSubtype() {
    $params = [
        'name' => "Student",
        'is_active' => 1,
        'parent_id' => "Individual",
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('ContactType', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * Test submitting Contact - Matching Rule
   */
  public function testSubmitWebform() {

    $this->createContactSubtype();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');

    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_contact_sub_type[]', 'Student');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // The Default Unsupervised Matching Rule in CiviCRM is: Email so we need to get it on the webform:
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_email_email");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_email_location_type_id', 'Main');
    $this->htmlOutput();

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'frederick@pabst.io');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Note: custom fields are on contact_id=3 (1=default org; 2=the drupal user)
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'contact_id' => 3,
    ]);
    $contact = reset($api_result['values']);

    $this->assertEquals('Frederick', $contact['first_name']);
    $this->assertEquals('Pabst', $contact['last_name']);
    $this->assertEquals('Student', implode($contact['contact_sub_type']));

    $api_result = $utils->wf_civicrm_api('Email', 'get', [
      'contact_id' => $contact['id'],
      'sequential' => 1,
    ]);
    $email = reset($api_result['values']);
    $this->assertEquals('frederick@pabst.io', $email['email']);

    // Next: load the form with cid1 -> and resubmit it -> update the Last Name:
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contact['id']]]));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('Last Name');
    $this->htmlOutput();
    $this->getSession()->getPage()->fillField('Last Name', 'Pabsted');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    // Check to see Last Name has been updated
    $api_result = $utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'contact_id' => $contact['id'],
    ]);
    $contact = reset($api_result['values']);

    $this->assertEquals('Pabsted', $contact['last_name']);
    throw new \Exception(var_export($contact, TRUE));

    // First Name and Email should have remained the same:
    $this->assertEquals('Frederick', $contact['first_name']);
    $this->assertEquals('Student', $contact['contact_sub_type']);

    $api_result = $utils->wf_civicrm_api('Email', 'get', [
      'contact_id' => $contact['id'],
      'sequential' => 1,
    ]);
    $email = reset($api_result['values']);
    $this->assertEquals(1, $email['count']);
    $this->assertEquals('frederick@pabst.io', $email['email']);
  }

}
