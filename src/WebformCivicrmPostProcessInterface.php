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


  function initialize(WebformSubmissionInterface $webform_submission);

  /**
   * Called after a webform is submitted
   * Or, for a multipage form, called after each page
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function validate($form, FormStateInterface $form_state);

  /**
   * Process webform submission when it is about to be saved.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   */
  public function preSave(WebformSubmissionInterface $webform_submission);

  /**
   * Process webform submission after it is has been saved.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   */
  public function postSave(WebformSubmissionInterface $webform_submission);

}
