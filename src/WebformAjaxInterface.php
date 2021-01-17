<?php

namespace Drupal\webform_civicrm;

interface WebformAjaxInterface {

  /**
   * Load one or more contacts via ajax
   * @param $webformId
   * @param $fid
   */
  function contactAjax($webformId, $fid);

  /**
   * Access callback. Check if user has permission to view autocomplete results.
   *
   * @param Webform $webform
   * @param string $fid
   *   Webform component id
   *
   * @return bool
   */
  public function autocompleteAccess($webform, $fid);

}
