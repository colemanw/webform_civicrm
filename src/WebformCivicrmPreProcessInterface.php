<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerInterface;

interface WebformCivicrmPreProcessInterface {

  /**
   * Initialize form variables.
   *
   * @param $form
   * @param $form_state
   * @param $handler
   */
  function initialize(&$form, FormStateInterface $form_state, WebformHandlerInterface $handler);

  /**
   * Alter front-end of webforms: Called by hook_form_alter() when rendering a civicrm-enabled webform
   * Add custom prefix.
   * Display messages.
   * Block users who should not have access.
   * Set webform default values.
   */
  function alterForm();

}
