<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: single contact + custom fields.
 *
 * @group webform_civicrm
 */
final class ContactDedupeTest extends WebformCivicrmTestBase {

  /**
   * The dedupe rule group ID.
   *
   * @var int
   */
  protected $dedupeRuleGroupId;

  /**
   * @var int
   */
  private $cfID;

  private function createContactSubtype() {
    $params = [
      'name' => "Student",
      'is_active' => 1,
      'parent_id' => "Individual",
    ];
    $result = $this->utils->wf_civicrm_api('ContactType', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    // Create custom group for Student.
    $cgID = $this->createCustomGroup([
      'title' => "Student Extras",
      'extends_entity_column_value' => ['Student'],
    ])['id'];
    $this->cfID = $this->utils->wf_civicrm_api('CustomField', 'create', [
      'custom_group_id' => $cgID,
      'label' => 'Advisor Name',
      'html_type' => "Text",
    ])['id'];
  }

  private function createDedupeRule() {
    $result = (array) civicrm_api4('DedupeRuleGroup', 'create', [
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
    $this->dedupeRuleGroupId = $result_DedupeRuleGroup['id'];

    $result = civicrm_api4('DedupeRule', 'create', [
      'values' => [
        'dedupe_rule_group_id' => $this->dedupeRuleGroupId,
        'rule_table' => 'civicrm_contact',
        'rule_field' => 'first_name',
        'rule_length' => '',
        'rule_weight' => 5,
      ],
    ]);

    $result = civicrm_api4('DedupeRule', 'create', [
      'values' => [
        'dedupe_rule_group_id' => $this->dedupeRuleGroupId,
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

    // Determine CiviCRM version. API4 does not exist for CiviCRM 5.35.* so the test fails :-)
    // ToDo - remove check when we remove support for 5.35.*
    $api_result = civicrm_api3('Domain', 'get', [
      'sequential' => 1,
      'return' => ["version"],
    ]);
    $domain = reset($api_result['values']);
    if ($domain['version'] == '5.35.2') {
      return;
    }

    // We'll be using phone_numeric so we must ensure we have the triggers that we need for that field to be populated
    \Civi::service('sql_triggers')->rebuild('civicrm_phone', TRUE);

    $this->drupalLogin($this->adminUser);

    $this->createContactSubtype();
    $this->createDedupeRule();

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_contact_sub_type[]', 'Student');
    $this->assertSession()->assertWaitOnAjaxRequest();
    // Check if student custom group is displayed on the form.
    $this->assertSession()->pageTextContains('Student Extras');
    $this->getSession()->getPage()->selectFieldOption('Enable Student Extras Fields', 'Yes');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Advisor Name');

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

    $this->saveCiviCRMSettings();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'frederick@pabst.io');
    $this->getSession()->getPage()->fillField('Phone', '4031234567');
    $this->getSession()->getPage()->fillField('Advisor Name', 'Professor Jane Smith');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Note: custom fields are on contact_id=3 (1=default org; 2=the drupal user)
    $api_result = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
      'return' => ["custom_{$this->cfID}", 'contact_sub_type'],
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contact = reset($api_result['values']);
    $this->assertEquals('Student', implode($contact['contact_sub_type']));
    $this->assertEquals('Professor Jane Smith', $contact["custom_{$this->cfID}"]);

    $api_result = $this->utils->wf_civicrm_api('Email', 'get', [
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
    $this->getSession()->getPage()->fillField('Phone', '4031234567');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    // Check to see Last Name has been updated
    $api_result = $this->utils->wf_civicrm_api('Contact', 'get', [
      'sequential' => 1,
      'contact_id' => $contact['id'],
    ]);
    $contact = reset($api_result['values']);

    // throw new \Exception(var_export($api_result, TRUE));

    $this->assertEquals('Pabst-edited', $contact['last_name']);

    // First Name and Email should have remained the same:
    $this->assertEquals('Frederick', $contact['first_name']);
    $this->assertEquals('Student', implode($contact['contact_sub_type']));

    $api_result = $this->utils->wf_civicrm_api('Email', 'get', [
      'contact_id' => $contact['id'],
      'sequential' => 1,
    ]);
    $email = reset($api_result['values']);
    $this->assertEquals('frederick@pabst.io', $email['email']);

    $this->drupalLogin($this->adminUser);

    civicrm_api4('DedupeRule', 'delete', [
      'where' => [['dedupe_rule_group_id.id', '=', $this->dedupeRuleGroupId]],
    ]);

    civicrm_api4('DedupeRuleGroup', 'delete', [
      'where' => [['id', '=', $this->dedupeRuleGroupId]],
    ]);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->assertSession()->elementExists('css', 'input[data-drupal-selector="edit-nid"]');
    $this->assertPageNoErrorMessages();
  }

}
