<?php

namespace Drupal\webform_civicrm\Controller;

use Drupal\civicrm\Civicrm;
use Drupal\webform_civicrm\Utils;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

include_once __DIR__ . '/../../includes/wf_crm_webform_ajax.inc';

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
    if ($key === 'stateProvince' || $key === 'county') {
      $this->civicrm->initialize();
      return $this->$key($input);
    }
    else {
      $this->civicrm->initialize();
      $processor = new \wf_crm_webform_ajax($this->requestStack);
      return new JsonResponse($processor->contactAjax($key, $input));
    }
  }

    protected function stateProvince($input) {
        if (!$input || ((int) $input != $input && $input != 'default')) {
            $data = ['' => t('- first choose a country')];
        }
        else {
            $data = Utils::wf_crm_get_states($input);
        }

        // @todo use Drupal's cacheable response?
        return new JsonResponse($data);
    }

    protected function county($input) {
        $data = [];
        if (strpos($input, '-') !== FALSE) {
            list($state, $country) = explode('-', $input);
            $params = [
              'field' => 'county_id',
              'state_province_id' => wf_crm_state_abbr($state, 'id', $country)
            ];
            $data = wf_crm_apivalues('address', 'getoptions', $params);
        }
        // @todo use Drupal's cacheable response?
        return new JsonResponse($data);
    }
}
