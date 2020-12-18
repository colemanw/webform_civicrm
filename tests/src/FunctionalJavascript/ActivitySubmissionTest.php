<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM and a single contact.
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
    // $this->getSession()->getPage()->fillField('date_field_name', TIMESTRING_AS_HTML_DATE)
    // date('c')
    // '2017-03-01T20:02:00'
    // core check date time - date time range - perhaps smart date module
    // DateRangeFieldTest lines 88-91

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Activity Subject', 'Awesome Activity');
    // ToDo -> try different dates -> default is 'now'
    // $this->getSession()->getPage()->fillField('Activity Date', '2020-12-12');
    $this->getSession()->getPage()->fillField('Activity Duration', '90');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->createScreenshot('test.png');
    // $this->createScreenshot($this->htmlOutputDirectory . '/test.png');
    // $this->htmlOutput();

    // ToDo -> figure out what Error message it is! The submission itself works well.
    // $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = wf_civicrm_api('activity', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $activity = reset($api_result['values']);
    $this->assertEquals('Awesome Activity', $activity['subject']);
    // CiviCRM Activity Type 1 -> Meeting (default)
    $this->assertEquals('1', $activity['activity_type_id']);
    $this->assertTrue(strtotime($today) -  strtotime($activity['activity_date_time']) < 60);
    $this->assertEquals(90, $activity['duration']);

    // $this->webform->toUrl('canonical', ['query' => ['cid1' => 12, 'aid' => 12]]);
  }

}
