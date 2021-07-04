<?php

namespace Drupal\webform_civicrm\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\Plugin\WebformHandlerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    $admin_form = \Drupal::service('webform_civicrm.admin_form')->initialize($form, $form_state, $webform);
    $form = $admin_form->buildForm();
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
    // Check if this is the confirmation form for removed fields.
    if (!isset($values['nid']) && isset($values['delete'], $values['disable'], $values['cancel'])) {
      $triggering_element = $form_state->getTriggeringElement();
      if ($triggering_element['#parents'][0] === 'cancel') {
        $this->messenger()->addMessage('Cancelled');
        return;
      }
      if ($triggering_element['#parents'][0] === 'delete') {
        // Restore the form state values.
        $values += $form_state->get('vals') ?: [];
        $form_state->setValues($values);
      }
      if ($triggering_element['#parents'][0] === 'disable') {
        // @todo probably restore as well, but flag later on not to delete.
        $this->messenger()->addMessage('Disable is not yet supported, canceled form save.');
        return;
      }
    }

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

    $admin_form = \Drupal::service('webform_civicrm.admin_form')->initialize($form, $form_state, $webform);
    $form_state->cleanValues();
    $admin_form->setSettings($form_state->getValues());
    $admin_form->rebuildData();
    $settings = $admin_form->getSettings();
    $handler_configuration = $handler->getConfiguration();
    $handler_configuration['settings'] = $settings;
    $handler->setConfiguration($handler_configuration);

    $admin_form->postProcess();
    if (empty($admin_form->confirmPage)) {
      $webform->save();
      $this->messenger()->addMessage('Saved CiviCRM settings');
    }
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
