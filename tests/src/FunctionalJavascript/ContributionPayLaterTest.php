<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\webform\Entity\Webform;

/**
 * Tests submitting a Webform with CiviCRM: Contribution with Pay later
 *
 * @group webform_civicrm
 */
final class ContributionPayLaterTest extends WebformCivicrmTestBase {

  public function testReceiptParams() {
    $this->drupalLogin($this->rootUser);
    $this->redirectEmailsToDB();

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $params = [
      'pp' => 'Pay Later',
      'financial_type_id' => 'create_civicrm_webform_element',
      'receipt' => [
        'receipt_from_name' => 'Admin',
        'receipt_from_email' => 'admin@example.com',
        'pay_later_receipt' => 'Payment by Direct Credit to: ABC. Please quote invoice number and name.',
        'receipt_text' => 'Thank you for your contribution.',
      ]
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->selectFieldOption('Enable Billing Address?', 'No');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Contribution Amount');

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('Contribution Amount', '30');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '30.00');
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contribution_1_contribution_financial_type_id', 2);

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $contribution = \Civi\Api4\Contribution::get()
      ->addSelect('source', 'total_amount', 'contribution_status_id:label', 'currency', 'financial_type_id:label')
      ->setLimit(1)
      ->execute()
      ->first();
    $this->assertEquals('30.00', $contribution['total_amount']);
    $this->assertEquals('Pending', $contribution['contribution_status_id:label']);
    $this->assertEquals('Member Dues', $contribution['financial_type_id:label']);
    $this->assertEquals('USD', $contribution['currency']);

    $sent_email = $this->getMostRecentEmail();
    $this->assertStringContainsString('From: Admin <admin@example.com>', $sent_email);
    $this->assertStringContainsString('To: Frederick Pabst <fred@example.com>', $sent_email);
    $this->assertStringContainsString('Payment by Direct Credit to: ABC. Please quote invoice number and name.', $sent_email);
    $this->assertStringContainsString('Thank you for your contribution', $sent_email);

    // Complete the contribution and recheck receipt.
    civicrm_api3('Contribution', 'completetransaction', [
      'id' => $contribution['id'],
      'is_email_receipt' => 1,
    ]);
    $sent_email = $this->getMostRecentEmail();
    $this->assertStringContainsString('From: Admin <admin@example.com>', $sent_email);
    $this->assertStringContainsString('To: Frederick Pabst <fred@example.com>', $sent_email);
    $this->assertStringContainsString('Thank you for your contribution', $sent_email);
  }

  public function testSubmitContribution() {
    $this->createFinancialCustomGroup();
    $this->createFinancialCustomGroup('Donation');
    $this->createFinancialCustomGroup('Member Dues');

    $this->drupalLogin($this->rootUser);
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
      'pp' => 'Pay Later',
    ];
    $this->configureContributionTab($params);
    $this->getSession()->getPage()->checkField('Contribution Amount');

    // Change financial type to Member Dues and confirm if its custom field is loaded.
    $this->getSession()->getPage()->selectFieldOption('Financial Type', 'Member Dues');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->verifyFTCustomSet('Member Dues');

    $this->getSession()->getPage()->selectFieldOption('Financial Type', 'Donation');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->verifyFTCustomSet('Donation');
    $this->getSession()->getPage()->checkField($this->_customFields['Donation']['label']);

    $this->saveCiviCRMSettings();
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertPageNoErrorMessages();

    $this->country = 'United Kingdom';
    $this->state = 'Newport';
    // Change widget of Amount element to checkbox.
    $this->changeTypeOfAmountElement('checkboxes', TRUE);
    $this->submitWebform('checkboxes');
    $this->verifyResult();

    // Change widget of Amount element to radios.
    $this->changeTypeOfAmountElement('radios');
    $this->submitWebform('radios');
    $this->verifyResult();

