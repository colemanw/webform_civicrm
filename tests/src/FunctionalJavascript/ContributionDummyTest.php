<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contribution with Line Items and Sales Tax
 *
 * @group webform_civicrm
 */
final class ContributionDummyTest extends WebformCivicrmTestBase {

  /**
   * Test One-page donation
   */
  public function testOnePageDonation() {
    $payment_processor = $this->createPaymentProcessor();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $params = [
      'payment_processor_id' => $payment_processor['id'],
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");

    $this->getSession()->getPage()->clickLink('Additional Settings');
    $this->getSession()->getPage()->checkField("Disable Contact Paging");
    $this->assertSession()->checkboxChecked("Disable Contact Paging");

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertSession()->pageTextNotContains('contact_pagebreak');

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-items .civicrm_1_contribution_1', 'Contribution Amount');

    $this->fillCardAndSubmit();

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('10.00', $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    $sid = $this->getLastSubmissionId($this->webform);
    $this->drupalGet(Url::fromRoute('entity.webform_submission.canonical', [
      'webform' => $this->webform->id(),
      'webform_submission' => $sid,
    ]));
    $this->htmlOutput();
    $title = $this->webform->label();
    $contact = $this->utils->wf_civicrm_api('contact', 'get', [
      'sequential' => 1,
      'first_name' => 'Frederick',
    ]);
    $displayName = $contact['values'][0]['display_name'];

    $this->assertSession()->pageTextContains("{$title}: Submission #{$sid} by {$displayName}");
    $this->assertSession()->linkExists("View {$displayName}");
    $this->assertSession()-> linkNotExists("View Activity");
    $this->assertSession()->linkExists('View Contribution');
    $this->assertSession()-> linkNotExists('View Participant');
  }

  /**
   * Test sameas billing address checkbox.
   */
  public function testBillingSameAs() {
    $payment_processor = $this->createPaymentProcessor();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Enable Address fields.
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_address', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Country');
    $this->assertSession()->checkboxChecked('Country');

    $params = [
      'payment_processor_id' => $payment_processor['id'],
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");
    $this->getSession()->getPage()->checkField("Same As");
    $this->assertSession()->checkboxChecked("Same As");
    $this->saveCiviCRMSettings();

    $this->drupalLogout();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $countryID = $this->utils->wf_civicrm_api4('Country', 'get', [
      'where' => [
        ['name', '=', 'United States'],
      ],
    ], 0)['id'];
    $stateProvinceID = $this->utils->wf_civicrm_api4('StateProvince', 'get', [
      'where' => [
        ['abbreviation', '=', 'NJ'],
        ['country_id', '=', $countryID],
      ],
    ], 0)['id'];
    $billingValues = [
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
      'street_address' => '123 Milwaukee Ave',
      'city' => 'Milwaukee',
      'country_id' => $countryID,
      'state_province_id' => $stateProvinceID,
      'postal_code' => '53177',
    ];
    $this->getSession()->getPage()->fillField('First Name', $billingValues['first_name']);
    $this->getSession()->getPage()->fillField('Last Name', $billingValues['last_name']);
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->fillField('Street Address', $billingValues['street_address']);
    $this->getSession()->getPage()->fillField('City', $billingValues['city']);
    $this->getSession()->getPage()->fillField('Postal Code', $billingValues['postal_code']);
    $this->getSession()->getPage()->selectFieldOption('Country', $billingValues['country_id']);
    $this->getSession()->wait(1000);
    $this->getSession()->getPage()->selectFieldOption('State/Province', $billingValues['state_province_id']);
    $this->getSession()->getPage()->pressButton('Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-items .civicrm_1_contribution_1', 'Contribution Amount');

    $this->fillCardAndSubmit($billingValues);

    // Verify 2 address are created and has same values.
    unset($billingValues['first_name'], $billingValues['last_name']);
    $api_result = $this->utils->wf_civicrm_api('address', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(2, $api_result['count']);
    foreach ($api_result['values'] as $key => $address) {
      foreach ($billingValues as $field => $value) {
        $this->assertEquals($value, $address[$field]);
      }
    }
  }

  public function testSubmitContribution() {
    $payment_processor = $this->createPaymentProcessor();
    $this->createMembershipType(100, FALSE, 'Basic');
    $this->createMembershipType(200, FALSE, 'Advanced');

    // Attach Sales Tax to Financial Type 2 = Member Dues; Default value for Sales Tax Rate = 5%
    $this->setupSalesTax(2, $accountParams = []);

    // Create Financial Type Product Purchase and Attach Sales Tax to it
    $this->createFinancialType('Product Purchase');
    $this->setupSalesTax(5, $accountParams = []);

    // Create a second individual contact cid2
    $cid2 = $this->createIndividual(['first_name' => 'Mark', 'last_name' => 'Cooper'])['id'];

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->htmlOutput();
    $this->getSession()->getPage()->clickLink('2. Contact 2');
    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_existing");

    $params = [
      'payment_processor_id' => $payment_processor['id'],
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");
    $el = $this->getSession()->getPage()->findField('Payment Processor');
    $opts = $el->findAll('css', 'option');
    $this->assertCount(3, $opts, 'Payment processor values: ' . implode(', ', array_map(static function(NodeElement $el) {
        return $el->getValue();
      }, $opts)));

    $this->enableBillingSection();
    $this->getSession()->getPage()->selectFieldOption('lineitem_1_number_of_lineitem', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_1_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_1_contribution_line_total");
    $this->getSession()->getPage()->checkField("civicrm_1_lineitem_2_contribution_line_total");
    $this->assertSession()->checkboxChecked("civicrm_1_lineitem_2_contribution_line_total");
    // Set the Financial Type for the second line item to Product Purchase (which has Sales Tax on it).
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_lineitem_2_contribution_financial_type_id', 5);
    // Configure Membership tab.
    $this->getSession()->getPage()->clickLink('Memberships');
    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', 'Basic');
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('membership_2_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_membership_1_membership_membership_type_id', 'Advanced');
    $this->htmlOutput();

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical',  ['query' => ['cid2' => $cid2]]));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');
    $filename = 'loaded_webform' . substr(sha1(rand()), 0, 7) .'.png';
    $this->createScreenshot($this->htmlOutputDirectory . $filename);

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->fillField('Line Item Amount', '20.00');
    $this->getSession()->getPage()->fillField('Line Item Amount 2', '29.50');

    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-first-name', 'Mark');
    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-last-name', 'Cooper');
    $this->addFieldValue('civicrm_2_contact_1_contact_first_name', 'MarkUpdated');
    $this->addFieldValue('civicrm_2_contact_1_contact_last_name', 'CooperUpdated');
    $this->getSession()->getPage()->pressButton('Next >');

    $this->assertSession()->waitForField('edit-wizard-prev');
    $this->getSession()->getPage()->pressButton('edit-wizard-prev');
    $this->assertSession()->waitForField('edit-civicrm-2-contact-1-contact-first-name');
    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-first-name', 'MarkUpdated');
    $this->assertFieldValue('edit-civicrm-2-contact-1-contact-last-name', 'CooperUpdated');
    $this->getSession()->getPage()->pressButton('Next >');

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');

    // Contribution Amount + Line1 + Line2 + Mem1 + Mem2
    // Amounts = 10 + 20.00 + 29.50 + 100.00 + 200.00 = 359.5
    // Taxes = 1.48 + 5 + 10 = 16.48
    // Total = 359.5 + 16.48 = 375.98
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '375.98');

    $this->htmlOutput();

    $this->fillCardAndSubmit();

    $membership = $this->utils->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
    ])['values'];
    $adminCid = $this->getUFMatchRecord($this->rootUser->id())['contact_id'];
    $this->assertEquals($adminCid, $membership[0]['contact_id']);
    $this->assertEquals('Basic', $membership[0]['membership_name']);

    $this->assertEquals($cid2, $membership[1]['contact_id']);
    $this->assertEquals('Advanced', $membership[1]['membership_name']);

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('375.98', $contribution['total_amount']);
    $contribution_total_amount = $contribution['total_amount'];
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    // Also retrieve tax_amount (have to ask for it to be returned):
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
      'return' => 'tax_amount',
    ]);
    $contribution = reset($api_result['values']);
    $this->assertEquals('16.48', $contribution['tax_amount']);
    $tax_total_amount = $contribution['tax_amount'];

