<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: existing contact element.
 *
 * @group webform_civicrm
 */
final class ExistingContactElementTest extends WebformCivicrmTestBase {

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

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

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

}
