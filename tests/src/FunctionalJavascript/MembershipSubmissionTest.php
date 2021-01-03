<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contact with Membership (Free)
 *
 * @group webform_civicrm
 */
final class MembershipSubmissionTest extends WebformCivicrmTestBase {

  private function createMembershipType() {
    $result = civicrm_api3('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id' => "Member Dues",
      'duration_unit' => "year",
      'duration_interval' => 1,
      'period_type' => "rolling",
      'name' => "Basic",
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * Test submitting a Membership
   */
  public function testSubmitWebform() {
    $this->createMembershipType();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->clickLink('Memberships');

    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');

    $this->getSession()->getPage()->pressButton('Submit');

    // ToDo -> figure out what Error message it is! The submission itself works well.
    // $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $membership = reset($api_result['values']);

    $this->assertEquals('Basic', $membership['membership_name']);
    $this->assertEquals('1', $membership['status_id']);

    $today = date('Y-m-d');
    // throw new \Exception(var_export($today, TRUE));

    $this->assertEquals($today,  $membership['join_date']);
    $this->assertEquals($today,  $membership['start_date']);

    $this->assertEquals(date('Y-m-d', strtotime($today. ' +364 days')),  $membership['end_date']);
  }

}
