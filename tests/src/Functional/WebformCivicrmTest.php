<?php

namespace Drupal\Tests\webform_civicrm\Functional;

use Drupal\Core\Url;

/**
 * Tests Webform CiviCRM.
 *
 * @group webform_civicrm
 */
final class WebformCivicrmTest extends CiviCrmTestBase {

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
  public function testCreateWebform() {
    $this->drupalLogin($this->testUser);
    $this->drupalGet(Url::fromRoute('entity.webform.collection'));
    $this->clickLink('Add webform');
    $this->getSession()->getPage()->fillField('Title', 'CiviCRM Webform Test');
    $this->getSession()->getPage()->fillField('Machine-readable name', 'civicrm_webform_test');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->pageTextContainsOnce('Webform CiviCRM Webform Test created.');
    $this->getSession()->getPage()->clickLink('Settings');
    $this->getSession()->getPage()->clickLink('CiviCRM');
    $this->getSession()->getPage()->checkField('Enable CiviCRM Processing');
    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error. Please try again later.');
  }

}
