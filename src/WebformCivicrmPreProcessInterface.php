<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;

interface WebformCivicrmPreProcessInterface {

  /**
   * Initialize form variables.
   *
   * @param array $form
   * @param FormStateInterface $form_state
   * @param WebformHandlerInterface $handler
   * @param WebformSubmissionInterface $webform_submission
   */
  function initialize(array &$form, FormStateInterface $form_state, WebformHandlerInterface $handler, WebformSubmissionInterface $webform_submission);

  /**
   * Alter front-end of webforms: Called by hook_form_alter() when rendering a civicrm-enabled webform
   * Add custom prefix.
   * Display messages.
   * Block users who should not have access.
   * Set webform default values.
   */
  function alterForm();

}
