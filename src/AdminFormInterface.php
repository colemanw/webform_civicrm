<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformInterface;

interface AdminFormInterface {

  /**
   * Initialize and set form variables.
   * @param array $form
   * @param object $form_state
   * @param object $webform
   *
   * @return object
   */
  function initialize(array $form, FormStateInterface $form_state, WebformInterface $webform);

  /**
   * When a custom field is saved/deleted in CiviCRM, sync webforms with dynamic fieldsets.
   *
   * @param string $op
   * @param int $fid
   * @param int $gid
   */
  public static function handleDynamicCustomField($op, $fid, $gid);

}
