<?php

namespace Drupal\Tests\webform_civicrm\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * @group webform_civicrm
 */
class UtilsTest extends UnitTestCase {

  public function testWfCrmExplodeKey() {
    $utils = \Drupal::service('webform_civicrm.utils');
    $this->assertNull(
      $utils->wf_crm_explode_key('not_even_remotely_valid')
    );

    $this->assertEquals([
      'civicrm',
      '1',
      'contact',
      '1',
      'email',
      'email'
    ], $utils->wf_crm_explode_key('civicrm_1_contact_1_email_email'));
  }

}