    $this->country = 'Malaysia';
    $this->state = 'Selangor';
    // Change widget of Amount element to radio + other.
    $this->changeTypeOfAmountElement('webform-radios-other');
    $this->submitWebform('webform_radios_other');
    $this->verifyResult();
  }

  /**
   * Submit the form
   *
   * @param string $amountType
   */
  protected function submitWebform($amountType) {
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');
    $this->getSession()->getPage()->selectFieldOption("Country", $this->country);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('State/Province', $this->state);

    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertSession()->waitForField('civicrm_1_contribution_1_contribution_total_amount');
    $this->assertPageNoErrorMessages();

    if ($amountType == 'radios') {
      $this->getSession()->getPage()->selectFieldOption("civicrm_1_contribution_1_contribution_total_amount", 30);
    }
    elseif ($amountType == 'webform_radios_other') {
      $this->getSession()->getPage()->selectFieldOption("civicrm_1_contribution_1_contribution_total_amount[radios]", '_other_');
      $this->assertSession()->waitForField('civicrm_1_contribution_1_contribution_total_amount[other]');
      $this->getSession()->getPage()->fillField('civicrm_1_contribution_1_contribution_total_amount[other]', '30');
    }
    else {
      $this->getSession()->getPage()->checkField('10');
      $this->getSession()->getPage()->checkField('20');
    }

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '30.00');
    $this->getSession()->getPage()->fillField('Donation Custom Field', 'Donation for xyz');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }

  /**
   * Create custom sets for financial type.
   *
   * @param string $ftName
   */
  public function createFinancialCustomGroup($ftName = NULL) {
    $params = [
      'title' => "{$ftName} Custom Group",
      'extends' => "Contribution",
    ];
    if ($ftName) {
      $ftId = civicrm_api3('FinancialType', 'get', [
        'return' => ["id"],
        'name' => $ftName,
      ])['id'];
      $params['extends_entity_column_value'] = $ftId;
    }
    $key = $ftName ?? 'all';
    $this->_customGroup[$key] = reset($this->createCustomGroup($params)['values']);

    // Add custom field.
    $params = [
      'custom_group_id' => $this->_customGroup[$key]['id'],
      'label' => "{$ftName} Custom Field",
      'data_type' => 'String',
      'html_type' => 'Text',
    ];
    $cf = $this->utils->wf_civicrm_api('CustomField', 'create', $params);
    $this->assertEquals(0, $cf['is_error']);
    $this->assertEquals(1, $cf['count']);
    $this->_customFields[$key] = reset($cf['values']);
  }

  /**
   * Confirm custom sets are loaded as per financial type.
   *
   * @param string $ftName
   */
  public function verifyFTCustomSet($ftName) {
    $this->assertSession()->elementTextContains('css', '[id="civicrm-ajax-contribution-sets-custom"]', $this->_customGroup[$ftName]['title']);
    $this->assertSession()->elementTextContains('css', '[id="civicrm-ajax-contribution-sets-custom"]', $this->_customGroup['all']['title']);
  }

  /**
   * Check submission results.
   */
  private function verifyResult() {
    $cfName = $this->_customGroup['Donation']['name'] . '.' . $this->_customFields['Donation']['name'];
    $contribution = \Civi\Api4\Contribution::get()
      ->addSelect('source', 'total_amount', 'contribution_status_id:label', 'currency', $cfName)
      ->setLimit(1)
      ->execute()
      ->first();

    $this->assertEquals($this->webform->label(), $contribution['source']);
    $this->assertEquals('30.00', $contribution['total_amount']);
    $this->assertEquals('Pending', $contribution['contribution_status_id:label']);
    $this->assertEquals('USD', $contribution['currency']);
    // Check if financial custom field value is pushed to civi.
    $this->assertEquals('Donation for xyz', $contribution[$cfName]);
    $this->utils->wf_civicrm_api('contribution', 'delete', [
      'id' => $contribution['id'],
    ]);
    $this->contribution_id = $contribution['id'];

    $address = $this->utils->wf_civicrm_api('Address', 'get', [
      'sequential' => 1,
    ])['values'][0];
    $country = $this->utils->wf_civicrm_api('Country', 'get', [
      'name' => $this->country,
    ]);
    $state = $this->utils->wf_civicrm_api('StateProvince', 'get', [
      'name' => $this->state,
    ]);
    $this->assertEquals($country['id'], $address['country_id']);
    $this->assertEquals($state['id'], $address['state_province_id']);
  }

  /**
   * Change contribution amount widget
   * to radio or checkbox.
   */
  private function changeTypeOfAmountElement($type, $changeTypeToOption = FALSE) {
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertPageNoErrorMessages();

    if ($type == 'webform-radios-other' && !$changeTypeToOption) {
      $this->editCivicrmOptionElement('edit-webform-ui-elements-civicrm-1-contribution-1-contribution-total-amount-operations', FALSE, FALSE, NULL, 'webform-radios-other');
      return;
    }

    $checkbox_edit_button = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-webform-ui-elements-civicrm-1-contribution-1-contribution-total-amount-operations"] a.webform-ajax-link');
    $checkbox_edit_button->click();
    $this->assertSession()->waitForField('drupal-off-canvas');
    $this->htmlOutput();

    if ($changeTypeToOption) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-change-type"]')->click();
      $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector='edit-elements-civicrm-options-operation']", 3000)->click();
      $this->assertSession()->waitForElementVisible('css', "[data-drupal-selector='edit-cancel']", 3000);
    }

    $this->getSession()->getPage()->fillField('properties[options][custom][options][items][0][value]', 10);
    $this->getSession()->getPage()->fillField('properties[options][custom][options][items][1][value]', 20);
    $this->getSession()->getPage()->fillField('properties[options][custom][options][items][2][value]', 30);

    if ($type == 'checkboxes') {
      $this->getSession()->getPage()->checkField('properties[extra][multiple]');
    }
    else {
      $this->getSession()->getPage()->uncheckField('properties[extra][multiple]');
    }

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->waitForText('has been updated.');
  }

}
