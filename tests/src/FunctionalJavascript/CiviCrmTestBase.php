<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Database\Database;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

// Requires patching for civicrm-core.
// @see https://github.com/civicrm/civicrm-core/pull/18843
// @see https://lab.civicrm.org/dev/core/-/issues/2140
// @todo move into civicrm-drupal-8 package.
abstract class CiviCrmTestBase extends WebDriverTestBase {

  protected $defaultTheme = 'classy';

  protected static $modules = [
    'block',
    'civicrm',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->drupalPlaceBlock('page_title_block');
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('local_actions_block');
  }

  /**
   * {@inheritdoc}
   */
  protected function changeDatabasePrefix() {
    parent::changeDatabasePrefix();
    $connection_info = Database::getConnectionInfo('default');
    Database::addConnectionInfo('civicrm_test', 'default', $connection_info['default']);
    Database::addConnectionInfo('civicrm', 'default', $connection_info['default']);
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareSettings() {
    parent::prepareSettings();

    // Set the test environment variables for CiviCRM.
    $filename = $this->siteDirectory . '/settings.php';
    chmod($filename, 0666);

    $constants = <<<CONSTANTS

if (!defined('CIVICRM_CONTAINER_CACHE')) {
  define('CIVICRM_CONTAINER_CACHE', 'never');
}
if (!defined('CIVICRM_TEST')) {
  define('CIVICRM_TEST', 'never');
}

CONSTANTS;

    file_put_contents($filename, $constants, FILE_APPEND);
  }

}
