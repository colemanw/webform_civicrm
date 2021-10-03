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

  /**
   * Test Membership submission using Pay Later option.
   */
  public function testSubmitPaylaterMembership() {
    $this->createMembershipType(20);

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->configureContributionTab(TRUE, 'Pay Later');

    $this->getSession()->getPage()->clickLink('Memberships');
    $this->getSession()->getPage()->selectFieldOption('membership_1_number_of_membership', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_membership_1_membership_membership_type_id', 'Basic');
    $this->htmlOutput();

    $this->saveCiviCRMSettings();

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'fred@example.com');

    $this->getSession()->getPage()->pressButton('Next >');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '20.00');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $api_result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('membership', 'get', [
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $api_result['count']);
    $membership = reset($api_result['values']);

    $this->assertEquals('Basic', $membership['membership_name']);
    $this->assertEquals('5', $membership['status_id']);

    //Assert if dates are empty.
    $this->assertEmpty($membership['join_date'] ?? '');
    $this->assertEmpty($membership['start_date'] ?? '');
    $this->assertEmpty($membership['end_date'] ?? '');
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

    //Enable Address fields.
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_address', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Country');
    $this->assertSession()->checkboxChecked('Country');

    $this->configureContributionTab(TRUE, 'Pay Later');
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
    $this->changeTypeOfAmountElement('checkboxes');
    $this->submitWebform('checkboxes');
    $this->verifyResult();

    // Change widget of Amount element to radios.
    $this->changeTypeOfAmountElement('radios');
    $this->submitWebform('radios');
    $this->verifyResult();

    $this->country = 'Malaysia';
    $this->state = 'Selangor';
    // Change widget of Amount element to radio + other.
    $this->changeTypeOfAmountElement('webform_radios_other');
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
  private function changeTypeOfAmountElement($type) {
    $webform = Webform::load('civicrm_webform_test');
    $elements = $webform->getElementsInitialized();
    $elements['contribution_pagebreak']['civicrm_1_contribution_1_contribution_total_amount']['#type'] = $type;
    $elements['contribution_pagebreak']['civicrm_1_contribution_1_contribution_total_amount']['#webform_plugin_id'] = $type;
    $elements['contribution_pagebreak']['civicrm_1_contribution_1_contribution_total_amount']['#options'] = [
      10 => 10,
      20 => 20,
      30 => 30,
    ];
    $webform->setElements($elements);
    $webform->save();
  }

}
