<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\Utility\WebformArrayHelper;
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
   */
  public function getDefaultProperties() {
    return [
        'name' => '',
        'form_key' => NULL,
        'pid' => 0,
        'weight' => 0,
        'value' => '',
        'required' => 0,
        'search_prompt' => '',
        'none_prompt' => '',
        'results_display' => ['display_name'],
        'allow_create' => 0,
        // @todo rename from widget to something else.
        'widget' => 'autocomplete',
        'contact_type' => '',
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
        // @todo needs the UI exposed.
        // @todo rename default_static_value ?
        'default' => 'user',
        'default_contact_id' => '',
        'default_relationship' => '',
        // @todo needs the UI exposed.
        'allow_url_autofill' => TRUE,
        'dupes_allowed' => FALSE,
        'filters' => [
          'contact_sub_type' => 0,
          'group' => [],
          'tag' => [],
          'check_permissions' => 1,
        ],
        // Set for custom fields.
        'expose_list' => FALSE,
        'empty_option' => '',
      ] + parent::getDefaultProperties();
  }

  /**
   * {@inheritdoc}
   *
   * @see _webform_render_civicrm_contact()
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    // Webform removes values which equal their defaults but does not populate
    // they keys.
    $ensure_keys_have_values = [
      'hide_method',
      'no_hide_blank',
      'default',
      'default_contact_id',
      'default_relationship',
      'allow_url_autofill',
    ];
    foreach ($ensure_keys_have_values as $key) {
      if (empty($element['#' . $key])) {
        $element['#' . $key] = $this->getDefaultProperty($key);
    }
    }
    $element['#type'] = $element['#widget'] === 'autocomplete' ? 'textfield' : $element['#widget'];
    $element['#attributes']['data-hide-method'] = $element['#hide_method'];
    $element['#attributes']['data-no-hide-blank'] = (int) $element['#no_hide_blank'];

    $cid = wf_crm_aval($element, '#default_value', '');
    if ($element['#type'] === 'hidden') {
      // User may not change this value for hidden fields
      $element['#value'] = $cid;
      if (empty($element['#show_hidden_contact'])) {
        return;
      }
    }
    if (!empty($cid)) {
      // Don't lookup same contact again
      if (wf_crm_aval($element, '#attributes:data-civicrm-id') != $cid) {
        $filters = wf_crm_search_filters($node, $component);
        $name = wf_crm_contact_access($component, $filters, $cid);
        if ($name !== FALSE) {
          $element['#attributes']['data-civicrm-name'] = $name;
          $element['#attributes']['data-civicrm-id'] = $cid;
        }
        else {
          unset($cid);
        }
      }
    }
    if (empty($cid) && $element['#type'] === 'hidden' && $element['none_prompt']) {
      $element['#attributes']['data-civicrm-name'] = Xss::filter($element['none_prompt']);
    }
    parent::prepare($element, $webform_submission);
  }

  /**
   * {@inheritdoc}
   *
   * @see _webform_edit_civicrm_contact()
   * @see webform_civicrm_webform_component_presave only form_id 1 can have static user.
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['element']['value']['#access'] = FALSE;
    $form['element']['multiple']['#access'] = FALSE;


    \Drupal::getContainer()->get('civicrm')->initialize();
    $element_properties = $form_state->get('element_properties');

    $contact_type = $element_properties['contact_type'];
    $allow_create = $element_properties['allow_create'];

    $form['element']['widget'] = [
      '#type' => 'select',
      '#title' => $this->t('Form Widget'),
      '#default_value' => $element_properties['widget'],
      '#options' => [
        'autocomplete' => $this->t('Autocomplete'),
        'select' => $this->t('Select List'),
        'hidden' => $this->t('Static'),
        'textfield' => $this->t('Enter Contact ID')
      ],
    ];
    $allow_create ? $this->t('<strong>Contact Creation: Enabled</strong> - this contact has name/email fields on the webform.') : $this->t('<strong>Contact Creation: Disabled</strong> - no name/email fields for this contact on the webform.');
//    $form['civicrm']['#description'] = '<div class="messages ' . ($allow_create ? 'status' : 'warning') . '">' . $status . ' ' . \wf_crm_admin_help::helpIcon('contact_creation', t('Contact Creation')) . '</div>';
    $form['element']['search_prompt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search Prompt'),
      '#default_value' => $element_properties['search_prompt'],
      '#description' => $this->t('Text the user will see before selecting a contact.'),
      '#size' => 60,
      '#maxlength' => 1024,
    ];
    $form['element']['none_prompt'] = [
      '#type' => 'textfield',
      '#title' => $allow_create ? $this->t('Create Prompt') : $this->t('Not Found Prompt'),
      '#default_value' => $element_properties['none_prompt'],
      '#description' => $allow_create ? $this->t('This text should prompt the user to create a new contact.') : $this->t('This text should tell the user that no search results were found.'),
      '#size' => 60,
      '#maxlength' => 1024,
    ];
    $form['element']['results_display'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Contact Display Field(s)'),
      '#required' => TRUE,
      '#default_value' => $element_properties['results_display'],
      '#options' => $this->wf_crm_results_display_options($contact_type),
    ];
    $form['element']['show_hidden_contact'] = [
      '#type' => 'radios',
      '#title' => $this->t('Display Contact Name'),
      '#description' => $this->t('If enabled, this static element will show the contact that has been pre-selected (or else the Create/Not Found Prompt if set). Otherwise the element will not be visible.'),
      '#options' => [$this->t('No'), $this->t('Yes')],
      '#default_value' => $element_properties['show_hidden_contact'],
    ];

    $form['default'] = [
      '#type' => 'value',
      '#value' => $element_properties['default'],
    ];

    // Need to be hidden values so that they persist from configuration on the
    // main Webform CiviCRM settings form.
    $form['allow_create'] = [
      '#type' => 'value',
      '#value' => $element_properties['allow_create'],
    ];
    $form['contact_type'] = [
      '#type' => 'value',
      '#value' => $element_properties['contact_type'],
    ];
    return $form;
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
