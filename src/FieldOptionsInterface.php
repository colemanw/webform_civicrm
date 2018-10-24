<?php

namespace Drupal\webform_civicrm;

interface FieldOptionsInterface {

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
  public function get($field, $context, $data);

}
