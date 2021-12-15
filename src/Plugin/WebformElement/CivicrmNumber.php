<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\webform\Plugin\WebformElement\Number;
use Drupal\webform\Utility\WebformReflectionHelper;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Provides a 'civicrm_number' element.
 *
 * @WebformElement(
 *   id = "civicrm_number",
 *   label = @Translation("CiviCRM Number"),
 *   description = @Translation("Provides a CiviCRM powered numeric field."),
 *   category = @Translation("CiviCRM"),
 * )
 *
 * @see \Drupal\webform_example_element\Element\WebformExampleElement
 * @see \Drupal\webform\Plugin\WebformElementBase
 * @see \Drupal\webform\Plugin\WebformElementInterface
 * @see \Drupal\webform\Annotation\WebformElement
 */
class CivicrmNumber extends Number {

  /**
   * {@inheritdoc}
   */
  public function getRelatedTypes(array $element) {
    $types = parent::getRelatedTypes($element);
    // Allow number field to be retyped into options widgets.
    $elements = $this->elementManager->getInstances();
    $supportedTypes = ['civicrm_options'];
    foreach ($elements as $element_name => $element_instance) {
      if (in_array($element_name, $supportedTypes)) {
        $types[$element_name] = $element_instance->getPluginLabel();
      }
    }
    asort($types);
    return $types;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    unset($element['#options'], $element['#data_type']);
    parent::prepare($element, $webform_submission);
  }

}
