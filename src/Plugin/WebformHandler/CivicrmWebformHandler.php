<?php

namespace Drupal\webform_civicrm\Plugin\WebformHandler;

use Drupal\civicrm\Civicrm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// Include legacy files for their procedural functions.
// @todo convert required functions into injectable services.
include_once __DIR__ . '/../../../includes/wf_crm_webform_base.inc';
include_once __DIR__ . '/../../../includes/wf_crm_webform_preprocess.inc';
include_once __DIR__ . '/../../../includes/wf_crm_webform_postprocess.inc';

/**
 * CiviCRM Webform Handler plugin.
 *
 * @WebformHandler(
 *   id = "webform_civicrm",
 *   label = @Translation("CiviCRM"),
 *   category = @Translation("CRM"),
 *   description = @Translation("Create some data in CiviCRM."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class CivicrmWebformHandler extends WebformHandlerBase {

  /**
   * The CiviCRM service.
   *
   * @var \Drupal\civicrm\Civicrm
   */
  protected $civicrm;

  /**
   * Constructs a CivicrmWebformHandler object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\webform\WebformSubmissionConditionsValidatorInterface $conditions_validator
   *   The webform submission conditions (#states) validator.
   * @param \Drupal\civicrm\Civicrm $civicrm
   *   The CiviCRM service.
   */
  public function __construct(array $configuration, string $plugin_id, array $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, Civicrm $civicrm) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);

    $this->civicrm = $civicrm;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('civicrm')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Manage the CiviCRM settings from the CiviCRM tab'),
      '#url' => new Url('entity.webform.civicrm', ['webform' => $this->getWebform()->id()]),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsConditions() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'data' => [
        'contact' => [
          1 => [
            'contact' => [
              1 => [
                'contact_type' => 'individual',
                'contact_sub_type' => [],
              ],
            ],
          ],
        ],
        'reg_options' => [
          'validate' => 1,
        ],
      ],
      'confirm_subscription' => 1,
      'create_fieldsets' => 1,
      // The default configuration is invoked before a webform is set to the
      // plugin, so we have to default this to empty.
      'new_contact_source' => '',
      'civicrm_1_contact_1_contact_first_name' => 'create_civicrm_webform_element',
      'civicrm_1_contact_1_contact_last_name' => 'create_civicrm_webform_element',
      'civicrm_1_contact_1_contact_existing' => 'create_civicrm_webform_element',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function alterElements(array &$elements, WebformInterface $webform) {
    $this->civicrm->initialize();
    $settings = $this->configuration;
    $data = $settings['data'];
    parent::alterElements($elements, $webform); // TODO: Change the autogenerated stub
  }

  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $this->civicrm->initialize();
    $settings = $this->configuration;
    $data = $settings['data'];
    $processor = new \wf_crm_webform_preprocess($form, $form_state, $this);
    $processor->alterForm();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return ['module' => ['webform_civicrm']];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $this->civicrm->initialize();
    $processor = \wf_crm_webform_postprocess::singleton($webform_submission->getWebform());
    $processor->validate($form, $form_state, $webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webform_submission) {
    $this->civicrm->initialize();
    $processor = \wf_crm_webform_postprocess::singleton($webform_submission->getWebform());
    $processor->preSave($webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $this->civicrm->initialize();
    $processor = \wf_crm_webform_postprocess::singleton($webform_submission->getWebform());
    $processor->postSave($webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteHandler() {
    $elements = array_filter($this->webform->getElementsDecoded(), function (array $element) {
      return strpos($element['#form_key'], 'civicrm_') !== 0;
    });
    $this->webform->setElements($elements);
    parent::deleteHandler();
  }

}
