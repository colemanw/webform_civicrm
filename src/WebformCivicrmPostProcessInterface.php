<?php

namespace Drupal\webform_civicrm;

/**
 * @file
 * Front-end form validation and post-processing.
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;


interface WebformCivicrmPostProcessInterface {


  function initialize(WebformInterface $webform);

  /**
   * Called after a webform is submitted
   * Or, for a multipage form, called after each page
   * @param array $form
   * @param array $form_state (reference)
   */
  public function validate($form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission);

  /**
   * Process webform submission when it is about to be saved. Called by the following hook:
   *
   * @see webform_civicrm_webform_submission_presave
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   */
  public function preSave(WebformSubmissionInterface $webform_submission);

  /**
   * Process webform submission after it is has been saved. Called by the following hooks:
   * @see webform_civicrm_webform_submission_insert
   * @see webform_civicrm_webform_submission_update
   * @param stdClass $submission
   */
  public function postSave(WebformSubmissionInterface $webform_submission);

}
