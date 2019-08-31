<?php

namespace Drupal\webform_civicrm\Plugin\WebformElement;

use CRM_Core_BAO_Tag;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformInterface;
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
        'widget' => '',
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
        // Set for custom fields.
        'expose_list' => FALSE,
        'empty_option' => '',
      ] + parent::getDefaultProperties();
  }

  /**
   * {@inheritdoc}
   *
   * @todo port logic from _webform_render_civicrm_contact()
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
    $element['#attached']['library'][] = 'webform_civicrm/civicrm_contact';
    $element['#attached']['drupalSettings']['webform_civicrm'][$element['#form_key']] = [
      'hiddenFields' => [],
    ];
    $element['#theme'] = 'webform_civicrm_contact';
    $element['#type'] = $element['#widget'] === 'autocomplete' ? 'textfield' : $element['#widget'];
    list(, $c, ) = explode('_', $element['#form_key'], 3);
    $element['#attributes']['data-civicrm-contact'] = $c;
    $element['#attributes']['data-form-id'] = $webform_submission ? $webform_submission->getWebform()->id() : NULL;
    $element['#attributes']['data-hide-method'] = $this->getElementProperty($element, 'hide_method');
    $element['#attributes']['data-no-hide-blank'] = (int) $this->getElementProperty($element, 'no_hide_blank');


    $cid = $this->getElementProperty($element, 'default_value');
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
    list($contact_types, $sub_types) = wf_crm_get_contact_types();
    list(, $c, ) = explode('_', $element_properties['form_key'], 3);
    $contact_type = $element_properties['contact_type'];
    $allow_create = $element_properties['allow_create'];

    $form['form']['display_container']['#weight'] = 10;
    $form['form']['field_container']['#weight'] = 10;
    $form['validation']['#weight'] = 10;

    $form['form']['widget'] = [
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
    $form['form']['search_prompt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search Prompt'),
      '#default_value' => $element_properties['search_prompt'],
      '#description' => $this->t('Text the user will see before selecting a contact.'),
      '#size' => 60,
      '#maxlength' => 1024,
      '#states' => [
        'invisible' => [
          'select[name="properties[widget]"]' => ['value' => 'hidden'],
        ],
      ],
    ];
    $form['form']['none_prompt'] = [
      '#type' => 'textfield',
      '#title' => $allow_create ? $this->t('Create Prompt') : $this->t('Not Found Prompt'),
      '#default_value' => $element_properties['none_prompt'],
      '#description' => $allow_create ? $this->t('This text should prompt the user to create a new contact.') : $this->t('This text should tell the user that no search results were found.'),
      '#size' => 60,
      '#maxlength' => 1024,
    ];
    $form['form']['show_hidden_contact'] = [
      '#type' => 'radios',
      '#title' => $this->t('Display Contact Name'),
      '#description' => $this->t('If enabled, this static element will show the contact that has been pre-selected (or else the Create/Not Found Prompt if set). Otherwise the element will not be visible.'),
      '#options' => [$this->t('No'), $this->t('Yes')],
      '#default_value' => $element_properties['show_hidden_contact'],
    ];
    $form['form']['results_display'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Contact Display Field(s)'),
      '#required' => TRUE,
      '#default_value' => $element_properties['results_display'],
      '#options' => $this->wf_crm_results_display_options($contact_type),
    ];

    $form['field_handling'] = [
      '#type' => 'details',
      '#title' => $this->t('Contact Field Handling'),
    ];
    $form['field_handling']['no_autofill'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Skip Autofilling of'),
      '#description' => $this->t('Which fields should <em>not</em> be autofilled for this contact?'),
      '#default_value' => $element_properties['no_autofill'],
      // @todo fix this to add support for wf_crm_contact_fields.
      '#options' => ['' => '- ' . $this->t('None') . ' -'],
    ];
    $form['field_handling']['hide_fields'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Fields to Lock'),
      '#description' => $this->t('Prevent editing by disabling or hiding fields when a contact already exists.'),
      '#default_value' => $element_properties['hide_fields'],
      // @todo fix this to add support for wf_crm_contact_fields.
      '#options' => ['' => '- ' . $this->t('None') . ' -'],
    ];
    $form['field_handling']['hide_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Locked fields should be'),
      '#default_value' => $element_properties['hide_method'],
      '#options' => ['hide' => $this->t('Hidden'), 'disable' => $this->t('Disabled')],
      '#states' => [
        'visible' => [
          'select[name="properties[hide_fields][]"]' => ['value' => ''],
        ],
      ],
    ];
    $form['field_handling']['no_hide_blank'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Don't lock fields that are empty"),
      '#default_value' => $element_properties['no_hide_blank'],
      '#states' => [
        'visible' => [
          'select[name="properties[hide_fields][]"]' => ['value' => ''],
        ],
      ],
    ];
    $form['field_handling']['submit_disabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Submit disabled field value(s)'),
      '#description' => $this->t('Store disabled field value(s) in webform submissions.'),
      '#default_value' => $element_properties['submit_disabled'],
      '#states' => [
        'visible' => [
          'select[name="properties[hide_fields][]"]' => ['value' => ''],
        ],
      ],
    ];

    $form['defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Default value'),
      '#description' => $this->t('Should the form be pre-populated with an existing contact?<ul><li>Any filters set below will restrict this default.</li><li>If more than one contact meets the criteria, the first match will be picked. If multiple existing contact fields exist on the webform, each will select a different contact.</li></ul>'),
    ];
    $form['defaults']['default'] = [
      '#type' => 'select',
      '#title' => $this->t('Set default contact from'),
      '#options' => ['contact_id' => $this->t('Specified Contact')],
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $element_properties['default'],
    ];
    if ($c == 1 && $contact_type == 'individual') {
      $form['defaults']['default']['#options']['user'] = $this->t('Current User');
    }
    elseif ($c > 1) {
      $form['defaults']['default']['#options']['relationship'] = $this->t('Relationship to :contact', [':contact' => wf_crm_contact_label(1, $data)]);
      $form['defaults']['default_relationship'] = [
        '#type' => 'select',
        '#multiple' => TRUE,
        '#title' => $this->t('Specify Relationship(s)'),
        '#options' => [
          '' => '- ' . $this->t('No relationship types defined for @a to @b', ['@a' => $contact_types[$contact_type], '@b' => $contact_types[$data['contact'][1]['contact'][1]['contact_type']]]) . ' -'],
        '#default_value' => $element_properties['default_relationship'],
      ];
    }
    $form['defaults']['default']['#options']['auto'] = $this->t('Auto - From Filters');
    $form['defaults']['default_contact_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Contact'),
      '#id' => 'default-contact-id',
      '#states' => [
        'visible' => [
          'select[name="properties[default]"]' => ['value' => 'contact_id'],
        ],
      ],
    ];
    $cid = $element_properties['default_contact_id'];
    if ($cid && $name = wf_crm_contact_access($element_properties,
        ['check_permissions' => 1], $cid)) {
          $form['defaults']['default_contact_id']['#default_value'] = $cid;
          $form['defaults']['default_contact_id']['#attributes'] = [
            'data-civicrm-name' => $name,
            'data-civicrm-id' => $cid,
          ];
        }
    $form['defaults']['allow_url_autofill'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use contact id from URL'),
      '#default_value' => $element_properties['allow_url_autofill'],
      '#description' => $this->t('If the url contains e.g. %arg, it will be used to pre-populate this contact (takes precidence over other default values).', ['%arg' => "cid$c=123"]),
    ];
    if ($c > 1) {
      $form['defaults']['dupes_allowed'] = array(
        '#type' => 'checkbox',
        '#title' => $this->t('Allow Duplicate Autofill'),
        '#default_value' => $element_properties['dupes_allowed'],
        '#description' => $this->t('Check this box to allow a contact to be selected even if they already autofilled a prior field on the form. (For example, if contact 1 was autofilled with Bob Smith, should this field also be allowed to select Bob Smith or should it pick a different contact?)'),
      );
    }
    $form['defaults']['randomize'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Randomize'),
      '#default_value' => $element_properties['randomize'],
      '#description' => $this->t('Pick a contact at random if more than one meets criteria.'),
      '#states' => [
        'visible' => [
          'select[name="properties[default]"]' => ['value' => 'auto'],
        ],
      ],
    ];

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#description' => $this->t('Only contacts meeting filter criteria will be available as select options or default value.<br />Note: Filters only apply to how a contact is chosen on the form, they do not affect how a contact is saved.'),
    ];
    if (!empty($sub_types[$contact_type])) {
      $form['filters']['contact_sub_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Type of @contact', ['@contact' => $contact_types[$contact_type]]),
        '#options' => [$this->t('- Any -')] + $sub_types[$contact_type],
        '#default_value' => $element_properties['filters']['contact_sub_type'],
      ];
    }
    $form['filters']['group'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Groups'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + wf_crm_apivalues('group_contact', 'getoptions', ['field' => 'group_id']),
      '#default_value' => $element_properties['filters']['group'],
      '#description' => $this->t('Listed contacts must be members of at least one of the selected groups (leave blank to not filter by group).'),
    ];
    $tags = [];
    $form['filters']['tag'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Tags'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + CRM_Core_BAO_Tag::getTags('civicrm_contact', $tags, NULL, '- '),
      '#default_value' => $element_properties['filters']['tag'],
      '#description' => $this->t('Listed contacts must be have at least one of the selected tags (leave blank to not filter by tag).'),
    ];
    if ($c > 1) {
      $form['filters']['relationship']['contact'] = [
        '#type' => 'select',
        '#title' => $this->t('Relationships to'),
        '#options' => ['' => '- ' . $this->t('None') . ' -'],
        '#default_value' => wf_crm_aval($element_properties['filters'], 'relationship:contact'),
      ];
      $form['filters']['relationship']['type'] = [
        '#type' => 'select',
        '#multiple' => TRUE,
        '#title' => $this->t('Specify Relationship(s)'),
        '#options' => ['' => '- ' . $this->t('Any relation') . ' -'],
        '#default_value' => wf_crm_aval($element_properties['filters'], 'relationship:type'),
      ];
      // Fill relationship data for defaults and filters
      /*
      $all_relationship_types = array_fill(1, $c - 1, array());
      for ($i = 1; $i < $c; ++$i) {
        $form['defaults']['default_relationship_to']['#options'][$i] = $form['filters']['relationship']['contact']['#options'][$i] = wf_crm_contact_label($i, $data, 'plain');
        $rtypes = wf_crm_get_contact_relationship_types($contact_type, $data['contact'][$i]['contact'][1]['contact_type'], $data['contact'][$c]['contact'][1]['contact_sub_type'], $data['contact'][$i]['contact'][1]['contact_sub_type']);
        foreach ($rtypes as $k => $v) {
          $all_relationship_types[$i][] = ['key' => $k, 'value' => $v . ' ' . wf_crm_contact_label($i, $data, 'plain')];
          $form['defaults']['default_relationship']['#options'][$k] = $form['filters']['relationship']['type']['#options'][$k] = $v . ' ' . wf_crm_contact_label($i, $data, 'plain');
        }
        if (!$rtypes) {
          $all_relationship_types[$i][] = ['key' => '', 'value' => '- ' . t('No relationship types defined for @a to @b', ['@a' => $contact_types[$contact_type], '@b' => $contact_types[$data['contact'][$i]['contact'][1]['contact_type']]]) . ' -'];
        }
      }
      */
      $form['#attributes']['data-reltypes'] = json_encode($all_relationship_types);
    }
    $form['filters']['check_permissions'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Enforce Permissions'),
      '#default_value' => $element_properties['filters']['check_permissions'],
      '#description' => $this->t('Only show contacts the acting user has permission to see in CiviCRM.') . '<br />' . $this->t('WARNING: Keeping this option enabled is highly recommended unless you are effectively controlling access by another method.'),
    );


    // Need to be hidden values so that they persist from configuration on the
    // main Webform CiviCRM settings form.
    $form['allow_create'] = [
      '#type' => 'hidden',
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

  /**
   * Lookup contact name from ID, verify permissions, and attach as html data.
   *
   * Used when rendering or altering a CiviCRM contact field.
   *
   * Also sets options for select lists.
   *
   * @param \Drupal\webform\WebformInterface $node
   *   Node object
   * @param array $component
   *   Webform component
   * @param array $element
   *   FAPI form element (reference)
   * @param array $ids
   *   Known entity ids
   */
  public static function wf_crm_fill_contact_value(WebformInterface $node, array $component, array &$element, array $ids = NULL) {
    $cid = wf_crm_aval($element, '#default_value', '');
    if ($element['#type'] == 'hidden') {
      // User may not change this value for hidden fields
      $element['#value'] = $cid;
      if (!$component['#show_hidden_contact']) {
        return;
      }
    }
    if ($cid) {
      // Don't lookup same contact again
      if (wf_crm_aval($element, '#attributes:data-civicrm-id') != $cid) {
        $filters = wf_crm_search_filters($node, $element);
        $name = wf_crm_contact_access($element, $filters, $cid);
        if ($name !== FALSE) {
          $element['#attributes']['data-civicrm-name'] = $name;
          $element['#attributes']['data-civicrm-id'] = $cid;
        }
        else {
          unset($cid);
        }
      }
    }
    if (empty($cid) && $element['#type'] == 'hidden' && $element['none_prompt']) {
      $element['#attributes']['data-civicrm-name'] = Html::escape($element['none_prompt']);
    }
    // Set options list for select elements. We do this here so we have access to entity ids.
    if (is_array($ids) && $element['#type'] == 'select') {
      $filters = wf_crm_search_filters($node, $component);
      $element['#options'] = wf_crm_contact_search($node, $component, $filters, wf_crm_aval($ids, 'contact', []));
      // Display empty option unless there are no results
      if (!$component['#allow_create'] || count($element['#options']) > 1) {
        $element['#empty_option'] = Xss::filter($component[$element['#options'] ? 'search_prompt' : 'none_prompt']);
      }
    }
  }



}
