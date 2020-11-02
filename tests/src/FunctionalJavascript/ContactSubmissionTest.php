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
   */
  public function testEnableCiviCrmHandler() {
    $this->drupalLogin($this->testUser);
    $this->drupalGet($this->webform->toUrl('settings'));
    $this->getSession()->getPage()->clickLink('CiviCRM');
    $this->getSession()->getPage()->checkField('Enable CiviCRM Processing');
    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertSession()->checkboxChecked('Existing Contact');
    $this->assertSession()->checkboxChecked('First Name');
    $this->assertSession()->checkboxChecked('Last Name');
    $this->getSession()->getPage()->clickLink('Build');
    $this->assertSession()->pageTextContains('civicrm_1_contact_1_fieldset_fieldset');
    $this->assertSession()->pageTextContains('civicrm_1_contact_1_contact_existing');
    $this->assertSession()->pageTextContains('civicrm_1_contact_1_contact_first_name');
    $this->assertSession()->pageTextContains('civicrm_1_contact_1_contact_last_name');

    $this->drupalGet($this->webform->toUrl('canonical'));
    $error_messages = $this->getSession()->getPage()->findAll('css', '.messages.messages--error');
    $this->assertCount(0, $error_messages);

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $result = civicrm_api('contact', 'get', [
      'version' => 3,
      'sequential' => 1,
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
    ]);
    $this->assertArrayHasKey('count', $result, var_export($result, TRUE));
    $this->assertEquals(1, $result['count'], var_export($result, TRUE));
    $contact = $result['values'][0];
    $this->assertEquals('Individual', $contact['contact_type']);
    $this->assertEquals('Pabst, Frederick', $contact['sort_name']);
  }

}
