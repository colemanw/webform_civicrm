<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Tests\webform\Traits\WebformBrowserTestTrait;

/**
 * Tests Webform CiviCRM.
 *
 * @group webform_civicrm
 */
final class WebformCivicrmTest extends CiviCrmTestBase {

  use WebformBrowserTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'webform',
    'webform_ui',
    'webform_civicrm',
  ];

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
   * The test user.
   *
   * @var \Drupal\user\Entity\User
   */
  private $testUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->testUser = $this->createUser([
      'administer CiviCRM',
      'access CiviCRM',
      'access administration pages',
      'access webform overview',
      'administer webform',
    ]);
  }

  /**
   * Test creating a Webform.
   */
  public function testEnableCiviCrmHandler() {
    $this->drupalLogin($this->testUser);

    $webform = $this->createWebform([
      'id' => 'civicrm_webform_test',
      'title' => 'CiviCRM Webform Test',
    ]);
    $this->drupalGet($webform->toUrl('settings'));
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

    $this->drupalGet($webform->toUrl('canonical'));
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
