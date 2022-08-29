<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Form\FormStateInterface;

interface WebformCivicrmConfirmFormInterface{

  function initialize(FormStateInterface $form_state);

  // In case you have an active payment processor, run CiviCRM's own doPayment method.
  public function doPayment();

}
