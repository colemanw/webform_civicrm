<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_civicrm\Utils;

/**
 * Provides a 'civicrm_options' element.
 *
 * @WebformElement(
 *   id = "civicrm_options",
 *   label = @Translation("CiviCRM Options"),
 *   description = @Translation("Provides a CiviCRM powered radios."),
 *   category = @Translation("CiviCRM"),
 * )
 *
 * @see \Drupal\webform_example_element\Element\WebformExampleElement
 * @see \Drupal\webform\Plugin\WebformElementBase
 * @see \Drupal\webform\Plugin\WebformElementInterface
 * @see \Drupal\webform\Annotation\WebformElement
 */
class CivicrmOptions extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    return [
        'form_key' => '',
        'pid' => 0,
        'value' => '',
        'empty_option' => '',
        'empty_value' => '',
        'options' => [],
        'options_randomize' => FALSE,
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        'civicrm_live_options' => 1,
        'default_option' => '',
        'data_type' => NULL,
        'extra' => [
          'multiple' => FALSE,
          'aslist' => FALSE,
        ],
      ] + parent::getDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // Get element properties.
    $element_properties = $form_state->getValues() ?: $form_state->get('element_properties');

    $form['extra'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Extra'),
      '#open' => TRUE,
      '#access' => TRUE,
      '#parents' => ['properties', 'extra'],
    ];
    $form['extra']['aslist'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Listbox'),
      '#description' => $this->t('Check this option if you want the select component to be displayed as a select list box instead of radio buttons or checkboxes.'),
      '#access' => TRUE,
      '#default_value' => $element_properties['extra']['aslist'],
      '#parents' => ['properties', 'extra', 'aslist'],
    ];

    // Options.
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Element options'),
      '#open' => TRUE,
      '#prefix' => '<div id="webform-civicrm-options-wrapper">',
      '#suffix' => '</div>',
    ];

    if ($element_properties['civicrm_live_options']) {
      $live_options_description = t('You cannot control the presentation of live options. They will be loaded from the CiviCRM database every time the form is displayed.');
    }
    else {
      $live_options_description = t('Drag the arrows to re-order these options. Click the "enabled" checkbox to show/remove an item from the form. Set the label as you want it to appear on the form.');
    }

    $form['options']['civicrm_live_options'] = [
      '#type' => 'radios',
      '#options' => [
        t('<strong>Static Options</strong> (fully configurable)'),
        t('<strong>Live Options</strong> (update automatically)'),
      ],
      '#description' => Markup::create(
        '<p>' . $live_options_description . '</p>' .
        '<p>' . t('Check the "default" box for an option to be selected by default when a user views the form.') . '</p>'),
      '#ajax' => [
        'callback' => [static::class, 'ajaxCallback'],
        'wrapper' => 'webform-civicrm-options-wrapper',
        'progress' => ['type' => 'fullscreen'],
      ],
    ];

    $form['options']['options'] = [
      '#type' => 'civicrm_select_options',
      '#live_options' => $element_properties['civicrm_live_options'],
      '#default_option' => $element_properties['default_option'],
      '#form_key' => $this->configuration['#form_key']
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigurationFormProperties(array &$form, FormStateInterface $form_state) {
    $properties = parent::getConfigurationFormProperties($form, $form_state);
    // Get additional properties off of the options element.
    $select_options = $form['properties']['options']['options'];
    $properties['#default_option'] = $select_options['#default_option'];
    $properties['#default_value'] = $select_options['#default_option'];
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    $as_list = !empty($element['#extra']['aslist']);
    $is_multiple = !empty($element['#extra']['multiple']);
    $use_live_options = !empty($element['#civicrm_live_options']);

    if (empty($element['#options'])) {
      $element['#options'] = $this->getFieldOptions($element);
    }

    if ($use_live_options) {
      $new = $this->getFieldOptions($element);
      $old = $element['#options'];

      // If an item doesn't exist, we add it. If it's changed, we update it.
      // But we don't subtract items that have been removed in civi - this prevents
      // breaking the display of old submissions.
      foreach ($new as $k => $v) {
        if (!isset($old[$k]) || $old[$k] !== $v) {
          $old[$k] = $v;
        }
      }
      $element['#options'] = $new;
    }

    if (!empty($element['#default_option'])) {
      $element['#default_value'] = $element['#default_option'];
    }

    $element['#type'] = 'select';
    if (!$as_list) {
      $element['#type'] = $is_multiple ? 'checkboxes' : 'radios';
    }
    else if ($is_multiple) {
      $element['#multiple'] = TRUE;
    }

    // A single static radio should be shown as a checkbox
    if (!$use_live_options && !$as_list && count($element['#options']) === 1) {
      $element['#type'] = 'checkbox';
      // Reset the element label, the checkbox label, to be the first option's value.
      $element['#title'] = reset($element['#options']);
      $element['#return_value'] = key($element['#options']);
      // Remove the options array to prevent invalid validation.
      // @see \Drupal\Core\Form\FormValidator::performRequiredValidation
      unset($element['#options']);
    }
    parent::prepare($element, $webform_submission);
  }

  protected function getFieldOptions($element) {
    list( , , , , $table, $name) = Utils::wf_crm_explode_key($element['#form_key']);
    $params = ['field' => $name, 'context' => 'create'];
    return wf_crm_apivalues($table, 'getoptions', $params);
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
