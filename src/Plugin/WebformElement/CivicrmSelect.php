<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_civicrm\Utils;

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
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    parent::prepare($element, $webform_submission);
    if (empty($element['#options'])) {
      $element['#options'] = $this->getFieldOptions($element);
    }
    // Webform unsets values which match the default value, it seems? The code
    // in the ::getConfigurationFormProperties method unsets anything equal to
    // its original value.
    // @see \Drupal\webform\Plugin\WebformElementBase::getConfigurationFormProperties
    elseif (!isset($element['#civicrm_live_options'])) {
      $new = $this->getFieldOptions($element);
      $old = $element['#options'];

      // If an item doesn't exist, we add it. If it's changed, we update it.
      // But we don't subtract items that have been removed in civi - this prevents
      // breaking the display of old submissions.
      foreach ($new as $k => $v) {
        if (!isset($old[$k]) || $old[$k] != $v) {
          $old[$k] = $v;
          $resave = TRUE;
        }
      }
      if (!empty($resave)) {
        // @todo Try to update valid values if they have changed.
        // @todo Determine if that is even relevant.
        // @see \wf_crm_webform_preprocess::fillForm
      }
      $element['#options'] = $new;
    }
    $element['#default_value'] = $element['#default_option'];
  }

  protected function getFieldOptions($element) {
    list( , , , , $table, $name) = Utils::wf_crm_explode_key($element['#form_key']);
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

    // @todo Add the static configuration form stuff here.
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
        'callback' => [get_called_class(), 'ajaxCallback'],
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
    return $properties;
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
