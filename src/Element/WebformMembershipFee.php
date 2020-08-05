<?php

namespace Drupal\webform_customs\Element;

use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Element\WebformComputedBase;

/**
 * Provides an item to display computed webform submission values using Twig.
 *
 * @RenderElement("civicrm_membership_fee")
 */
class WebformMembershipFee extends WebformComputedBase {

  /**
   * {@inheritdoc}
   */
  public static function computeValue(array $element, WebformSubmissionInterface $webform_submission) {
    /** @var \Drupal\webform\WebformTokenManagerInterface $token_manager */
    $token_manager = \Drupal::service('webform.token_manager');
    // @todo Figure out how to grab this from the webform submission instead of
    // using tokens.
    $membership_id = $token_manager->replace($element['#template'], $webform_submission, []);

    \Drupal::service('civicrm')->initialize();

    try {
      $membership = civicrm_api3('MembershipType', 'get', ['id' => $membership_id, 'return' => 'minimum_fee']);
      $membership = reset($membership);
      if (!empty($membership) && $membership['minimum_fee']) {
        return number_format($membership['minimum_fee'], 2);
      }
    }
    catch (\CiviCRM_API3_Exception $e) {
      \Drupal::logger('webform_customs')->notice($e->getMessage());
    }

    return 0;
  }

  /**
   * {@inheritdoc}
   */
  protected static function setWebformComputedElementValue(array &$element, $value) {
    parent::setWebformComputedElementValue($element, $value);
    $element['hidden']['#attributes']['class'][] = 'civicrm-enabled';
    $element['hidden']['#attributes']['class'][] = 'contribution-line-item';
    $element['hidden']['#attributes']['data-civicrm-field-key'] = $element['#webform_key'];
    $element['hidden']['#attributes']['id'] = $element['#webform_key'];
  }

}
