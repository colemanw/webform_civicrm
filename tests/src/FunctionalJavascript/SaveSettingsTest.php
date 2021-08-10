<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests settings on the webform.
 *
 * @group webform_civicrm
 */
final class SaveSettingsTest extends WebformCivicrmTestBase {

  /**
   * Add fields on the webform.
   */
  function testAddFields() {
    $this->addFieldsOnWebform();

    $elements = [
      'civicrm_1_contact_1_contact_existing',
      'civicrm_1_contact_1_contact_first_name',
      'civicrm_1_contact_1_contact_last_name',
      'civicrm_1_activity_1_activity_activity_type_id',
    ];
    $this->assertElementsOnBuildForm($elements);
  }

  /**
   * Delete fields on the webform.
   */
  function testDeleteField() {
    $this->addFieldsOnWebform();
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));

    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->clickLink('Activities');
    $this->getSession()->getPage()->selectFieldOption('Activity Type', 'Meeting');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->saveCiviCRMSettings(TRUE);
    $this->assertSession()->waitForField('edit-delete');

    $this->assertSession()->pageTextContains('These existing fields are no longer needed for CiviCRM processing based on your new form settings');
    $this->assertSession()->pageTextContains('Contact 1 Activity 1: Activity Type');
    $this->assertSession()->pageTextNotContains('Saved CiviCRM settings');

    // Cancel this action.
    $this->getSession()->getPage()->pressButton('edit-cancel');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForField('nid');
    $this->htmlOutput();

    // Ensure the action was cancelled and activity type is still - User Select -
    $this->assertSession()->pageTextContains('Cancelled');
    $this->assertOptionSelected('edit-civicrm-1-activity-1-activity-activity-type-id', '- User Select -');
    $this->assertOptionSelected('number_of_contacts', 1);

    // Repeat the step and delete activity type element from the page.
    $this->getSession()->getPage()->selectFieldOption('number_of_contacts', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->getSession()->getPage()->clickLink('Activities');
    $this->getSession()->getPage()->selectFieldOption('Activity Type', 'Meeting');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->saveCiviCRMSettings(TRUE);
    $this->assertSession()->waitForField('edit-delete');

    $this->getSession()->getPage()->pressButton('edit-delete');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->waitForField('nid');
    $this->htmlOutput();

    $this->assertSession()->pageTextContains('Deleted field: Activity Type');
    $this->assertSession()->pageTextContains('Added 2 fields to the form');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
    $this->assertPageNoErrorMessages();

    $elements = [
      'civicrm_1_activity_1_activity_activity_type_id',
    ];
    $this->assertElementsOnBuildForm($elements, TRUE);
  }

  /**
   * Add fields on the webform.
   */
  private function addFieldsOnWebform() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    $this->getSession()->getPage()->clickLink('Activities');
    $this->getSession()->getPage()->selectFieldOption('activity_number_of_activity', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();
    $this->getSession()->getPage()->selectFieldOption('Activity Type', '- User Select -');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->saveCiviCRMSettings();
    $this->assertSession()->pageTextContains('Added 4 fields to the form');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');
  }

  /**
   * Check if element is present or not on the webform build page.
   */
  private function assertElementsOnBuildForm($elements, $negate = FALSE) {
    $this->drupalGet($this->webform->toUrl('edit-form'));
    foreach ($elements as $element) {
      if ($negate) {
        $this->assertSession()->pageTextNotContains($element);
      }
      else {
        $this->assertSession()->pageTextContains($element);
      }
    }
  }

}
