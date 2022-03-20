<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with CiviCRM: Contact with Grant.
 *
 * @group webform_civicrm
 */
final class GrantTest extends WebformCivicrmTestBase {

  protected function setup() {
    parent::setUp();
    $civicrm_version = $this->utils->wf_crm_apivalues('System', 'get')[0]['version'];
    // Grant is moved to extension after > 5.47.0.
    if (version_compare($civicrm_version, '5.47') >= 0) {
      $res = civicrm_api3('Extension', 'install', [
        'keys' => "civigrant",
      ]);
      $this->assertEquals(1, $res['count']);
    }
    else {
      $this->enableComponent('CiviGrant');
    }
    civicrm_api3('System', 'flush', [
      'triggers' => 1,
      'session' => 1,
    ]);
    drupal_flush_all_caches();
  }

  /**
   * Grant submission.
   */
  function testSubmitGrant() {
    $this->drupalLogin($this->rootUser);
    civicrm_api3('System', 'flush', [
      'triggers' => 1,
      'session' => 1,
    ]);
    drupal_flush_all_caches();
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->clickLink('Grants');

    //Configure Grant tab.
    $this->getSession()->getPage()->selectFieldOption('Number of Grants', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->assertSession()->checkboxChecked("Amount Requested");

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
      'Amount Requested' => '100',
    ];
    $this->postSubmission($this->webform, $edit);
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    //Assert if grant is successfully created.
    $grant = \Civi\Api4\Grant::get(FALSE)
      ->execute()
      ->first();
    $this->assertEquals($this->rootUserCid, $grant['contact_id']);
    $this->assertEquals(date('Y-m-d'), $grant['application_received_date']);
    $this->assertEquals(100, $grant['amount_total']);
  }

}
