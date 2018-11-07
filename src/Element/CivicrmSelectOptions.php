<?php

namespace Drupal\webform_civicrm\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;

// Include legacy files for their procedural functions.
// @todo convert required functions into injectable services.
include_once __DIR__ . '/../../includes/utils.inc';

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
      '#live_options' => TRUE,
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

  protected static function getFieldOptions($form_key) {
    \Drupal::getContainer()->get('civicrm')->initialize();
    $pieces = explode('_', $form_key, 6);
    list( , , , , $table, $name) = $pieces;
    $params = ['field' => $name, 'context' => 'create'];
    return wf_crm_apivalues($table, 'getoptions', $params);
  }

  public static function processSelectOptions(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['#tree'] = TRUE;
    // Add validate callback that extracts the associative array of options.
    $element += ['#element_validate' => []];
    array_unshift($element['#element_validate'], [get_called_class(), 'validateSelectOptions']);

    $element['options'] = [
      '#type' => 'table',
      '#tableselect' => FALSE,
      '#header' => [
        'item' => [
          'data' => ['#markup' => 'Item',]
        ],
        'enabled' => [
          'data' => [
            '#markup' => 'Enabled',
            '#access' => !$element['#live_options'],
          ]
        ],
        'label' => [
          'data' => [
            '#markup' => 'Label',
            '#access' => !$element['#live_options'],
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
            '#access' => !$element['#live_options'],
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
    ];

    if ($element['#live_options']) {
      $element['options']['#tabledrag'] = [];
    }

    $current_options = $element['#default_value'];
    $weight = 0;
    $field_options = static::getFieldOptions($element['#form_key']);

    // Sort the field options by the current options.
    if (!$element['#live_options']) {
      uasort($field_options, function ($a, $b) use ($current_options) {
        $current_options = array_flip($current_options);
        $weight_values = array_flip(array_values(array_flip($current_options)));

        if (!isset($current_options[$b]) && isset($current_options[$a])) {
          return -1;
        }
        if (!isset($current_options[$a]) && isset($current_options[$b])) {
          return 1;
        }

        $a_weight = $weight_values[$a];
        $b_weight = $weight_values[$b];
        if ($a_weight == $b_weight) {
          return 0;
        }
        return ($a_weight < $b_weight) ? -1 : 1;
      });
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
        '#access' => !$element['#live_options'],
      ];
      $element['options'][$row_key]['label'] = [
        '#type' => 'textfield',
        '#title' => t('Label for @item', ['@item' => $option]),
        '#title_display' => 'invisible',
        '#default_value' => !empty($current_options[$key]) ?  $current_options[$key] : $option,
        '#access' => !$element['#live_options'],
      ];
      $element['options'][$row_key]['default_option'] = [
        '#type' => 'radio',
        '#title' => t('Mark @item as the default value', ['@item' => $option]),
        '#title_display' => 'invisible',
        '#default_value' => $element['#default_option'] == $key,
        '#parents' => array_merge($element['#parents'], ['default']),
        '#return_value' => $key,
      ];
      $element['options'][$row_key]['weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight for @option', ['@option' => $option]),
        '#title_display' => 'invisible',
        // @todo support these values.
        '#default_value' => $weight,
        '#attributes' => ['class' => ['weight']],
        '#access' => !$element['#live_options'],
      ];
      $weight++;
    }

    return $element;
  }

  /**
   * Validates webform options element.
   */
  public static function validateSelectOptions(&$element, FormStateInterface $form_state, &$complete_form) {
    if ($element['#live_options']) {
      $options_value = self::getFieldOptions($element['#form_key']);
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
