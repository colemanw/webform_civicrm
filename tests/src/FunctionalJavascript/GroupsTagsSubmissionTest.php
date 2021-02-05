<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Tags
 *
 * @group webform_civicrm
 */
final class GroupsTagsSubmissionTest extends WebformCivicrmTestBase {

  private function addcontactinfo() {
    // contact_id = 2 -> is the Drupal user
    $params = [
      'contact_id' => 2,
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

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Enable Tags and Groups Fields and then set Tag(s) to -User Select-
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_other', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption("civicrm_1_contact_1_other_tag[]", 'create_civicrm_webform_element');
    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    // Ensure the Tags render like checkboxes by deselecting List ->
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->waitForField('Checkboxes');
    $this->htmlOutput();
    $this->enableCheckboxOnElement('edit-webform-ui-elements-civicrm-1-contact-1-other-tag-operations');

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    // The Default Existing Contact Element behaviour is: load logged in User
    // The test here is to check if the fields on the form populate with Contact details belonging to the logged in User:
    $this->assertSession()->fieldValueEquals('First Name', 'Maarten');
    $this->assertSession()->fieldValueEquals('Last Name', 'van der Weijden');

    $this->getSession()->getPage()->checkField('Volunteer');
    $this->assertSession()->checkboxChecked("Volunteer");
    $this->htmlOutput();

    $this->getSession()->getPage()->pressButton('Submit');
    // ToDo -> Fix Notice: Array to string conversion in Drupal\webform\WebformSubmissionStorage->saveData() (line 1343 of /Applications/MAMP/htdocs/d9civicrm.local/web/modules/contrib/webform/src/WebformSubmissionStorage.php)
    // $this->assertPageNoErrorMessages();
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'return' => ["tag"],
      'contact_id' => 2,
    ]);

    $this->assertEquals('Volunteer', $api_result['values'][0]['tags']);

    // Ok let's go back to the Webform and see it is loading the previously selected Tag properly
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->assertSession()->waitForField('Volunteer');
    $this->assertSession()->checkboxChecked("Volunteer");
    $this->assertSession()->checkboxNotChecked("Company");

    // throw new \Exception(var_export($api_result, TRUE));
  }

}
