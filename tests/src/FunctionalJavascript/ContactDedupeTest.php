<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

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

  private function createDedupeRule() {
    $result = civicrm_api4('DedupeRuleGroup', 'create', [
      'values' => [
        'contact_type' => 'Individual',
        'threshold' => 10,
        'used' => 'General',
        'name' => 'FirstPhone',
        'title' => 'FirstPhone',
        'is_reserved' => FALSE,
        ],
    ]);
    $result_DedupeRuleGroup = reset($result);
    $dedupe_rule_group_id = $result_DedupeRuleGroup['id'];

    $result = civicrm_api4('DedupeRule', 'create', [
      'values' => [
        'dedupe_rule_group_id' => $dedupe_rule_group_id,
        'rule_table' => 'civicrm_contact',
        'rule_field' => 'first_name',
        'rule_length' => '',
        'rule_weight' => 5,
      ],
    ]);

    $result = civicrm_api4('DedupeRule', 'create', [
      'values' => [
        'dedupe_rule_group_id' => $dedupe_rule_group_id,
        'rule_table' => 'civicrm_phone',
        'rule_field' => 'phone_numeric',
        'rule_length' => '',
        'rule_weight' => 5,
      ],
    ]);
  }

  /**
   * Test submitting Contact - Matching Rule
   */
  public function testSubmitWebform() {
    // We'll be using phone_numeric so we must ensure we have the triggers that we need for that field to be populated
    \Civi::service('sql_triggers')->rebuild('civicrm_phone', TRUE);

    $this->drupalLogin($this->adminUser);

    $this->createContactSubtype();
    $this->createDedupeRule();

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->getSession()->getPage()->checkField('nid');

    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_contact_sub_type[]', 'Student');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Select our Custom Rule FirstPhone
    $this->getSession()->getPage()->selectFieldOption('contact_1_settings_matching_rule', 'FirstPhone');
    // We do need Phone then!
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_phone', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->createScreenshot($this->htmlOutputDirectory . 'img.png');
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_phone_phone");
    $this->htmlOutput();

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
    $this->getSession()->getPage()->fillField('Phone', '4031234567');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Note: custom fields are on contact_id=3 (1=default org; 2=the drupal user)
    $utils = \Drupal::service('webform_civicrm.utils');
    $api_result = $utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contact = reset($api_result['values']);
    $this->assertEquals('Student', implode($contact['contact_sub_type']));

    $api_result = $utils->wf_civicrm_api('Email', 'get', [
      'contact_id' => $contact['id'],
      'sequential' => 1,
    ]);
    $email = reset($api_result['values']);
    $this->assertEquals('frederick@pabst.io', $email['email']);

    // Next: load the form again and resubmit it -> update the Last Name:
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst-edited');
    $this->getSession()->getPage()->fillField('Email', 'frederick@pabst.io');
    $this->getSession()->getPage()->fillField('Phone', '4031234567');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Check to see Last Name has been updated
    $api_result = $utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'contact_id' => $contact['id'],
    ]);
    $contact = reset($api_result['values']);

    // throw new \Exception(var_export($api_result, TRUE));

    $this->assertEquals('Pabst-edited', $contact['last_name']);

    // First Name and Email should have remained the same:
    $this->assertEquals('Frederick', $contact['first_name']);
    $this->assertEquals('Student', implode($contact['contact_sub_type']));

    $api_result = $utils->wf_civicrm_api('Email', 'get', [
      'contact_id' => $contact['id'],
      'sequential' => 1,
    ]);
    $email = reset($api_result['values']);
    $this->assertEquals('frederick@pabst.io', $email['email']);
  }

}