    $contriPriceFieldID = $this->utils->wf_civicrm_api('PriceField', 'get', [
      'sequential' => 1,
      'price_set_id' => 'default_contribution_amount',
      'options' => ['limit' => 1],
    ])['id'] ?? NULL;
    $membershipPriceFieldID = $this->utils->wf_civicrm_api('PriceField', 'get', [
      'sequential' => 1,
      'price_set_id' => 'default_membership_type_amount',
      'options' => ['limit' => 1],
    ])['id'] ?? NULL;

    $api_result = $this->utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);

    // Assert line item records.
    $this->assertEquals('100.00', $api_result['values'][0]['line_total']);
    $this->assertEquals('2', $api_result['values'][0]['financial_type_id']);
    $this->assertEquals('5.00', $api_result['values'][0]['tax_amount']);
    $this->assertEquals($membershipPriceFieldID, $api_result['values'][0]['price_field_id']);

    $this->assertEquals('200.00', $api_result['values'][1]['line_total']);
    $this->assertEquals('2', $api_result['values'][1]['financial_type_id']);
    $this->assertEquals('10.00', $api_result['values'][1]['tax_amount']);
    $this->assertEquals($membershipPriceFieldID, $api_result['values'][1]['price_field_id']);

    $this->assertEquals('10.00', $api_result['values'][2]['line_total']);
    $this->assertEquals('1', $api_result['values'][2]['financial_type_id']);
    $this->assertEquals($contriPriceFieldID, $api_result['values'][2]['price_field_id']);

    $this->assertEquals('20.00', $api_result['values'][3]['line_total']);
    $this->assertEquals('1', $api_result['values'][3]['financial_type_id']);
    $this->assertEquals($contriPriceFieldID, $api_result['values'][3]['price_field_id']);

    $this->assertEquals('29.50', $api_result['values'][4]['line_total']);
    $this->assertEquals('1.48', $api_result['values'][4]['tax_amount']);
    $this->assertEquals('5', $api_result['values'][4]['financial_type_id']);
    $this->assertEquals($contriPriceFieldID, $api_result['values'][4]['price_field_id']);

    $sum_line_total = array_sum(array_column($api_result['values'], 'line_total'));
    $sum_tax_amount = array_sum(array_column($api_result['values'], 'tax_amount'));
    $this->assertEquals($tax_total_amount, $sum_tax_amount);
    $this->assertEquals($contribution_total_amount, $sum_line_total + $sum_tax_amount);
  }

  /**
   * Test current employer submission.
   */
  public function testCurrentEmployer() {
    $payment_processor = $this->createPaymentProcessor();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->htmlOutput();
    $this->getSession()->getPage()->clickLink('2. Contact 2');
    $this->getSession()->getPage()->selectFieldOption('2_contact_type', 'organization');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->getPage()->checkField("civicrm_2_contact_1_contact_existing");

    $params = [
      'payment_processor_id' => $payment_processor['id'],
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $this->enableBillingSection();

    // Set contact 2 as current employer to first contact.
    $this->getSession()->getPage()->clickLink('1. Contact 1');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_contact_employer_id', 'Contact 2');

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Change contact 2 to select and remove any permissions.
    $params = [
      'selector' => 'edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations',
      'widget' => 'select',
      'filter' => [
        'check_permissions' => FALSE,
      ],
    ];
    $this->editContactElement($params);

    $this->drupalLogout();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->selectFieldOption('civicrm_2_contact_1_contact_existing', 'Default Organization');

    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '1');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '1');

    $this->fillCardAndSubmit();

    $api_result = $this->utils->wf_civicrm_api('contact', 'get', [
      'sequential' => 1,
      'first_name' => 'Frederick',
    ]);
    $this->assertEquals('Default Organization', $api_result['values'][0]['current_employer']);
  }

  public function testOverThousand() {
    $payment_processor = $this->createPaymentProcessor();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();

    $params = [
      'payment_processor_id' => $payment_processor['id'],
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField('Contribution Amount');
    $el = $this->getSession()->getPage()->findField('Payment Processor');
    $opts = $el->findAll('css', 'option');
    $this->assertCount(3, $opts, 'Payment processor values: ' . implode(', ', array_map(static function(NodeElement $el) {
        return $el->getValue();
      }, $opts)));

    $this->enableBillingSection();
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->fillField('Contribution Amount', '1200.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '1,200');

    $this->fillCardAndSubmit();

    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertNotEmpty($contribution['trxn_id']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('Donation', $contribution['financial_type']);
    $this->assertEquals('1200.00', $contribution['total_amount']);
    $this->assertEquals('Completed', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);

    $api_result = $this->utils->wf_civicrm_api('line_item', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals('1.00', $api_result['values'][0]['qty']);
    $this->assertEquals('1200.00', $api_result['values'][0]['unit_price']);
    $this->assertEquals('1200.00', $api_result['values'][0]['line_total']);
    $this->assertEquals('1', $api_result['values'][0]['financial_type_id']);
  }

  public function testAssignContributionSecondContact() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);

    $this->configureContributionTab();
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    //  Assign the contribution to contact "2"
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_contact_id', 2);
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');

    $this->getSession()->getPage()->selectFieldOption('Enable Billing Address?', 'No');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Specific alert, not required to achieve.
    $this->assertSession()->pageTextContains('You must enable an email field for Contact 2 in order to process transactions.');
    $this->getSession()->getPage()->pressButton('Enable It');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->htmlOutput();
    $this->saveCiviCRMSettings();

    $this->htmlOutput();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_first_name', 'Frederick');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_last_name', 'Pabst');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_email_email', 'fred@example.com');

    // Second contact to assign  the contribution
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_first_name', 'Max');
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_last_name', 'Plank');
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_email_email', 'max@example.com');

    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Next >');
    $this->htmlOutput();

    $this->getSession()->getPage()->fillField('Contribution Amount', '11.00');
    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    $api_result_contribution = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $api_result_contact =  $this->utils->wf_civicrm_api('contact', 'getSingle', [
      'sequential' => 1,
      'first_name' => "Max",
      'last_name' => 'Plank',
    ]);

    $this->assertEquals(1, $api_result_contribution['count']);
    $contribution = reset($api_result_contribution['values']);

    $this->assertEquals($contribution['contact_id'], $api_result_contact['id']);
    $this->assertEquals('11.00', $contribution['total_amount']);

  }

  public function testAssignContributionSecondContactSelectByUser() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);

    $this->configureContributionTab();
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    //  Assign the contribution to contact "- User Select -"
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_contact_id', 'create_civicrm_webform_element');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');

    $this->enableBillingSection();
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->htmlOutput();
    $this->saveCiviCRMSettings();

    $this->htmlOutput();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_first_name', 'Frederick');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_last_name', 'Pabst');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_email_email', 'fred@example.com');

    // Second contact to assign  the contribution
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_first_name', 'Max');
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_last_name', 'Plank');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->htmlOutput();

    $this->getSession()->getPage()->fillField('Contribution Amount', '11.00');
    $this->getSession()->getPage()->selectFieldOption('edit-civicrm-1-contribution-1-contribution-contact-id', 2);

    $billingValues = [
      'first_name' => 'Max',
      'last_name' => 'Plank',
      'street_address' => '123 Milwaukee Ave',
      'city' => 'Milwaukee',
      'country' => '1228',
      'state_province' => '1048',
      'postal_code' => '53177',
    ];
    $this->fillBillingFields($billingValues);

    $this->htmlOutput();
    $this->getSession()->getPage()->pressButton('Submit');
    $this->htmlOutput();

    $api_result_contribution = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $api_result_contact =  $this->utils->wf_civicrm_api('contact', 'getSingle', [
      'sequential' => 1,
      'first_name' => "Max",
      'last_name' => 'Plank',
    ]);

    $this->assertEquals(1, $api_result_contribution['count']);
    $contribution = reset($api_result_contribution['values']);

    $this->assertEquals($contribution['contact_id'], $api_result_contact['id']);
    $this->assertEquals('11.00', $contribution['total_amount']);

  }

  public function testAssignContributionSecondContactSelectByUserPaymentProcessor() {
    $payment_processor = $this->createPaymentProcessor();
    $this->drupalLogin($this->adminUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);

    // Enable email field for contact 2.
    $this->getSession()->getPage()->clickLink("Contact 2");
    $this->getSession()->getPage()->selectFieldOption('contact_2_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();

    $params = [
      'payment_processor_id' => $payment_processor['id'],
    ];

    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField("Contribution Amount");
    $this->assertSession()->checkboxChecked("Contribution Amount");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_enable_contribution', 1);
    //  Assign the contribution to contact "- User Select -"
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_contact_id', 'create_civicrm_webform_element');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('Currency', 'USD');

    $this->enableBillingSection();
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->htmlOutput();
    $this->saveCiviCRMSettings();

    $this->htmlOutput();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_first_name', 'Frederick');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_last_name', 'Pabst');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_email_email', 'fred@example.com');

    // Second contact to assign  the contribution
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_first_name', 'Max');
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_last_name', 'Plank');
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_email_email', 'maxplank@example.com');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->getSession()->getPage()->selectFieldOption('edit-civicrm-1-contribution-1-contribution-contact-id', 2);

    $this->getSession()->getPage()->fillField('Contribution Amount', '10.00');
    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');

    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '10.00');

    $this->htmlOutput();
    $this->fillCardAndSubmit();

    $api_result_contribution = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $api_result_contact =  $this->utils->wf_civicrm_api('contact', 'getSingle', [
      'sequential' => 1,
      'first_name' => "Max",
      'last_name' => 'Plank',
    ]);

    $this->assertEquals(1, $api_result_contribution['count']);
    $contribution = reset($api_result_contribution['values']);

    $this->assertEquals($contribution['contact_id'], $api_result_contact['id']);
    $this->assertEquals('10.00', $contribution['total_amount']);

  }
}
