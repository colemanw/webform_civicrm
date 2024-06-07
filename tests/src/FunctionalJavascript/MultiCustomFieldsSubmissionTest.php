<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: single contact + custom fields.
 *
 * @group webform_civicrm
 */
final class MultiCustomFieldsSubmissionTest extends WebformCivicrmTestBase {

  /**
   * @var int
   */
  private $_totalMV;

  /**
   * @var array
   */
  private $_customFields;

  /**
   * @var int
   */
  private $_cgID;

  /**
   * @var array
   */
  private $_contact1;
  private $_contact2;

  private function createMultiValueCustomFields() {
    $this->_customFields = [];
    $params = [
      'title' => "Monthly Data",
      'extends' => 'Contact',
      'is_multiple' => 1,
      'style' => "Tab with table",
    ];
    $result = $this->utils->wf_civicrm_api('CustomGroup', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_cgID = $result['id'];

    $params = [
      'custom_group_id' => $this->_cgID,
      'label' => 'Month',
      'name' => 'month',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
    ];
    $result = $this->utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['month'] = $result['id'];

    $result = civicrm_api3('OptionGroup', 'create', [
      'name' => "data",
      'title' => "Data",
      'data_type' => "String",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "data",
      'name' => "100",
      'label' => "100",
      'value' => 100,
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "data",
      'name' => "200",
      'label' => "200",
      'value' => 200,
      'is_default' => 0,
      'weight' => 1,
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => $this->_cgID,
      'label' => "Data",
      'name' => 'data',
      'html_type' => "Radio",
      'data_type' => "String",
      'option_group_id' => "data",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['data'] = $result['id'];

    $result = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => $this->_cgID,
      'label' => "Consultant",
      'name' => 'consultant',
      'html_type' => "Autocomplete-Select",
      'data_type' => "ContactReference",
      'is_active' => 1,
    ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->_customFields['consultant'] = $result['id'];
  }

  public function testAnonymousSubmitWithContribution() {
    $payment_processor = $this->createPaymentProcessor();

    $this->_totalMV = 1;
    $this->createMultiValueCustomFields();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->enableCustomFields(1, TRUE);
    $this->htmlOutput();

    //Configure Contribution tab.
    $this->configureContributionTab();
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $this->assertSession()->checkboxChecked('Contribution Amount');

    $this->getSession()->getPage()->selectFieldOption('Payment Processor', $payment_processor['id']);

    $this->saveCiviCRMSettings();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    $params = [
      'First Name' => 'The',
      'Last Name' => 'Weeknd',
      'Email' => 'theweeknd@example.com',
      'Month' => 'January',
      'civicrm_1_contact_1_cg1_custom_2' => 200,
    ];

    $this->submitWebform($params, 'Next >');
    $this->htmlOutput();
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->getSession()->getPage()->fillField('Contribution Amount', 20);

    // Wait for the credit card form to load in.
    $this->assertSession()->waitForField('credit_card_number');
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '20.00');

    $billingValues = [
      'first_name' => 'The',
      'last_name' => 'Weeknd',
      'street_address' => 'Raymond James Stadium',
      'city' => 'Tampa',
      'country' => '1228',
      'state_province' => '1008',
      'postal_code' => '33607',
    ];
    $this->fillBillingFields($billingValues);

    $this->getSession()->getPage()->fillField('Card Number', '4222222222222220');
    $this->getSession()->getPage()->fillField('Security Code', '123');
    $this->getSession()->getPage()->selectFieldOption($this->getCreditCardMonthFieldName(), '11');
    $this_year = date('Y');
    $this->getSession()->getPage()->selectFieldOption('credit_card_exp_date[Y]', $this_year + 1);
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $cid = $this->utils->wf_civicrm_api('Contact', 'getsingle', [
      'first_name' => $params['First Name'],
      'last_name' => $params['Last Name'],
    ])['contact_id'];

    // Ensure contribution is created on the contact.
    $contribution = $this->utils->wf_civicrm_api('Contribution', 'getsingle', [
      'contact_id' => $cid,
    ]);
    $this->assertEquals($contribution["total_amount"], '20.00');

    $customValues = $this->utils->wf_civicrm_api('CustomValue', 'get', [
      'entity_id' => $cid,
    ])['values'];
    // Assert only 1 multivalue record is created.
    unset($customValues[$this->_customFields['month']]['latest'], $customValues[$this->_customFields['data']]['latest']);
    $monthValueCount = array_count_values($customValues[$this->_customFields['month']]);
    $dataValueCount = array_count_values($customValues[$this->_customFields['data']]);
    $this->assertEquals($monthValueCount["January"], 1);
    $this->assertEquals($dataValueCount["200"], 1);
  }

  /**
   * Submit webform with 3 contact reference fields.
   */
  public function testContactRefSubmission() {
    $this->_totalMV = 5;
    $this->createMultiValueCustomFields();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption("number_of_contacts", $this->_totalMV);
    $this->htmlOutput();

    $this->enableCustomFields(1);
    $this->htmlOutput();
    foreach ([2, 3, 4, 5] as $c) {
      $this->getSession()->getPage()->clickLink("Contact {$c}");
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->selectFieldOption("{$c}_contact_type", 'Household');
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->checkField("civicrm_{$c}_contact_1_contact_existing");
      $this->assertSession()->checkboxChecked("civicrm_{$c}_contact_1_contact_existing");
    }
    $this->saveCiviCRMSettings();

    $this->_hh = [];
    foreach ([2, 3, 4, 5] as $c) {
      // Create 4 households to select on the ref fields while submitting the webform.
      $params = ['household_name' => "HH{$c}"];
      $this->_hh[$c] = $this->createHousehold($params);
      $this->drupalGet($this->webform->toUrl('edit-form'));
      $editContact = [
        'selector' => "edit-webform-ui-elements-civicrm-{$c}-contact-1-contact-existing-operations",
        'widget' => 'Select',
        'default' => '- None -',
      ];
      $this->editContactElement($editContact);
    }
    $this->htmlOutput();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    //Submit only 3 multi-value fields. Contact 1 is default to current user.
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', $this->_hh[2]['id']);
    $this->getSession()->getPage()->selectFieldOption('civicrm_3_contact_1_contact_existing', $this->_hh[3]['id']);
    $params = [];
    $params['civicrm_1_contact_1_cg1_custom_1'] = 'Jan';
    $params['civicrm_1_contact_1_cg1_custom_2'] = 100;
    $params['civicrm_1_contact_2_cg1_custom_1'] = 'Feb';
    $params['civicrm_1_contact_2_cg1_custom_2'] = 200;
    $params['civicrm_1_contact_3_cg1_custom_1'] = 'March';
    $params['civicrm_1_contact_3_cg1_custom_2'] = 200;
    $this->submitWebform($params);
    $this->verifyCustomValues($params);
  }

  /**
   * Test submitting Custom Fields
   */
  public function testSubmitWebform() {
    $this->_totalMV = 5;
    $this->createMultiValueCustomFields();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption("number_of_contacts", $this->_totalMV);
    $this->htmlOutput();

    $this->enableCustomFields(1);
    $this->htmlOutput();

    $this->getSession()->getPage()->clickLink('Contact 2');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('civicrm_2_contact_1_contact_existing');
    $this->assertSession()->checkboxChecked('civicrm_2_contact_1_contact_existing');
    $this->enableCustomFields(2);

    $this->getSession()->getPage()->clickLink('Contact 3');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('civicrm_3_contact_1_contact_existing');
    $this->assertSession()->checkboxChecked('civicrm_3_contact_1_contact_existing');
    $this->enableCustomFields(3);

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-3-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
      'default' => '- None -',
    ];
    $this->editContactElement($editContact);

    //Create 2 contacts to fill on the webform.
    $this->_contact1 = $this->createIndividual();
    $this->_contact2 = $this->createIndividual();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    //Enter values for the custom fields and save.
    $months = ['Jan', 'Feb', 'March', 'April', 'May'];
    $data = [100, 200];
    $params = [];
    for ($c = 1; $c <= $this->_totalMV; $c++) {
      if ($c < 4) {
        for ($i = 1; $i <= $this->_totalMV; $i++) {
          $params["civicrm_{$c}_contact_{$i}_cg1_custom_1"] = $months[array_rand($months)];
          $params["civicrm_{$c}_contact_{$i}_cg1_custom_2"] = $data[array_rand($data)];
        }
      }
      else {
        $params["civicrm_{$c}_contact_1_contact_first_name"] = substr(sha1(rand()), 0, 7);
        $params["civicrm_{$c}_contact_1_contact_last_name"] = substr(sha1(rand()), 0, 7);
      }
    }
    $this->submitWebform($params);

    $this->verifyCustomValues($params);

    //Visit the webform again.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Verify if field values are loaded on the webform.
    foreach ($params as $key => $val) {
      if (strpos($key, 'civicrm_1_contact') !== false) {
        if (strpos($key, 'custom_1') !== false) {
          $this->assertSession()->fieldValueEquals($key, $val);
        }
        elseif (strpos($key, 'custom_2') !== false) {
          $this->assertSession()->elementExists('css', '[name="' . $key . '"][value=' . $val . ']')->isChecked();
        }
      }
    }

    //Update values for the custom fields and save.
    $months = ['JanEdited', 'FebEdited', 'MarchEdited', 'AprilEdited', 'MayEdited'];
    $data = [100, 200];
    $params = [];
    for ($c = 1; $c <= $this->_totalMV; $c++) {
      if ($c < 4) {
        for ($i = 1; $i <= $this->_totalMV; $i++) {
          $params["civicrm_{$c}_contact_{$i}_cg1_custom_1"] = $months[array_rand($months)];
          $params["civicrm_{$c}_contact_{$i}_cg1_custom_2"] = $data[array_rand($data)];
        }
      }
      else {
        $params["civicrm_{$c}_contact_1_contact_first_name"] = substr(sha1(rand()), 0, 7);
        $params["civicrm_{$c}_contact_1_contact_last_name"] = substr(sha1(rand()), 0, 7);
      }
    }
    $this->submitWebform($params);

    // Check if updated values are stored on the contact.
    $this->verifyCustomValues($params);
  }

  /**
   * Enable Custom Fields
   */
  private function enableCustomFields($c, $createOnly = FALSE) {
    $this->getSession()->getPage()->selectFieldOption("contact_{$c}_number_of_cg{$this->_cgID}", $this->_totalMV);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    // Enable custom fields.
    foreach ($this->_customFields as $id) {
      for ($i = 1; $i <= $this->_totalMV; $i++) {
        if ($createOnly) {
          $this->getSession()->getPage()->selectFieldOption("civicrm_{$c}_contact_{$i}_cg{$this->_cgID}_createmode", "Create Only");
        }

        $fldName = "civicrm_{$c}_contact_{$i}_cg{$this->_cgID}_custom_{$id}";
        if ($id == $this->_customFields['consultant']) {
          $this->getSession()->getPage()->selectFieldOption($fldName, "Contact {$i}");
        }
        else {
          $this->getSession()->getPage()->checkField($fldName);
          $this->assertSession()->checkboxChecked($fldName);
        }
      }
    }
  }

  /**
   * Submit the webform with specified params.
   *
   * @param array $params
   */
  private function submitWebform($params, $submit = 'Submit') {
    if (!empty($this->_contact1)) {
      $this->fillContactAutocomplete('token-input-edit-civicrm-2-contact-1-contact-existing', $this->_contact1['first_name']);
      $this->fillContactAutocomplete('token-input-edit-civicrm-3-contact-1-contact-existing', $this->_contact2['first_name']);
    }

    foreach ($params as $key => $val) {
      $this->addFieldValue($key, $val);
      if (strpos($key, 'custom_2') !== false) {
        $this->getSession()->getPage()->selectFieldOption($key, $val);
      }
    }

    $this->getSession()->getPage()->pressButton($submit);
    $this->assertPageNoErrorMessages();
    if ($submit == 'Submit') {
      $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    }
  }

  /**
   * Verify stored values.
   */
  private function verifyCustomValues($params) {
    $customValues = $this->utils->wf_civicrm_api('CustomValue', 'get', [
      'entity_id' => $this->rootUserCid,
    ])['values'];
    $monthValues = $customValues[$this->_customFields['month']];
    $dataValues = $customValues[$this->_customFields['data']];
    $contactRefValues = $customValues[$this->_customFields['consultant']];
    //Assert Household Data submission
    if (!empty($this->_hh)) {
      unset($monthValues['entity_id'], $monthValues['latest'], $monthValues['id']);
      //Ensure 5 custom field value is created, with only 3 having the values.
      $this->assertEquals(count($monthValues), 5);
      $this->assertEquals($monthValues[1], 'Jan');
      $this->assertEquals($monthValues[2], 'Feb');
      $this->assertEquals($monthValues[3], 'March');
      $this->assertEmpty($monthValues[4]);
      $this->assertEmpty($monthValues[5]);

      unset($dataValues['entity_id'], $dataValues['latest'], $dataValues['id']);
      //Ensure 5 custom field value is created, with only 3 having the values.
      $this->assertEquals(count($dataValues), 5);
      $this->assertEquals($dataValues[1], 100);
      $this->assertEquals($dataValues[2], 200);
      $this->assertEquals($dataValues[3], 200);
      $this->assertEmpty($dataValues[4]);
      $this->assertEmpty($dataValues[5]);

      unset($contactRefValues['entity_id'], $contactRefValues['latest'], $contactRefValues['id']);
      //Ensure 5 custom field value is created, with only 3 having the values.
      $this->assertEquals(count($contactRefValues), 5);
      $this->assertEquals($contactRefValues[1], $this->rootUserCid);
      $this->assertEquals($contactRefValues[2], $this->_hh[2]['id']);
      $this->assertEquals($contactRefValues[3], $this->_hh[3]['id']);
      $this->assertEmpty($contactRefValues[4]);
      $this->assertEmpty($contactRefValues[5]);
      return;
    }
    // Assert if submitted params are present in the custom values.
    $this->assertTrue(in_array($this->_contact1['id'], $contactRefValues));
    $this->assertTrue(in_array($this->_contact2['id'], $contactRefValues));

    for ($c = 1; $c <= $this->_totalMV; $c++) {
      $contact = current($this->utils->wf_civicrm_api('Contact', 'get', [
        'id' => $contactRefValues[$c],
      ])['values']);

      //We have entered multi value fields for only first 3 contacts.
      if ($c < 4) {
        for ($i = 1; $i <= $this->_totalMV; $i++) {
          if ($c == 2) {
            $cid = $this->_contact1['id'];
            $this->assertEquals($this->_contact1["first_name"], $contact['first_name']);
            $this->assertEquals($this->_contact1["last_name"], $contact['last_name']);
          }
          elseif ($c == 3) {
            $cid = $this->_contact2['id'];
            $this->assertEquals($this->_contact2["first_name"], $contact['first_name']);
            $this->assertEquals($this->_contact2["last_name"], $contact['last_name']);
          }
          $key = $i;
          if (!empty($cid)) {
            //Assert custom values stored on 2nd and 3rd contact.
            $customValues = $this->utils->wf_civicrm_api('CustomValue', 'get', [
              'entity_id' => $cid,
            ])['values'];
            $monthValues = $customValues[$this->_customFields['month']];
            $dataValues = $customValues[$this->_customFields['data']];
            unset($monthValues['entity_id'], $monthValues['latest'], $monthValues['id']);
            $monthValues = array_values($monthValues);

            unset($dataValues['entity_id'], $dataValues['latest'], $dataValues['id']);
            $dataValues = array_values($dataValues);
            $key--;
          }
          $this->assertEquals($params["civicrm_{$c}_contact_{$i}_cg1_custom_1"], $monthValues[$key]);
          $this->assertEquals($params["civicrm_{$c}_contact_{$i}_cg1_custom_2"], $dataValues[$key]);
        }
      }
      else {
        $this->assertEquals($params["civicrm_{$c}_contact_1_contact_first_name"], $contact['first_name']);
        $this->assertEquals($params["civicrm_{$c}_contact_1_contact_last_name"], $contact['last_name']);
      }
    }
  }

}
