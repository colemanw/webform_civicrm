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

  /**
   * Test webform submission with locked address fields.
   */
  public function testLockedAddressSubmission() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_address', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->checkField('Country');
    $this->getSession()->getPage()->selectFieldOption('Address Location', 'Main');
    $this->getSession()->getPage()->selectFieldOption('Is Primary', 'No');

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));

    // Edit contact element and enable select widget.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
      'hide_fields' => 'address',
    ];
    $this->editContactElement($editContact);

    //Submit the form.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
    ];
    $this->postSubmission($this->webform, $edit);

    $countryID = $this->utils->wf_civicrm_api('Country', 'getvalue', [
      'return' => "id",
      'name' => "Canada",
    ]);
    $stateID = $this->utils->wf_civicrm_api('StateProvince', 'getvalue', [
      'return' => "id",
      'name' => "Ontario",
    ]);
    $address = $this->utils->wf_civicrm_api('Address', 'get', [
      'sequential' => 1,
      'contact_id' => $this->rootUserCid,
    ]);
    // Verify if the address is not modified on the contact.
    $this->assertEquals(1, $address['count']);
    $this->assertEquals('Toronto', $address['values'][0]['city']);
    $this->assertEquals($stateID, $address['values'][0]['state_province_id']);
    $this->assertEquals($countryID, $address['values'][0]['country_id']);
    $this->assertEquals(1, $address['values'][0]['is_primary']);
  }

}
