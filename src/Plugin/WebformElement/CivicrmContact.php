<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElement\TextField;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;

// Include legacy files for their procedural functions.
// @todo convert required functions into injectable services.
include_once __DIR__ . '/../../../includes/wf_crm_admin_help.inc';

/**
 * Provides a 'textfield' element.
 *
 * @WebformElement(
 *   id = "civicrm_contact",
 *   api =
 *   "https://api.drupal.org/api/drupal/core!lib!Drupal!Core!Render!Element!Textfield.php/class/Textfield",
 *   label = @Translation("CiviCRM Contact"), description =
 *   @Translation("Choose existing contact."), category =
 *   @Translation("CiviCRM"),
 * )
 */
class CivicrmContact extends WebformElementBase {

  /**
   * {@inheritdoc}
   *
   * @see _webform_render_civicrm_contact()
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    parent::prepare($element, $webform_submission);
  }

  /**
   * {@inheritdoc}
   *
   * @see _webform_edit_civicrm_contact()
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    \Drupal::getContainer()->get('civicrm')->initialize();
    /** @var \Drupal\webform_ui\Form\WebformUiElementFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    $webform = $form_object->getWebform();

    $element_properties = $form_state->get('element_properties');

    list($contact_types, $sub_types) = wf_crm_get_contact_types();
    $contact_type = $this->configuration['#extra']['contact_type'];
    $allow_create = $element_properties['extra']['allow_create'];

    $form['civicrm'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('CiviCRM settings'),
      '#access' => TRUE,
      '#weight' => -50,
    ];
//    $form['#suffix'] = \wf_crm_admin_help::helpTemplate();
    $form['civicrm']['widget'] = [
      '#type' => 'select',
      '#title' => t('Form Widget'),
      '#default_value' => $element_properties['extra']['widget'],
      '#options' => [
        'autocomplete' => t('Autocomplete'),
        'select' => t('Select List'),
        'hidden' => t('Static'),
        'textfield' => t('Enter Contact ID')
      ],
      '#weight' => -9,
    ];
    $status = $allow_create ? t('<strong>Contact Creation: Enabled</strong> - this contact has name/email fields on the webform.') : t('<strong>Contact Creation: Disabled</strong> - no name/email fields for this contact on the webform.');
//    $form['civicrm']['#description'] = '<div class="messages ' . ($allow_create ? 'status' : 'warning') . '">' . $status . ' ' . \wf_crm_admin_help::helpIcon('contact_creation', t('Contact Creation')) . '</div>';
    $form['civicrm']['search_prompt'] = [
      '#type' => 'textfield',
      '#title' => t('Search Prompt'),
      '#default_value' => $element_properties['extra']['search_prompt'],
      '#description' => t('Text the user will see before selecting a contact.'),
      '#size' => 60,
      '#maxlength' => 1024,
      '#weight' => -7,
    ];
    $form['civicrm']['none_prompt'] = [
      '#type' => 'textfield',
      '#title' => $allow_create ? t('Create Prompt') : t('Not Found Prompt'),
      '#default_value' => $element_properties['extra']['none_prompt'],
      '#description' => $allow_create ? t('This text should prompt the user to create a new contact.') : t('This text should tell the user that no search results were found.'),
      '#size' => 60,
      '#maxlength' => 1024,
      '#weight' => -6,
      '#parents' => ['extra', 'none_prompt'],
    ];
    $form['civicrm']['results_display'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => t("Contact Display Field(s)"),
      '#required' => TRUE,
      '#default_value' => $element_properties['extra']['results_display'],
      '#options' => $this->wf_crm_results_display_options($contact_type),
    ];
    $form['civicrm']['show_hidden_contact'] = [
      '#type' => 'radios',
      '#title' => t('Display Contact Name'),
      '#description' => t('If enabled, this static element will show the contact that has been pre-selected (or else the Create/Not Found Prompt if set). Otherwise the element will not be visible.'),
      '#options' => [t('No'), t('Yes')],
      '#default_value' => $element_properties['extra']['show_hidden_contact'],
      '#weight' => -5,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    return [
        'name' => '',
        'form_key' => NULL,
        'pid' => 0,
        'weight' => 0,
        'value' => '',
        'required' => 0,
        'extra' => [
          'search_prompt' => '',
          'none_prompt' => '',
          'results_display' => ['display_name'],
          'allow_create' => 0,
          'widget' => 'autocomplete',
          'show_hidden_contact' => 0,
          'unique' => 0,
          'title_display' => 'before',
          'randomize' => 0,
          'description' => '',
          'no_autofill' => [],
          'hide_fields' => [],
          'hide_method' => 'hide',
          'no_hide_blank' => FALSE,
          'submit_disabled' => FALSE,
          'attributes' => [],
          'private' => FALSE,
          'default' => '',
          'default_contact_id' => '',
          'default_relationship' => '',
          'allow_url_autofill' => TRUE,
          'dupes_allowed' => FALSE,
          'filters' => [
            'contact_sub_type' => 0,
            'group' => [],
            'tag' => [],
            'check_permissions' => 1,
          ],
        ],
      ] + parent::getDefaultProperties();
  }

  /**
   * Returns a list of fields that can be shown in an "Existing Contact" field display
   * In the future we could use api.getfields for this, but that also returns a lot of stuff we don't want
   *
   * @return array
   */
  function wf_crm_results_display_options($contact_type) {
    $options = array(
      'display_name' => t("Display Name"),
      'sort_name' => t("Sort Name"),
    );
    if ($contact_type == 'individual') {
      $options += array(
        'first_name' => t("First Name"),
        'middle_name' => t("Middle Name"),
        'last_name' => t("Last Name"),
        'current_employer' => t("Current Employer"),
        'job_title' => t("Job Title"),
      );
    }
    else {
      $options[$contact_type . '_name'] = $contact_type == 'organization' ? t("Organization Name") : t("Household Name");
    }
    $options += array(
      'nick_name' => t("Nick Name"),
      'id' => t("Contact ID"),
      'external_identifier' => t("External ID"),
      'source' => t("Source"),
      'email' => t("Email"),
      'city' => t("City"),
      'county' => t("District/County"),
      'state_province' => t("State/Province"),
      'country' => t("Country"),
      'postal_code' => t("Postal Code"),
      'phone' => t("Phone"),
    );
    return $options;
  }

}
