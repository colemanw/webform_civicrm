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
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_activity_date_time");
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_activity_date_time_timepart");
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_duration");

    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_subject");
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
    // ToDo -> try different dates -> default is 'now'
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
    // CiviCRM Activity Type 1 -> Meeting (default)
    $this->assertEquals('1', $activity['activity_type_id']);
    $this->assertTrue(strtotime($today) -  strtotime($activity['activity_date_time']) < 60);
    $this->assertEquals(90, $activity['duration']);

    // ToDo get contact id and activity id from the URL query for authenticated user
    // $this->webform->toUrl('canonical', ['query' => ['cid1' => 12, 'aid' => 12]]);
    $this->drupalLogin($this->adminUser);

    // Needed?
    // $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
    //  'webform' => $this->webform->id(),
    // ]));

    $this->webform->toUrl('canonical', ['query' => ['cid1' => 3, 'aid' => $activity['id']]]);
    $this->assertPageNoErrorMessages();
    $this->assertSession()->waitForField('Activity Subject');

    $this->htmlOutput();
    // $this->assertSession()->checkField('civicrm_1_activity_1_activity_subject','Awesome Activity');
    // $this->assertSession()->pageTextContains('Awesome Activity');

  }

}
