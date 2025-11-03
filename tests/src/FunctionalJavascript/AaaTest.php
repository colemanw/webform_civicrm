<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the thing
 */
#[Group('Aaa')]
#[RunTestsInSeparateProcesses]
class AaaTest extends WebDriverTestBase {

  /**
   * Tests the thing
   */
  public function testAaa(): void {
    $this->drupalLogin($this->rootUser);
    $this->assertNotEmpty(\Drupal::currentUser()->id());
  }

}
