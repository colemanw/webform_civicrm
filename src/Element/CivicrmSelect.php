<?php

namespace Drupal\webform_civicrm\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Select;

/**
 * @FormElement("civicrm_select")
 */
class CivicrmSelect extends Select {

  public static function processSelect(&$element, FormStateInterface $form_state, &$complete_form) {
    $element = parent::processSelect($element, $form_state, $complete_form);
    if (!empty($element['#extra']['civicrm_live_options'])) {
      // @todo decide if this need any operations here.
    }
    return $element;
  }

}
