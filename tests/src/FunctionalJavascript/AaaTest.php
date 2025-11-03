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

  protected $defaultTheme = 'starterkit_theme';

  /**
   * Tests the thing
   */
  public function testAaa(): void {
    \Drupal::service('theme_installer')->install(['olivero', 'claro']);
    $this->config('system.theme')
      ->set('default', 'olivero')
      ->set('admin', 'claro')
      ->save();

    $this->drupalLogin($this->rootUser);
    $this->assertNotEmpty(\Drupal::currentUser()->id());
  }

}
