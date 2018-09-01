<?php

namespace Drupal\webform_civicrm\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerInterface;

include_once __DIR__ . '/../../includes/utils.inc';
include_once __DIR__ . '/../../includes/wf_crm_admin_help.inc';
include_once __DIR__ . '/../../includes/wf_crm_admin_form.inc';

class WebformCiviCRMSettingsForm extends FormBase {

  /**
   * @return \Drupal\webform\WebformInterface
   */
  public function getWebform() {
    return $this->getRouteMatch()->getParameter('webform');
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
    $admin_form = new \wf_crm_admin_form($form, $form_state, (object) [
      'nid' => $webform->id(),
      'title' => $this->getRouteMatch()->getParameter('webform')->label(),
    ], $webform);
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
    $instance_ids = $handler_collection->getInstanceIds();
    $values = $form_state->getValues();

    $remove_handler = empty($values['nid']);

    /** @var \Drupal\webform\Plugin\WebformHandlerInterface $handler */
    if (empty($instance_ids)) {
      if ($remove_handler) {
        $this->messenger()->addWarning('No changes made to CiviCRM settings');
        return;
      }
      $handler_mananger = \Drupal::getContainer()->get('plugin.manager.webform.handler');
      $handler = $handler_mananger->createInstance('webform_civicrm');
      $handler->setWebform($webform);
      $handler->setHandlerId('webform_civicrm');
      $handler->setStatus(TRUE);
      $webform->addWebformHandler($handler);
    }
    else {
      $handler = $handler_collection->get(reset($instance_ids));

      if ($remove_handler) {
        if (!$handler->getHandlerId()) {
          $handler->setHandlerId('webform_civicrm');
        }
        $webform->deleteWebformHandler($handler);

        $elements = array_filter($webform->getElementsDecoded(), function (array $element, $key = null) {
          return empty($element['#webform_civicrm']);
        });
        $webform->setElements($elements);
        $webform->save();

        $this->messenger()->addMessage('Removed CiviCRM');
        return;
      }
    }

    // @todo need to implement \wf_crm_admin_form::postProcess logic.
    $admin_form = new \wf_crm_admin_form($form, $form_state, (object) [
      'nid' => $webform->id(),
      'title' => $this->getRouteMatch()->getParameter('webform')->label(),
    ], $webform);
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
