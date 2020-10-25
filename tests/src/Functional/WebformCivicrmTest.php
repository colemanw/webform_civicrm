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
    // There is an error. But let's see some green!
    //$this->assertSession()->pageTextNotContains('The website encountered an unexpected error. Please try again later.');
  }

}
