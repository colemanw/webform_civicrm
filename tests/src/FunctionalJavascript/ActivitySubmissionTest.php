<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contact with Activity
 *
 * @group webform_civicrm
 */
final class ActivitySubmissionTest extends WebformCivicrmTestBase {
  /**
   * Test submitting an activity
   */
  public function testSubmitWebform() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->clickLink('Activities');

    $this->getSession()->getPage()->selectFieldOption('activity_number_of_activity', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_subject");
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_details");
    $this->getSession()->getPage()->uncheckField('activity_1_settings_details[view_link]');
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_activity_date_time");
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_activity_date_time_timepart");
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_duration");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_activity_1_activity_assignee_contact_id[]', 'Contact 1');
    // ToDo -> assigning multiple contacts may fail with Notice in webform itself:
    // https://www.drupal.org/project/webform/issues/3191088

    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_subject");
    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_details");
    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_activity_date_time");
    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_activity_date_time_timepart");
    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_duration");

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $today = date('Y-m-d H:i:s');

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Activity Subject', 'Awesome Activity');
    $this->getSession()->getPage()->fillField('Activity Details', 'Lorem ipsum dolor sit amet.');
    // ToDo -> use different dates -> default is 'now'
    $this->getSession()->getPage()->fillField('Activity Duration', '90');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('activity', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $activity = reset($api_result['values']);
    $this->assertEquals('Awesome Activity', $activity['subject']);
    $this->assertEquals('Lorem ipsum dolor sit amet.', $activity['details']);
    // CiviCRM Activity Type 1 -> Meeting (default)
    $this->assertEquals('1', $activity['activity_type_id']);
    $this->assertTrue(strtotime($today) -  strtotime($activity['activity_date_time']) < 60);
    $this->assertEquals(90, $activity['duration']);

    $api_result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('ActivityContact', 'get', [
      'sequential' => 1,
      'return' => ["contact_id"],
      'record_type_id' => "Activity Assignees",
      'activity_id' => 1,
    ]);
    $activityContact = reset($api_result['values']);
    // In this test: contact_id 1 = Default Organization; contact_id 2 = Drupal User; contact_id 3 = Frederick
    $this->assertEquals(3, $activityContact['contact_id']);

    // Ok now let's log back in and retrieve the Activity we just stored - so that we can update it.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => 3, 'aid' => $activity['id']]]));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('Activity Duration');
    $this->htmlOutput();
    $this->getSession()->getPage()->fillField('Activity Duration', '120');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    // All we've updated is the Activity Duration
    $api_result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('activity', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $activity = reset($api_result['values']);
    $this->assertEquals(120, $activity['duration']);

    // Everything else should have remained the same:
    $this->assertEquals('Awesome Activity', $activity['subject']);
    $this->assertEquals('1', $activity['activity_type_id']);
    $this->assertTrue(strtotime($today) -  strtotime($activity['activity_date_time']) < 60);
  }

}
