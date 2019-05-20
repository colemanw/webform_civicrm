<?php

namespace Drupal\webform_civicrm\Controller;

use Drupal\civicrm\Civicrm;
use Drupal\webform_civicrm\Utils;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class AjaxController implements ContainerInjectionInterface {

  /**
   * The CiviCRM service.
   *
   * @var \Drupal\civicrm\Civicrm
   */
  protected $civicrm;

  public function __construct(Civicrm $civicrm) {
    $this->civicrm = $civicrm;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('civicrm')
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
        return new JsonResponse(null);
    }

    protected function stateProvince($input) {
        if (!$input || (intval($input) != $input && $input != 'default')) {
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
