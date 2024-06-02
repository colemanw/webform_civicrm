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
   * @var array
   */
  private $_contacts;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->_contacts = [
      1 => [
        'first_name' => 'Frederick',
        'last_name' => 'Pabst',
      ],
      2 => [
        'first_name' => 'Mark',
        'last_name' => 'Wood',
      ],
    ];
  }
  /**
   * Test submitting an activity
   */
  public function testSubmitWebform() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->addActivityFields();
    $this->submitWebform();
    $this->verifyActivityValues();
  }

  /**
   * Test Activity with single option for Activity Type.
   */
  public function testSingleActivityTypeOption() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->addActivityFields(1, TRUE);
    // Alter activity type field to use static options with only 1 option enabled.
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->htmlOutput();
    $this->editCivicrmOptionElement("edit-webform-ui-elements-civicrm-1-activity-1-activity-activity-type-id-operations", FALSE, TRUE, NULL, NULL, TRUE, TRUE);

    $this->submitWebform();
    $this->verifyActivityValues();
  }

  /**
   * Test activity on multiple assignees
   */
  public function testMultipleAssignees() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->addActivityFields(2);
    $this->submitWebform(2);
    $this->verifyActivityValues(2);
  }

  /**
   * Add activity fields on webform and set assignee values
   *
   * @param int $num
   */
  private function addActivityFields($num = 1, $select_activity = FALSE) {
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', $num);
    $this->htmlOutput();

    $this->getSession()->getPage()->clickLink('Activities');
    $this->getSession()->getPage()->selectFieldOption('activity_number_of_activity', 1);
    $this->assertSession()->waitForField('civicrm_1_activity_1_activity_subject');
    $this->htmlOutput();

    if ($select_activity) {
      $this->getSession()->getPage()->selectFieldOption('civicrm_1_activity_1_activity_activity_type_id', '- User Select -');
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->htmlOutput();
    }

    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_subject");
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_details");
    $this->getSession()->getPage()->uncheckField('activity_1_settings_details[view_link]');
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_activity_date_time");
    $this->getSession()->getPage()->checkField("civicrm_1_activity_1_activity_duration");

    $multiple = FALSE;
    if ($num > 1) {
      $multiple = TRUE;
      $this->getSession()->getPage()->find('xpath', '//div[contains(@class, "form-item-civicrm-1-activity-1-activity-assignee-contact-id")]/a[contains(@class, "select-multiple")]')->click();
    }
    $this->assertSession()->assertWaitOnAjaxRequest();
    for ($i = 1; $i <= $num; $i++) {
      $this->getSession()->getPage()->selectFieldOption('civicrm_1_activity_1_activity_assignee_contact_id[]', "Contact {$i}", $multiple);
    }

    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_subject");
    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_details");
    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_activity_date_time");
    $this->assertSession()->checkboxChecked("civicrm_1_activity_1_activity_duration");

    $this->saveCiviCRMSettings();
  }

  /**
   * Submit webform for activity
   *
   * @param int $num
   */
  private function submitWebform($num = 1) {
    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('civicrm_1_contact_1_contact_first_name');

    for ($i = 1; $i <= $num; $i++) {
      $this->getSession()->getPage()->fillField("civicrm_{$i}_contact_1_contact_first_name", $this->_contacts[$i]['first_name']);
      $this->getSession()->getPage()->fillField("civicrm_{$i}_contact_1_contact_last_name", $this->_contacts[$i]['last_name']);
    }

    $this->getSession()->getPage()->fillField('Activity Subject', 'Awesome Activity');
    $this->getSession()->getPage()->fillField('Activity Details', 'Lorem ipsum dolor sit amet.');
    // ToDo -> use different dates -> default is 'now'
    $this->getSession()->getPage()->fillField('Activity Duration', '90');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }

  /**
   * Verify activity values in civicrm.
   */
  private function verifyActivityValues($num = 1) {
    $api_result = $this->utils->wf_civicrm_api('activity', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $activity = reset($api_result['values']);
    $this->assertEquals('Awesome Activity', $activity['subject']);
    $this->assertEquals('Lorem ipsum dolor sit amet.', $activity['details']);
    // CiviCRM Activity Type 1 -> Meeting (default)
    $this->assertEquals('1', $activity['activity_type_id']);
    $today = date('Y-m-d H:i:s');
    $this->assertTrue(strtotime($today) -  strtotime($activity['activity_date_time']) < 120);
    $this->assertEquals(90, $activity['duration']);

    $api_result = $this->utils->wf_civicrm_api('ActivityContact', 'get', [
      'sequential' => 1,
      'return' => ["contact_id"],
      'record_type_id' => "Activity Assignees",
      'activity_id' => $activity['id'],
    ]);
    $activityContacts = array_column($api_result['values'], 'contact_id');
    for ($i = 1; $i <= $num; $i++) {
      // In this test: contact_id 1 = Default Organization; contact_id 2 = Drupal User; contact_id 3 = Frederick
      $contact = $this->utils->wf_civicrm_api('Contact', 'get', [
        'sequential' => 1,
        'first_name' => $this->_contacts[$i]['first_name'],
        'last_name' => $this->_contacts[$i]['last_name'],
      ]);
      $this->assertEquals(1, $contact['count']);
      $this->assertTrue(in_array($contact['id'], $activityContacts));
    }

    // Ok now let's log back in and retrieve the Activity we just stored - so that we can update it.
    $this->drupalLogin($this->adminUser);
    $sid = $this->getLastSubmissionId($this->webform);
    $this->drupalGet(Url::fromRoute('entity.webform_submission.canonical', [
      'webform' => $this->webform->id(),
      'webform_submission' => $sid,
    ]));
    $this->htmlOutput();

    $title = $this->webform->label();
    $this->assertSession()->pageTextContains("{$title}: Submission #{$sid} by Frederick Pabst");
    $this->assertSession()->linkExists("View Frederick Pabst");
    $this->assertSession()->linkExists("View Activity");
    $this->assertSession()-> linkNotExists('View Contribution');
    $this->assertSession()-> linkNotExists('View Participant');

    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contact['id'], 'aid' => $activity['id']]]));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('Activity Duration');
    $this->htmlOutput();
    $this->getSession()->getPage()->fillField('Activity Duration', '120');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    // All we've updated is the Activity Duration
    $api_result = $this->utils->wf_civicrm_api('activity', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $activity = reset($api_result['values']);
    $this->assertEquals(120, $activity['duration']);

    // Everything else should have remained the same:
    $this->assertEquals('Awesome Activity', $activity['subject']);
    $this->assertEquals('1', $activity['activity_type_id']);
    $this->assertTrue(strtotime($today) -  strtotime($activity['activity_date_time']) < 120);
  }

}
