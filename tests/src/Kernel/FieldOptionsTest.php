<?php

namespace Drupal\Tests\webform_civicrm\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;

/**
 * @group webform_civicrm
 *
 * @todo CiviCRM does not support SQLite.
 */
class FieldOptionsTest extends KernelTestBase {

  protected static $modules = [
    'user',
    'civicrm',
    'webform',
    'webform_civicrm',
  ];

  protected function bootEnvironment() {
    parent::bootEnvironment();

    Database::addConnectionInfo('civicrm_test', 'default', $this->getDatabaseConnectionInfo()['default']);

  }

  protected function setUp(): void {
    $this->markTestSkipped('Requires MySQL');
    parent::setUp();

    module_load_install('civicrm');
    civicrm_install();

    $this->container->get('civicrm')->initialize();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $conn = Database::getConnection('default', 'civicrm_test');
    $database = $conn->getConnectionOptions()['database'];
    // Todo: get this working when db name passed in as an argument.
    $conn->query("DROP DATABASE $database");
    $conn->destroy();
    parent::tearDown();
  }

  /**
   * @dataProvider getDataprovider
   */
  public function testGet(array $field, string $context, array $data) {
    $field_options = $this->container->get('webform_civicrm.field_options');
    $options = $field_options->get($field, $context, $data);
  }

  public static function getDataprovider() {
    yield [
      ['form_key' => 'civicrm_1_contact_1_email_email'],
      'live_options',
      []
    ];
  }

}
