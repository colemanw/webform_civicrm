<?php

namespace Drupal\webform_civicrm\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;

/**
 * @FormElement("civicrm_contact")
 */
class CivicrmContact extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#multiple' => FALSE,
      '#default_value' => NULL,
      '#process' => [
        [$class, 'processCivicrmContact'],
      ],
      '#theme_wrappers' => ['container'],
    ];
  }

  public static function processCivicrmContact(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $hide_method = wf_crm_aval($element['#extra'], 'hide_method', 'hide');
    $no_hide_blank = (int) wf_crm_aval($element['#extra'], 'no_hide_blank', 0);
    $element['widget'] = [
      '#type' => $element['#extra']['widget'] == 'autocomplete' ? 'textfield' : $element['#extra']['widget'],
      '#weight' => $element['#weight'],
    ];
    $element['widget']['#attributes']['data-hide-method'] = $hide_method;
    $element['widget']['#attributes']['data-no-hide-blank'] = $no_hide_blank;

    $cid = wf_crm_aval($element, '#default_value', '');
    if ($element['#type'] == 'hidden') {
      // User may not change this value for hidden fields
      $element['#value'] = $cid;
      if (!$element['#extra']['show_hidden_contact']) {
        return;
      }
    }
    if ($cid) {
      // Don't lookup same contact again
      if (wf_crm_aval($element, '#attributes:data-civicrm-id') != $cid) {
        $filters = wf_crm_search_filters($node, $component);
        $name = wf_crm_contact_access($component, $filters, $cid);
        if ($name !== FALSE) {
          $element['#attributes']['data-civicrm-name'] = $name;
          $element['#attributes']['data-civicrm-id'] = $cid;
        }
        else {
          unset($cid);
        }
      }
    }
    if (empty($cid) && $element['#type'] == 'hidden' && $component['extra']['none_prompt']) {
      $element['#attributes']['data-civicrm-name'] = filter_xss($component['extra']['none_prompt']);
    }

    return $element;
  }

}
