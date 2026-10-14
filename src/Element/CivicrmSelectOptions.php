<?php

namespace Drupal\webform_civicrm\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;

/**
 * @FormElement("civicrm_select_options")
 */
class CivicrmSelectOptions extends FormElement {

  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#label' => t('option'),
      '#labels' => t('options'),
      '#civicrm_live_options' => TRUE,
      '#default_option' => NULL,
      '#form_key' => NULL,
      '#process' => [
        [$class, 'processSelectOptions'],
      ],
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input === FALSE) {
      if (!isset($element['#default_value'])) {
        return [];
      }

      return $element['#default_value'];
      // return static::convertOptionsToValues($options, $element['#options_description']);
    }
    elseif (is_array($input) && isset($input['options'])) {
      return $input['options'];
    }
    else {
      return $element['#default_value'];
    }
  }

  protected static function getFieldOptions($form_key, $data = []) {
    \Drupal::getContainer()->get('civicrm')->initialize();
    $field_options = \Drupal::service('webform_civicrm.field_options');
    return $field_options->get(['form_key' => $form_key], 'create', $data);
  }

  public static function processSelectOptions(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['#tree'] = TRUE;
    // Add validate callback that extracts the associative array of options.
    $element += ['#element_validate' => []];
    array_unshift($element['#element_validate'], [get_called_class(), 'validateSelectOptions']);

    $element['options'] = [
      '#type' => 'table',
      '#tableselect' => TRUE,
      '#header' => [
        'item' => [
          'data' => ['#markup' => 'Item',]
        ],
        'enabled' => [
          'data' => [
            '#markup' => 'Enabled',
            '#access' => !$element['#civicrm_live_options'],
          ]
        ],
        'label' => [
          'data' => [
            '#markup' => 'Label',
            '#access' => !$element['#civicrm_live_options'],
          ]
        ],
        'default' => [
          'data' => [
            '#markup' => 'Default'
          ]
        ],
        'weight' => [
          'data' => [
            '#markup' => 'Weight',
            '#access' => !$element['#civicrm_live_options'],
          ]
        ],
      ],
      '#empty' => 'Nothing',
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'weight',
        ],
      ],
      '#value_callback' => [get_called_class(), 'valueCallback'],
    ];

    if ($element['#civicrm_live_options']) {
      $element['options']['#tabledrag'] = [];
      $element['options']['#tableselect'] = FALSE;
    }
    if (strpos($element['#form_key'], 'address_state_province_id') !== false || strpos($element['#form_key'], 'address_county_id') !== false) {
      $parent_label = (strpos($element['#form_key'], 'address_state_province_id') !== false) ? 'Country' : 'State/Province';
      $element['options']['#empty'] = t('Options are loaded dynamically on the webform based on the value selected in @key field.', ['@key' => $parent_label]);
    }

    $weight = 0;
    $webform = $form_state->getFormObject()->getWebform();
    $data = $webform->getHandler('webform_civicrm')->getConfiguration()['settings']['data'] ?? [];

    // $current_options is an array of [value => webform_label] listed in the order defined in the webform.
    // Options disabled in the webform are absent from this array.
    $current_options = $element['#default_value'];

    if (!$element['#civicrm_live_options']) {
      // $all_options is an array of [value => civi_label] listed in the order defined in civicrm.
      // Options disabed in civi are absent from this array, but it includes options disabled in the webform.
      $all_options = static::getFieldOptions($element['#form_key'], $data);

      // build the $field_options array using the order of $current_options, using the labels specified in the webform.
      foreach ($current_options as $key => $option) {
        $field_options[$key] = $all_options[$key];
      }

      // Add to the $field_options array any options that are disabled in the webform.
      // The order of the disabled options cannot be changed, and they will always
      // appear below the enabled options.
      foreach ($all_options as $key => $option) {
        if (!isset($field_options[$key])) {
          $field_options[$key] = $all_options[$key];
        }
      }
    } else { // static options
      $field_options = static::getFieldOptions($element['#form_key'], $data);
    }

    foreach ($field_options as $key => $option) {
      $row_key = 'civicrm_option_' . $key;
      $element['options'][$row_key]['#attributes']['class'][] = 'draggable';
      $element['options'][$row_key]['#weight'] = $weight;

      $element['options'][$row_key]['item'] = [
        '#plain_text' => $option,
      ];
      $element['options'][$row_key]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => t('Enable @item', ['@item' => $option]),
        '#title_display' => 'invisible',
        '#default_value' => !empty($current_options[$key]),
        '#access' => !$element['#civicrm_live_options'],
      ];
      $element['options'][$row_key]['label'] = [
        '#type' => 'textfield',
        '#title' => t('Label for @item', ['@item' => $option]),
        '#title_display' => 'invisible',
        '#default_value' => !empty($current_options[$key]) ?  $current_options[$key] : $option,
        '#access' => !$element['#civicrm_live_options'],
      ];
      $element['options'][$row_key]['default_option'] = [
        '#type' => 'radio',
        '#title' => t('Mark @item as the default value', ['@item' => $option]),
        '#title_display' => 'invisible',
        '#default_value' => $element['#default_option'] == $key ? $key : '',
        '#parents' => array_merge($element['#parents'], ['default']),
        '#return_value' => $key,
      ];
      // Avoid 0 value to be selected as default. The patch from https://www.drupal.org/project/drupal/issues/1381140
      // renders 0 as the default option due to some casting equation check in preRenderRadio() function.
      // Pay later holds the value 0 and is always loaded as default on the edit element page.
      // Setting false for the #value key mitigates this problem.
      // https://www.drupal.org/project/drupal/issues/2908602 is an open ticket on drupal which looks similar.
      if ($key === 0 && $element['#default_option'] != $key) {
        $element['options'][$row_key]['default_option']['#value'] = FALSE;
      }
      $element['options'][$row_key]['weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight for @option', ['@option' => $option]),
        '#title_display' => 'invisible',
        // @todo support these values.
        '#default_value' => $weight,
        '#attributes' => ['class' => ['weight']],
        '#access' => !$element['#civicrm_live_options'],

        // delta theoretically should control the number of items in the weight dropdown for each option, but
        // in reality that weight range seems to be fixed at -10 to +10. When there are more than 10 options present,
        // the weight dropdown therefore cannot be used to move an option below the 10th spot. In addition,
        // a bug related to this fixed -10 to +10 range prevents dragging options below the 22nd spot.
        // Therefore, when there are more than 10 options present, it's desireable to switch from a weight
        // listbox to an integer edit box. This is accomplished by setting
        // delta to a value greater than Drupal::config('system.site')->get('weight_select_max') (default value 100)
        // See Drupal\Core\Render\Element\Weight::processWeight()
        // Other than that threshold, the value specified here for delta is not significent.
        '#delta' => !$element['#civicrm_live_options'] && sizeof($all_options) > 10 ? '101' : "10",
      ];
      $weight++;
    }
    $element['#attached']['library'][] = 'webform_civicrm/civicrmoptions';
    return $element;
  }

  /**
   * Validates webform options element.
   */
  public static function validateSelectOptions(&$element, FormStateInterface $form_state, &$complete_form) {
    if ($element['#civicrm_live_options']) {
      $webform = $form_state->getFormObject()->getWebform();
      $data = $webform->getHandler('webform_civicrm')->getConfiguration()['settings']['data'] ?? [];
      $options_value = self::getFieldOptions($element['#form_key'], $data);
    }
    else {
      $raw_values = $form_state->getValue($element['options']['#parents']);
      uasort($raw_values, '\Drupal\Component\Utility\SortArray::sortByWeightElement');

      $options_value = [];
      foreach ($raw_values as $raw_key => $raw_value) {
        if (!empty($raw_value['enabled'])) {
          $new_key = str_replace('civicrm_option_', '', $raw_key);
          $options_value[$new_key] = $raw_value['label'];
        }
      }
    }

    $element['#default_option'] = $form_state->getValue(['properties', 'options', 'default']);
    $element['#value'] = $options_value;
    $form_state->setValueForElement($element, $options_value);
  }
}
