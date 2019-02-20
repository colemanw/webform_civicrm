<?php

namespace Drupal\webform_civicrm\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\Plugin\WebformHandlerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// Include legacy files for their procedural functions.
// @todo convert required functions into injectable services.
include_once __DIR__ . '/../../includes/utils.inc';
include_once __DIR__ . '/../../includes/wf_crm_admin_help.inc';
include_once __DIR__ . '/../../includes/wf_crm_admin_form.inc';

class WebformCiviCRMSettingsForm extends FormBase {

  protected $webformHandlerManager;

  public function __construct(RouteMatchInterface $route_match, WebformHandlerManagerInterface $webform_handler_manager) {
    $this->routeMatch = $route_match;
    $this->webformHandlerManager = $webform_handler_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('plugin.manager.webform.handler')
    );
  }

  /**
   * @return \Drupal\webform\WebformInterface
   */
  public function getWebform() {
    return $this->routeMatch->getParameter('webform');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_civicrm_settings_form';
  }

  /**
   * {@inheritdoc}
   *
   * @todo slowly move parts of the D7 handling into here.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();
    $admin_form = new \wf_crm_admin_form($form, $form_state, (object) [], $webform);
    $form = $admin_form->buildForm();;
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @todo find a more elegant way to handle the handler creation/removal.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $webform = $this->getWebform();
    $handler_collection = $webform->getHandlers('webform_civicrm');
    $values = $form_state->getValues();

    $has_handler = $handler_collection->has('webform_civicrm');
    $remove_handler = empty($values['nid']);
    if (!$has_handler && $remove_handler) {
      $this->messenger()->addWarning('No changes made to CiviCRM settings');
      return;
    }
    /** @var \Drupal\webform\Plugin\WebformHandlerInterface $handler */
    if (!$has_handler) {
      $handler = $this->webformHandlerManager->createInstance('webform_civicrm');
      $handler->setWebform($webform);
      $handler->setHandlerId('webform_civicrm');
      $handler->setStatus(TRUE);
      $webform->addWebformHandler($handler);
    }
    else {
      $handler = $handler_collection->get('webform_civicrm');
    }

    if ($remove_handler) {
      $webform->deleteWebformHandler($handler);
      $this->messenger()->addMessage('Removed CiviCRM');
      return;
    }

    $elements = $webform->getElementsDecoded();

    $admin_form = new \wf_crm_admin_form($form, $form_state, (object) [], $webform);
    $form_state->cleanValues();
    $admin_form->setSettings($form_state->getValues());
    $admin_form->rebuildData();
    $settings = $admin_form->getSettings();
    $handler_configuration = $handler->getConfiguration();
    $handler_configuration['settings'] = $settings;
    $handler->setConfiguration($handler_configuration);

    $admin_form->postProcess();
    $webform->save();

    $this->messenger()->addMessage('Saved CiviCRM settings');
  }

  /**
   * AJAX callback for elements with a pathstr.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element to refresh.
   */
  public static function pathstrAjaxRefresh(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $element = NestedArray::getValue($form, explode(':', $triggering_element['#ajax']['pathstr']));
    return $element;
  }

}
