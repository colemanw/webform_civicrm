<?php

namespace Drupal\webform_civicrm\Controller;

use Drupal\civicrm\Civicrm;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class AjaxController implements ContainerInjectionInterface {

  protected $requestStack;

  /**
   * The CiviCRM service.
   *
   * @var \Drupal\civicrm\Civicrm
   */
  protected $civicrm;

  public function __construct(Civicrm $civicrm, RequestStack $requestStack) {
    $this->civicrm = $civicrm;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('civicrm'),
        $container->get('request_stack')
    );
  }

  /**
   * Handles the ajax request.
   *
   * @param string $operation
   *   The operation to perform: stateProvince or county
   */
  public function handle($key, $input = '') {
    $this->civicrm->initialize();
    if ($key === 'stateProvince') {
      return $this->stateProvince($input);
    }
    elseif ($key === 'county') {
      return $this->county($input);
    }
    else {
      $processor = \Drupal::service('webform_civicrm.webform_ajax');
      return new JsonResponse($processor->contactAjax($key, $input));
    }
  }

  protected function stateProvince($input) {
    if (!$input || ((int) $input != $input && $input != 'default')) {
      $data = ['' => t('- first choose a country')];
    }
    else {
      $data = \Drupal::service('webform_civicrm.utils')->wf_crm_get_states($input);
    }

    // @todo use Drupal's cacheable response?
    return new JsonResponse($data);
  }

  protected function county($input) {
    $data = [];
    $utils = \Drupal::service('webform_civicrm.utils');
    if (strpos($input, '-') !== FALSE) {
      list($state, $country) = explode('-', $input);
      $params = [
        'field' => 'county_id',
        'state_province_id' => $state
      ];
      $data = $utils->wf_crm_apivalues('address', 'getoptions', $params);
    }
    // @todo use Drupal's cacheable response?
    return new JsonResponse($data);
  }

}
