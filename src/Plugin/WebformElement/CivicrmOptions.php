<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\webform\Plugin\WebformElement\OptionsBase;
use Drupal\webform\WebformSubmissionInterface;

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
class CivicrmOptions extends OptionsBase {

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
      '#default_value' => $element_properties['extra']['aslist'] ?? FALSE,
      '#parents' => ['properties', 'extra', 'aslist'],
    ];
    $form['extra']['multiple'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Multiple'),
      '#description' => $this->t('Check this option if multiple options can be selected for the input field.'),
      '#access' => TRUE,
      '#default_value' => $element_properties['extra']['multiple'] ?? FALSE,
      '#parents' => ['properties', 'extra', 'multiple'],
    ];

    // Do not load option values if this is a numeric field.
    if ($this->isNumberField($form_state->get('element_properties'))) {
      return $form;
    }

    // Options.
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Element options'),
      '#open' => TRUE,
      '#prefix' => '<div id="webform-civicrm-options-wrapper">',
      '#suffix' => '</div>',
    ];
    $form['options']['empty_option'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Empty option label'),
      '#description' => $this->t('The label to show for the initial option denoting no selection in a select element.'),
      '#default_value' => $element_properties['empty_option'],
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
      '#civicrm_live_options' => $element_properties['civicrm_live_options'],
      '#default_option' => $element_properties['default_option'],
      '#form_key' => $form_state->get('element_properties')['form_key'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigurationFormProperties(array &$form, FormStateInterface $form_state) {
    $properties = parent::getConfigurationFormProperties($form, $form_state);
    if ($this->isNumberField($properties)) {
      return $properties;
    }
    if (!empty($form['properties'])) {
      // Get additional properties off of the options element.
      $select_options = $form['properties']['options']['options'];
      $properties['#default_option'] = $select_options['#default_option'];
      if (empty($properties['#default_value'])) {
        $properties['#default_value'] = $select_options['#default_option'];
      }
    }
    if (!isset($properties['#civicrm_live_options'])) {
      $properties['#civicrm_live_options'] = $form_state->getValues()['civicrm_live_options'] ?? 0;
    }
    // Make sure options are available on the element.
    if (!isset($properties['#options'])) {
      $properties['#options'] = $this->getFieldOptions($properties);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    \Drupal::service('civicrm')->initialize();
    $as_list = !empty($element['#extra']['aslist']);
    $is_multiple = !empty($element['#extra']['multiple']);
    $use_live_options = !empty($element['#civicrm_live_options']);
    $data = [];
    if ($webform_submission && $webform_submission->getWebform()->getHandlers()->has('webform_civicrm')) {
      $data = $webform_submission->getWebform()->getHandler('webform_civicrm')->getConfiguration()['settings']['data'] ?? [];
    }

    if (empty($element['#options'])) {
      $element['#options'] = $this->getFieldOptions($element, $data);
    }

    if ($use_live_options) {
      $new = $this->getFieldOptions($element, $data);
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

    if (empty($element['#default_value']) && !empty($element['#default_option'])) {
      $element['#default_value'] = $element['#default_option'];
    }

    $element['#type'] = 'select';
    if (!$as_list) {
      $element['#type'] = 'radios';
      // A single static radio should be shown as a checkbox
      if ($is_multiple || (!$use_live_options && count($element['#options']) === 1)) {
        $element['#type'] = 'checkboxes';
        $element['#default_value'] = empty($element['#default_value']) ? [] : (array) $element['#default_value'];
      }
    }
    if ($is_multiple) {
      $element['#multiple'] = TRUE;
    }

    parent::prepare($element, $webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareElementValidateCallbacks(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    parent::prepareElementValidateCallbacks($element, $webform_submission);
    // Disable default form validation on state select field, since options are loaded via js.
    if (strpos($element['#form_key'], 'state_province_id') !== false) {
      unset($element['#needs_validation']);
      $element['#validated'] = TRUE;
    }
  }

  protected function getFieldOptions($element, $data = []) {
    \Drupal::getContainer()->get('civicrm')->initialize();
    $field_options = \Drupal::service('webform_civicrm.field_options');
    return $field_options->get(['form_key' => $element['#form_key']], 'create', $data);
  }

  /**
   * If this is a CiviCRM Number element.
   *
   * @param array $element
   *
   * @return bool
   */
  protected function isNumberField($element) {
    $form_key = $element['form_key'] ?? $element['#form_key'] ?? NULL;
    if (!empty($form_key)) {
      $field = \Drupal::service('webform_civicrm.utils')->wf_crm_get_field($form_key);
      if (isset($field['type']) && $field['type'] == 'civicrm_number') {
        return TRUE;
      }
    }
    return FALSE;
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

  /**
   * {@inheritdoc}
   */
  public function hasMultipleWrapper() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasMultipleValues(array $element) {
    return \Drupal::service('webform_civicrm.utils')->hasMultipleValues($element);
  }

  /**
   * {@inheritdoc}
   */
  public function getRelatedTypes(array $element) {
    $types = [];
    $has_multiple_values = $this->hasMultipleValues($element);

    $supportedTypes = [
      'checkboxes',
      'radios',
      'webform_radios_other',
      'select',
      'webform_select_other',
      'civicrm_number'
    ];
    $elements = $this->elementManager->getInstances();
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
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $properties = $this->getConfigurationFormProperties($form, $form_state);
    if ($this->isNumberField($properties)) {
      foreach ($properties['#options'] as $key => $option) {
        if (!is_numeric($key)) {
          $form_state->setErrorByName('options', $this->t('This is a CiviCRM number field. @field keys must be numeric.', ['@field' => $properties['#title']]));
          break;
        }
      }
    }
  }

  /**
   * @inheritDoc
   */
  protected function format($type, array &$element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = parent::format($type, $element, $webform_submission, $options);
    $format = $this->getItemFormat($element);
    if (!str_ends_with($element['#form_key'], '_address_state_province_id')) {
      return $value;
    }
    if ($type === 'Text') {
      $state_id = $value;
    }
    else {
      $state_id = $value['#plain_text'] ?? $value['#markup'] ?? NULL;
    }
    if ($format === 'raw' || empty($state_id) || !is_numeric($state_id)) {
      return $value;
    }
    $utils = \Drupal::service('webform_civicrm.utils');
    $state = $utils->wf_crm_apivalues('state_province', 'get', ['id' => $state_id], 'name');
    if (!empty($state[$state_id])) {
      if ($type === 'Text') {
        return $state[$state_id];
      }
      $value['#plain_text'] = $state[$state_id];
    }
    return $value;
  }

}
