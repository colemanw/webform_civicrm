<?php

namespace Drupal\webform_customs\Plugin\WebformElement;

use Drupal\webform\Plugin\WebformElement\WebformComputedBase;

/**
 * Provides a 'webform_customs_membership_fee' element.
 *
 * @WebformElement(
 *   id = "webform_customs_membership_fee",
 *   label = @Translation("CiviCRM Membership fee"),
 *   description = @Translation("Provides computed value based on membership type."),
 *   category = @Translation("CiviCRM"),
 * )
 */
class WebformMembershipFee extends WebformComputedBase {
  // @todo Figure out how to hide other default webform computed configurations.
}
