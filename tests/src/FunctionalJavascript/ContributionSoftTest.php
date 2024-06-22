<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Civi\Api4\Contribution;
use Drupal\Core\Url;

final class ContributionSoftTest extends WebformCivicrmTestBase {

  /**
   * Test contribution with soft-credit
   */
  public function testSoftCredit() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->htmlOutput();

    $params = [
      'payment_processor_id' => 'Pay Later',
      'soft' => 'Contact 2',
      'soft_credit_type_id' => 'In Memory of',
    ];
    $this->configureContributionTab($params);

    $this->getSession()->getPage()->selectFieldOption('Enable Billing Address?', 'No');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('Contribution Amount');

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_first_name', 'Frederick');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_contact_last_name', 'Pabst');
    $this->getSession()->getPage()->fillField('civicrm_1_contact_1_email_email', 'fred@example.com');

    // Second contact to assign the soft credit.
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_first_name', 'Max');
    $this->getSession()->getPage()->fillField('civicrm_2_contact_1_contact_last_name', 'Plank');

    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertPageNoErrorMessages();
    $this->getSession()->getPage()->fillField('Contribution Amount', '20');

    $this->assertSession()->elementExists('css', '#wf-crm-billing-items');
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '#wf-crm-billing-total', '20.00');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $contribution = Contribution::get(TRUE)
      ->addSelect('contribution_soft.amount', 'contribution_soft.soft_credit_type_id:label', 'contribution_soft.contact_id.display_name', 'contact_id.display_name')
      ->addJoin('ContributionSoft AS contribution_soft', 'LEFT')
      ->execute()
      ->first();
    $this->assertEquals('Frederick Pabst', $contribution['contact_id.display_name']);
    $this->assertEquals('20', $contribution['contribution_soft.amount']);
    $this->assertEquals('In Memory of', $contribution['contribution_soft.soft_credit_type_id:label']);
    $this->assertEquals('Max Plank', $contribution['contribution_soft.contact_id.display_name']);
  }

}
