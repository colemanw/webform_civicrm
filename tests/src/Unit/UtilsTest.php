<?php

namespace Drupal\Tests\webform_civicrm\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\webform_civicrm\Utils;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @group webform_civicrm
 */
class UtilsTest extends UnitTestCase {

  public function testWfCrmExplodeKey() {
    $requestStack = new RequestStack();
    $container = new ContainerBuilder();
    $container->set('request_stack', $requestStack);
    $utils = new Utils($requestStack);
    // Set the container for Drupal::service to work correctly.
    \Drupal::setContainer($container);
    $container->set('webform_civicrm.utils', $utils);

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
