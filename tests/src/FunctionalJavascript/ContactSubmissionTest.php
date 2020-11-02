<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

/**
 * Tests Webform CiviCRM.
 *
 * @group webform_civicrm
 */
final class ContactSubmissionTest extends WebformCivicrmTestBase {

  /**
   * {@inheritdoc}
   *
   * During tests configuration schema is validated. This module does not
   * provide schema definitions for its handler.
   *
   * To fix: webform.webform.civicrm_webform_test:handlers.webform_civicrm.settings
   *
   * @see \Drupal\Core\Test\TestSetupTrait::getConfigSchemaExclusions
   */
  protected static $configSchemaCheckerExclusions = [
    'webform.webform.civicrm_webform_test',
  ];

  /**
   * Test submitting a contact.
   *
   * @dataProvider dataContactValues
   *
   * @todo $contact_values might need to be a more robust array, such as:
   *   contact_values:
   *    contact:
   *      first_name: foo
   *      last_name: bar
   *      nickname: baz
   *    email:
   *      email: foo@example.com
   *      type: main
   *
   */
  public function testSubmitWebform($contact_type, array $contact_values) {
    $this->assertArrayHasKey('first_name', $contact_values, 'Test data must contain first_name');
    $this->assertArrayHasKey('last_name', $contact_values, 'Test data must contain last_name');

    $this->drupalLogin($this->testUser);
    $this->drupalGet($this->webform->toUrl('settings'));
    $this->getSession()->getPage()->clickLink('CiviCRM');
    $this->getSession()->getPage()->checkField('Enable CiviCRM Processing');
    $this->getSession()->getPage()->selectFieldOption('1_contact_type', strtolower($contact_type));
    $this->assertSession()->assertWaitOnAjaxRequest();

    if (isset($contact_values['email'])) {
      $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->checkboxChecked('civicrm_1_contact_1_email_email');
    }

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertSession()->checkboxChecked('Existing Contact');
    $this->assertSession()->checkboxChecked('First Name');
    $this->assertSession()->checkboxChecked('Last Name');

    $this->drupalGet($this->webform->toUrl('canonical'));
    $error_messages = $this->getSession()->getPage()->findAll('css', '.messages.messages--error');
    $this->assertCount(0, $error_messages);

    foreach ($contact_values as $field_name => $field_value) {
      $selector = 'civicrm_1_contact_1_contact_' . $field_name;
      // Email form name is civicrm_1_contact_1_email_email, not *_contact_email.
      // Hacked in for now.
      if ($field_name === 'email') {
        $selector = 'civicrm_1_contact_1_email_email';
      }
      $this->getSession()->getPage()->fillField($selector, $contact_values[$field_name]);
    }
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $result = civicrm_api('contact', 'get', [
      'version' => 3,
      'sequential' => 1,
      'first_name' => $contact_values['first_name'],
      'last_name' => $contact_values['last_name'],
    ]);
    $this->assertArrayHasKey('count', $result, var_export($result, TRUE));
    $this->assertEquals(1, $result['count'], var_export($result, TRUE));
    $contact = $result['values'][0];
    $this->assertEquals($contact_type, $contact['contact_type']);

    $result_debug = var_export($result, TRUE);
    foreach ($contact_values as $field_name => $field_value) {
      $this->assertEquals($field_value, $contact[$field_name], $result_debug);
    }
  }

  /**
   * Data for the test.
   *
   * @return \Generator
   *   The test data.
   */
  public function dataContactValues() {
    yield [
      'Individual',
      [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ]];
    yield [
      'Individual',
      [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
      'email' => 'fred@example.com',
    ]];
  }

}
