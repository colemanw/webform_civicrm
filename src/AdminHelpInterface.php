<?php

namespace Drupal\webform_civicrm;

interface AdminHelpInterface {

  /**
   * Set help text on the field description.
   * @param array $field
   * @param string $topic
   */
  public function addHelpDescription(&$field, $topic);

}
