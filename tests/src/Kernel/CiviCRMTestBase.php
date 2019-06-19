<?php

namespace Drupal\Tests\webform_civicrm\Kernel;

use Drupal\KernelTests\KernelTestBase;

class CiviCRMTestBase extends KernelTestBase {

  protected function setUp() {
    parent::setUp();
    // the obvious choice would be to use \Drupal::service('civicrm')->initialize();
    // however this results in all kind of vfs errors. So the civicrm code is
    // instantiated directly
    $civicrmSettingsFile = DRUPAL_ROOT . "/sites/default/civicrm.settings.php";
    include_once $civicrmSettingsFile;
    include_once 'CRM/Core/Config.php';
    \CRM_Core_Config::singleton()->userSystem->setMySQLTimeZone();
  }

}