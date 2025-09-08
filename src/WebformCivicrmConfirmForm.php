<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Form\FormStateInterface;

class WebformCivicrmConfirmForm  implements WebformCivicrmConfirmFormInterface {

  /**
   * @var \Drupal\Core\Form\FormStateInterface
   */
  private $form_state;

  /**
   * @var \Drupal\webform_civicrm\UtilsInterface
   */
  protected $utils;

  /**
   * Static cache.
   *
   * @var bool
   */
  protected $initialized = FALSE;

  public function __construct(UtilsInterface $utils) {
    $this->utils = $utils;
  }

  function initialize(FormStateInterface $form_state) {
    if ($this->initialized) {
      return $this;
    }
    $this->form_state = $form_state;

    $this->initialized = TRUE;
    return $this;
  }

  public function doPayment() {
    $paramsDoPayment = $this->form_state->get(['civicrm', 'doPayment']);
    if (!empty($paramsDoPayment['payment_processor_id'])) {
      $paymentProcessor = \Civi\Payment\System::singleton()->getById($paramsDoPayment['payment_processor_id']);

      $processor_type =  $this->utils->wf_civicrm_api('payment_processor', 'getSingle', ['id' => $paramsDoPayment['payment_processor_id']]);

      if (!empty($params['is_test'])) {
        $paymentProcessor = \Civi\Payment\System::singleton()->getByName($processor_type['name'], TRUE);
      }

      if (method_exists($paymentProcessor, 'setSuccessUrl')) {
        $paymentProcessor->setSuccessUrl($paramsDoPayment['successURL']);
        $paymentProcessor->setCancelUrl($paramsDoPayment['cancelURL']);
      }
      try {
        $paymentProcessor->doPayment($paramsDoPayment);

      }
      catch (\Civi\Payment\Exception\PaymentProcessorException $e) {
        \Drupal::messenger()->addError(ts('Payment approval failed with message: %error ', [
          '%error' =>  $e->getMessage(),
        ]));
        \CRM_Utils_System::redirect($paramsDoPayment['cancelURL']);
      }
    }
  }
}