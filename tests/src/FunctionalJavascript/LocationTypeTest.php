<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submission of loc types.
 *
 * @group webform_civicrm
 */
final class LocationTypeTest extends WebformCivicrmTestBase {

  protected function setUp() {
    parent::setUp();
    $this->utils->wf_civicrm_api('Address', 'create', [
      'contact_id' => $this->rootUserCid,
      'location_type_id' => "Main",
      'is_primary' => 1,
      'street_address' => "35th Street",
      'city' => "Toronto",
      'country_id' => "CA",
      'state_province_id' => "Ontario",
    ]);
  }

  /**
   * Submit address with primary flag set to 0.
   */
  public function testAddressSubmissionNoPrimary() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_address', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->checkField('Country');
    $this->getSession()->getPage()->selectFieldOption('Address Location', 'Home');
    $this->getSession()->getPage()->selectFieldOption('Is Primary', 'No');

    $this->saveCiviCRMSettings();

    //Submit the form.
    $this->drupalGet($this->webform->toUrl('canonical'));

    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
      'Street Address' => '9th Street',
      'City' => 'Newark',
      'Postal Code' => '12345',
      'Country' => 1228,
      'State/Province' => 'NJ',
    ];
    $this->postSubmission($this->webform, $edit);

    $address = $this->utils->wf_civicrm_api('Address', 'get', [
      'sequential' => 1,
      'contact_id' => $this->rootUserCid,
    ]);
    // Verify if the new address is not marked as primary.
    $this->assertEquals(2, $address['count']);
    $this->assertEquals('Toronto', $address['values'][0]['city']);
    $this->assertEquals(1, $address['values'][0]['is_primary']);

    $this->assertEquals('Newark', $address['values'][1]['city']);
    $this->assertEquals(0, $address['values'][1]['is_primary']);
  }

}
