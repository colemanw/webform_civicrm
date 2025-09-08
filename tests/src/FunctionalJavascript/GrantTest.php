<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Civi\Api4\Grant;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;

/**
 * Tests submitting a Webform with CiviCRM: Contact with Grant.
 *
 * @group webform_civicrm
 */
final class GrantTest extends WebformCivicrmTestBase {

  /**
   * @var int
   */
  private $grant_type_id;

  protected function setUp(): void {
    parent::setUp();
    $civicrm_version = $this->utils->wf_crm_apivalues('System', 'get')[0]['version'];
    // Grant is moved to extension after > 5.47.0.
    if (version_compare($civicrm_version, '5.47') >= 0) {
      $result = $this->utils->wf_civicrm_api('Extension', 'install', [
        'keys' => "civigrant",
      ]);
      $this->assertEquals(1, $result['count']);
    }
    else {
      $this->enableComponent('CiviGrant');
    }
    $result = $this->utils->wf_civicrm_api('OptionValue', 'create', [
      'option_group_id' => "grant_type",
      'label' => "Emergency Grant Type",
      'name' => "Emergency Grant Type",
    ]);
    $this->grant_type_id = $result['values'][$result['id']]['value'];
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    drupal_flush_all_caches();
  }

  /**
   * Grant submission.
   */
  function testSubmitGrant() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->clickLink('Grants');

    //Configure Grant tab.
    $this->getSession()->getPage()->selectFieldOption('Number of Grants', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('Grant Type', $this->grant_type_id);
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
    $grant = Grant::get(FALSE)
      ->execute()
      ->first();
    $this->assertEquals($this->rootUserCid, $grant['contact_id']);
    $this->assertEquals(date('Y-m-d'), $grant['application_received_date']);
    $this->assertEquals(100, $grant['amount_total']);
    $this->assertEquals($this->grant_type_id, $grant['grant_type_id']);
  }

}
