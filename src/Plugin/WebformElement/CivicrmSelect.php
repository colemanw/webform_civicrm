<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Provides a 'civicrm_select' element.
 *
 * @WebformElement(
 *   id = "civicrm_select",
 *   label = @Translation("CiviCRM Select"),
 *   description = @Translation("Provides a CiviCRM powered select list."),
 *   category = @Translation("CiviCRM"),
 * )
 *
 * @see \Drupal\webform_example_element\Element\WebformExampleElement
 * @see \Drupal\webform\Plugin\WebformElementBase
 * @see \Drupal\webform\Plugin\WebformElementInterface
 * @see \Drupal\webform\Annotation\WebformElement
 */
class CivicrmSelect extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    return parent::getDefaultProperties() + [
        'form_key' => NULL,
        'pid' => 0,
        'weight' => 0,
        'value' => '',
        'empty_option' => '',
        'empty_value' => '',
        'options' => [],
        'options_randomize' => FALSE,
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        'extra' => [
          'civicrm_live_options' => 1,
          'items' => [],
        ],
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    parent::prepare($element, $webform_submission);

    // Fetch static options
    if (empty($element['#extra']['civicrm_live_options'])) {
      $exposed = wf_crm_str2array($element['#extra']['items']);
    }
    // Fetch live options
    else {
      // @todo change this when \wf_crm_webform_preprocess::fillForm changed
      // We set the values here, but the ::fillForm method reruns this process
      // anyways when #options is not empty and live options is set.
      $exposed = [];
      /*
      $pieces = wf_crm_explode_key($element['#form_key']);
      list( , , , , $table, $name) = $pieces;
      $params = ['field' => $name, 'context' => 'create'];
      $exposed = wf_crm_apivalues($table, 'getoptions', $params);
      */
    }

    $element['#options'] = $exposed;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    // @todo Add the static configuration form stuff here.
    return $form;
  }

}
