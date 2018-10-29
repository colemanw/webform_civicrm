<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
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
        'value' => '',
        'empty_option' => '',
        'empty_value' => '',
        'options' => [],
        'options_randomize' => FALSE,
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        'civicrm_live_options' => 1,
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
    // @todo see how to remove this check, so it has default values.
    if (empty($element['#options'])) {
      $exposed = $this->getFieldOptions($element);
      $element['#options'] = $exposed;
    }
  }

  protected function getFieldOptions($element) {
    $pieces = wf_crm_explode_key($element['#form_key']);
    list( , , , , $table, $name) = $pieces;
    $params = ['field' => $name, 'context' => 'create'];
    return wf_crm_apivalues($table, 'getoptions', $params);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // Get element properties.
    $element_properties = $form_state->getValues() ?: $form_state->get('element_properties');
    $wtf_values = $form_state->getValues();

    // @todo Add the static configuration form stuff here.
    // Options.
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Element options'),
      '#open' => TRUE,
      '#prefix' => '<div id="webform-civicrm-options-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['options']['civicrm_live_options'] = [
      '#type' => 'radios',
      '#options' => [
        t('<strong>Static Options</strong> (fully configurable)'),
        t('<strong>Live Options</strong> (update automatically)'),
      ],
      '#description' => Markup::create('<p><div class="live-options-hide">' .
        t('Drag the arrows to re-order these options. Click the "enabled" checkbox to show/remove an item from the form. Set the label as you want it to appear on the form.') .
        '</div><div class="live-options-show">' .
        t('You cannot control the presentation of live options. They will be loaded from the CiviCRM database every time the form is displayed.') .
        '</div><div>' .
        t('Check the "default" box for an option to be selected by default when a user views the form.') .
        '</div></p>'),
      '#ajax' => [
        'callback' => [get_called_class(), 'ajaxCallback'],
        'wrapper' => 'webform-civicrm-options-wrapper',
        'progress' => ['type' => 'fullscreen'],
      ],
    ];


    $form['options']['options'] = [
      '#type' => 'civicrm_select_options',
      '#live_options' => $element_properties['civicrm_live_options'],
      '#form_key' => $this->configuration['#form_key']
    ];

    return $form;
  }

  /**
   * Ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The properties element.
   */
  public static function ajaxCallback(array $form, FormStateInterface $form_state) {
    $radio = $form_state->getTriggeringElement();
    $element = NestedArray::getValue($form, array_slice($radio['#array_parents'], 0, -2));
    return $element;
  }

}
