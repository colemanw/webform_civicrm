<?php

namespace Drupal\webform_civicrm;

interface FieldsInterface {

  /**
   * Fetches CiviCRM field data.
   *
   * @param string $var
   *   Name of variable to return: fields, tokens, or sets
   *
   * @return array
   *   fields: The CiviCRM contact fields this module supports
   *   tokens: Available tokens keyed to field ids
   *   sets: Info on fieldsets (entities)
   */
  public function get($var = 'fields');

  /**
   * Fetches CiviCRM field sets.
   *
   * @param array $components
   *
   * @return array
   */
  public function getSets(array $components): array;

}
