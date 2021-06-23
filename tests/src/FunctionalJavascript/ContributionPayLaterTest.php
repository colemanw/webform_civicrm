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


  public function testSubmitContribution() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->configureContributionTab(TRUE, 'Pay Later');
    $this->getSession()->getPage()->checkField('Contribution Amount');

    $this->saveCiviCRMSettings();
    $this->drupalGet($this->webform->toUrl('edit-form'));
    $this->assertPageNoErrorMessages();

    // Change widget of Amount element to checkbox.
    $this->changeTypeOfAmountElement('checkboxes');
    $this->submitWebform('checkboxes');
    $this->verifyResult();

    // Change widget of Amount element to radios.
    $this->changeTypeOfAmountElement('radios');
    $this->submitWebform('radios');
    $this->verifyResult();

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

    $this->getSession()->getPage()->pressButton('Next >');
    $this->assertSession()->waitForField('civicrm_1_contribution_1_contribution_total_amount');

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
    $this->getSession()->getPage()->pressButton('Submit');

    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }

  private function verifyResult() {
    $api_result = $this->utils->wf_civicrm_api('contribution', 'get', [
      'sequential' => 1,
    ]);

    $this->assertEquals(1, $api_result['count']);
    $contribution = reset($api_result['values']);
    $this->assertEquals($this->webform->label(), $contribution['contribution_source']);
    $this->assertEquals('30.00', $contribution['total_amount']);
    $this->assertEquals('Pending', $contribution['contribution_status']);
    $this->assertEquals('USD', $contribution['currency']);
    $this->utils->wf_civicrm_api('contribution', 'delete', [
      'id' => $contribution['id'],
    ]);
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
