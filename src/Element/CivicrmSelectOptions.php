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

    $current_options = $element['#default_value'];
    $weight = 0;
    $webform = $form_state->getFormObject()->getWebform();
    $data = $webform->getHandler('webform_civicrm')->getConfiguration()['settings']['data'] ?? [];
    $field_options = static::getFieldOptions($element['#form_key'], $data);

    // Sort the field options by the current options.
    if (!$element['#civicrm_live_options']) {
      uasort($field_options, function ($a, $b) use ($current_options) {
        $current_options = array_flip($current_options);
        $weight_values = array_flip(array_values(array_flip($current_options)));

        if (!isset($current_options[$b]) && isset($current_options[$a])) {
          return -1;
        }
        if (!isset($current_options[$a]) && isset($current_options[$b])) {
          return 1;
        }

        $a_weight = $weight_values[$a] ?? 0;
        $b_weight = $weight_values[$b] ?? 0;
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
      $element['options'][$row_key]['weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight for @option', ['@option' => $option]),
        '#title_display' => 'invisible',
        // @todo support these values.
        '#default_value' => $weight,
        '#attributes' => ['class' => ['weight']],
        '#access' => !$element['#civicrm_live_options'],
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
