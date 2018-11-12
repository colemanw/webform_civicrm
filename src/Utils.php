<?php

namespace Drupal\webform_civicrm;

class Utils {

  /**
   * Explodes form key into an array and verifies that it is in the right format
   *
   * @param $key
   *   Webform component field key (string)
   *
   * @return array or NULL
   */
  public static function wf_crm_explode_key($key) {
    $pieces = explode('_', $key, 6);
    if (count($pieces) != 6 || $pieces[0] !== 'civicrm') {
      return NULL;
    }
    return $pieces;
  }

  /**
   * Get options for a specific field
   *
   * @param array $field
   *   Webform component array
   * @param string $context
   *   Where is this being called from?
   * @param array $data
   *   Array of crm entity data
   *
   * @return array
   */
  public static function wf_crm_field_options($field, $context, $data) {
    return \Drupal::getContainer()->get('webform_civicrm.field_options')->get($field, $context, $data);
  }

}
