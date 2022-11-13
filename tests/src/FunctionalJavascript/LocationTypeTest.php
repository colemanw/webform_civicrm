<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submission of loc types.
 *
 * @group webform_civicrm
 */
final class LocationTypeTest extends WebformCivicrmTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'webform',
    'webform_ui',
    'webform_civicrm',
    'webform_civicrm_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $configSchemaCheckerExclusions = [
    'webform.webform.civicrm_webform_test',
    'webform.webform.update_contact_details',
  ];

  protected function setUp(): void {
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

  public function createWebformWithAddress($locType = 'Home') {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_address', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->checkField('Country');
    $this->getSession()->getPage()->selectFieldOption('Address Location',$locType);
    $this->getSession()->getPage()->selectFieldOption('Is Primary', 'No');

    $this->saveCiviCRMSettings();
  }

  public function submitAndVerifyAddressSubmission() {
    //Submit the form.
    $this->drupalGet($this->webform->toUrl('canonical'));

    $edit = [
      'First Name' => 'Frederick',
      'Last Name' => 'Pabst',
      'Street Address' => '9th Street',
      'City' => 'Newark',
      'Postal Code' => '12345',
      'Country' => 1228,
      'State/Province' => $this->utils->wf_crm_state_abbr('NJ', 'id'), // New Jersey
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
   * Submit address with primary flag set to 0.
   */
  public function testAddressSubmissionNoPrimary() {
    $this->createWebformWithAddress('Home');
    $this->submitAndVerifyAddressSubmission();
  }

  /**
   * Test submission of billing address without enabling contribution.
   */
  public function testBillingAddressWithoutContribution() {
    $this->createWebformWithAddress('Billing');
    $this->submitAndVerifyAddressSubmission();
  }

  /**
   * Test webform submission with locked address fields.
   */
  public function testLockedAddressSubmission() {
    $this->createWebformWithAddress('Main');
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

  /**
   * Verify if contact details are updated correctly when the
   * webform is submitted using checksum in the URL.
   */
  public function testAddressUpdateUsingChecksum() {
    $this->webform = $this->loadWebform('update_contact_details');
    $contact = $this->createIndividual([
      'first_name' => 'Pabst',
      'last_name' => 'Anthony',
    ]);
    $address = $this->utils->wf_civicrm_api('Address', 'create', [
      'contact_id' => $contact['id'],
      'location_type_id' => "Home",
      'is_primary' => 1,
      'street_address' => "123 Defence Colony",
      'city' => "Edmonton",
      'country_id' => "CA",
      'state_province_id' => "Alberta",
      'postal_code' => 11111,
    ]);
    $contact_cs = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contact['id']);

    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['cid1' => $contact['id'], 'cs' => $contact_cs]]));
    $this->assertPageNoErrorMessages();

    // Check if name fields are pre populated with existing values.
    $this->assertSession()->fieldValueEquals('First Name', $contact['first_name']);
    $this->assertSession()->fieldValueEquals('Last Name', $contact['last_name']);

    // Update the last name
    $this->getSession()->getPage()->fillField('Last Name', 'Morissette');
    $this->getSession()->getPage()->pressButton('Next >');

    // Change the street & city value in the address fields.
    $address = [
      'Street Address' => '123 Defence Colony Updated',
      'City' => 'Calgary',
    ];
    $this->getSession()->getPage()->fillField('Street Address', '123 Defence Colony Updated');
    $this->getSession()->getPage()->fillField('City', 'Calgary');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to Update Contact Details.');

    // Assert if last name and city is updated.
    $contact_result = $this->utils->wf_civicrm_api('contact', 'get', [
      'sequential' => 1,
      'id' => $contact['id'],
    ]);
    $result_debug = var_export($contact_result, TRUE);
    $this->assertArrayHasKey('count', $contact_result, $result_debug);
    $this->assertEquals(1, $contact_result['count'], $result_debug);

    $expected_values = [
      'first_name' => 'Pabst',
      'last_name' => 'Morissette',
      'street_address' => "123 Defence Colony Updated",
      'city' => "Calgary",
      'country_id' => 1039,
      'state_province_id' => $this->utils->wf_crm_state_abbr('AB', 'id', 1039),
      'postal_code' => 11111,
    ];
    foreach ($expected_values as $key => $value) {
      $this->assertEquals($value, $contact_result['values'][0][$key], $result_debug);
    }
  }

}
