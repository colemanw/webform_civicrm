<?php

namespace Drupal\webform_civicrm;

class Fields implements FieldsInterface {

  protected $components = [];
  protected $sets = [];
  /**
   * Store data from CiviCRM API getfields action.
   */
  protected $fieldMetadata = [];

  public function __construct(UtilsInterface $utils) {
    $this->utils = $utils;
  }

  /**
   * {@inheritdoc}
   */
  public function get($var = 'fields') {
    if ($var === 'tokens') {
      return [
        'display_name' => t('display name'),
        'first_name' => t('first name'),
        'nick_name' => t('nickname'),
        'middle_name' => t('middle name'),
        'last_name' => t('last name'),
        'individual_prefix' => t('name prefix'),
        'individual_suffix' => t('name suffix'),
        'gender' => t('gender'),
        'birth_date' => t('birth date'),
        'job_title' => t('job title'),
        'current_employer' => t('current employer'),
        'contact_id' => t('contact id'),
        'street_address' => t('street address'),
        'city' => t('city'),
        'state_province' => t('state/province abbr'),
        'state_province_name' => t('state/province full'),
        'postal_code' => t('postal code'),
        'country' => t('country'),
        'world_region' => t('world region'),
        'phone' => t('phone number'),
        'email' => t('email'),
      ];
    }
    return $this->wf_crm_get_fields($var);
  }

  protected function getComponents(): array {
    if (empty($this->components)) {
      $this->components = $this->utils->wf_crm_get_civi_setting('enable_components');
    }

    return $this->components;
  }

  protected function getSets(array $components): array {
    if (empty($this->sets)) {
      $sets = [
        'contact' => ['entity_type' => 'contact', 'label' => t('Contact Fields')],
        'other' => ['entity_type' => 'contact', 'label' => t('Tags and Groups'), 'max_instances' => 1],
        'address' => ['entity_type' => 'contact', 'label' => t('Address'), 'max_instances' => 9, 'custom_fields' => 'combined'],
        'phone' => ['entity_type' => 'contact', 'label' => t('Phone'), 'max_instances' => 9, 'custom_fields' => 'combined'],
        'email' => ['entity_type' => 'contact', 'label' => t('Email'), 'max_instances' => 9, 'custom_fields' => 'combined'],
        'website' => ['entity_type' => 'contact', 'label' => t('Website'), 'max_instances' => 9, 'custom_fields' => 'combined'],
        'im' => ['entity_type' => 'contact', 'label' => t('Instant Message'), 'max_instances' => 9, 'custom_fields' => 'combined'],
        'activity' => ['entity_type' => 'activity', 'label' => t('Activity'), 'max_instances' => 99,  'attachments' => TRUE],
        'relationship' => ['entity_type' => 'contact', 'label' => t('Relationship'), 'help_text' => TRUE, 'custom_fields' => 'combined'],
      ];
      $civicrm_version = $this->utils->wf_crm_apivalues('System', 'get')[0]['version'];
      // Grant is moved to extension after > 5.47.0.
      if (version_compare($civicrm_version, '5.47') >= 0) {
        $components = array_diff($components, ['CiviGrant']);
        $grantStatus = $this->utils->wf_crm_apivalues('Extension', 'get', [
          'full_name' => 'civigrant'
        ], 'status');
        if (array_pop($grantStatus) == 'installed') {
          $components[] = 'CiviGrant';
        }
      }
      $conditional_sets = [
        'CiviCase' => ['entity_type' => 'case', 'label' => t('Case'), 'max_instances' => 30],
        'CiviEvent' => ['entity_type' => 'participant', 'label' => t('Participant'), 'max_instances' => 9],
        'CiviContribute' => ['entity_type' => 'contribution', 'label' => t('Contribution')],
        'CiviMember' => ['entity_type' => 'membership', 'label' => t('Membership'), 'custom_fields' => 'combined'],
        'CiviGrant' => ['entity_type' => 'grant', 'label' => t('Grant'), 'max_instances' => 30, 'attachments' => TRUE],
      ];
      foreach ($conditional_sets as $component => $set) {
        if (in_array($component, $components, TRUE)) {
          $sets[$set['entity_type']] = $set;
        }
      }
      // Contribution line items
      if (in_array('CiviContribute', $components, TRUE)) {
        $sets['line_items'] = ['entity_type' => 'line_item', 'label' => t('Line Items')];
      }
      $extra_sets = $this->utils->wf_crm_get_empty_sets();
      $sets += $extra_sets;
      $this->sets = $sets;
    }

    return $this->sets;
  }

  protected function getMoneyDefaults(): array {
    return [
      'type' => 'civicrm_number',
      'data_type' => 'Money',
      'extra' => [
        'field_prefix' => $this->utils->wf_crm_get_civi_setting('defaultCurrencySymbol', '$'),
        'point' => $this->utils->wf_crm_get_civi_setting('monetaryDecimalPoint', '.'),
        'separator' => $this->utils->wf_crm_get_civi_setting('monetaryThousandSeparator', ','),
        'decimals' => 2,
        'min' => 0,
      ],
    ];
  }

  /**
   * Use CiviCRM API getfields so we can populate field info dynamically.
   */
  protected function getFieldMetadata(): void {
    $components = $this->getComponents();
    $setNames = array_keys($this->getSets($components));
    foreach ($setNames as $setName) {
      $result = $this->utils->wf_crm_apivalues($setName, 'getfields');
      if ($result) {
        $this->fieldMetadata[$setName] = $result;
      }
    }
    array_filter($this->fieldMetadata);
  }

  /**
   * Use the CiviCRM API metadata to further fill out the $fields array.
   */
  protected function addFieldMetadata(array $fields): array {
    foreach ($fields as $fieldName => $field) {
      [$entity, $name] = explode('_', $fieldName, 2);
      if ($this->fieldMetadata[$entity][$name] ?? FALSE) {
        $fieldLength = $this->addFieldLength($this->fieldMetadata[$entity][$name], $field);
        // Merge the existing data last so this file can always override CiviCRM metadata.
        $fields[$fieldName] = array_merge($fieldLength, $fields[$fieldName]);
      }
    }
    return $fields;
  }

  /**
   * Add the maximum length to a field in the $fields array based upon the Civi metadata.
   */
  protected function addFieldLength(array $fieldMetadata, array $field): array {
    $lengthData = [];
    if (isset($fieldMetadata['maxlength']) && in_array($field['type'], ['textfield', 'textarea'])) {
      $lengthData['counter_type'] = 'character';
      $lengthData['counter_maximum'] = $fieldMetadata['maxlength'];
      $lengthData['counter_maximum_message'] = ' ';
    }
    return $lengthData;
  }

  protected function wf_crm_get_fields($var = 'fields') {
    $components = $this->getComponents();
    $sets = $this->getSets($components);
    $elements = \Drupal::service('plugin.manager.webform.element')->getInstances();

    static $fields = [];

    if (!$fields) {
      $moneyDefaults = $this->getMoneyDefaults();
      $fieldMetadata = $this->getFieldMetadata();

      // Field keys are in the format table_column
      // Use a # sign as a placeholder for field number in the title (or by default it will be appended to the end)
      // Setting 'expose_list' allows the value to be set on the config form
      // Set label for 'empty_option' for exposed lists that do not require input
      $fields['contact_contact_sub_type'] = [
        'name' => t('Type of @contact'),
        'type' => 'select',
        'extra' => ['multiple' => 1],
        'civicrm_live_options' => 1,
        'expose_list' => TRUE,
      ];
      $fields['contact_existing'] = [
        'name' => t('Existing Contact'),
        'type' => 'civicrm_contact',
        'search_prompt' => t('- Choose existing -'),
        'widget' => 'hidden',
      ];
      // Organization / household names
      foreach (['organization' => t('Organization Name'), 'legal' => t('Legal Name'), 'household' => t('Household Name')] as $key => $label) {
        $fields['contact_' . $key . '_name'] = [
          'name' => $label,
          'type' => 'textfield',
          'contact_type' => $key == 'household' ? 'household' : 'organization',
        ];
      }
      $fields['contact_sic_code'] = [
        'name' => t('SIC Code'),
        'type' => 'textfield',
        'contact_type' => 'organization',
      ];
      // Individual names
      $enabled_names = $this->utils->wf_crm_get_civi_setting('contact_edit_options');
      $name_options = array_column($this->utils->wf_crm_apivalues('OptionValue', 'get', ['option_group_id' => 'contact_edit_options', 'return' => ['name', 'value']]), 'name', 'value');
      $enabled_names = array_intersect_key($name_options, array_flip($enabled_names));
      foreach (['prefix_id' => t('Name Prefix'), 'formal_title' => t('Formal Title'), 'first_name' => t('First Name'), 'middle_name' => t('Middle Name'), 'last_name' => t('Last Name'), 'suffix_id' => t('Name Suffix')] as $key => $label) {
        if (in_array(ucwords(str_replace(['_id', '_'], ['', ' '], $key)),
          $enabled_names, TRUE)) {
          $fields['contact_' . $key] = [
            'name' => $label,
            'type' => strpos($key, '_id') ? 'select' : 'textfield',
            'contact_type' => 'individual',
          ];
        }
      }
      $fields['contact_nick_name'] = [
        'name' => t('Nickname'),
        'type' => 'textfield',
      ];
      $fields['contact_gender_id'] = [
        'name' => t('Gender'),
        // Gender should be textfield if using https://civicrm.org/extensions/gender-self-identify
        'type' => function_exists('genderselfidentify_civicrm_apiWrappers') ? 'textfield' : 'select',
        'contact_type' => 'individual',
      ];
      $fields['contact_job_title'] = [
        'name' => t('Job Title'),
        'type' => 'textfield',
        'contact_type' => 'individual',
      ];
      $fields['contact_birth_date'] = [
        'name' => t('Birth Date'),
        'type' => 'date',
        'extra' => [
          'start_date' => '-100 years',
          'end_date' => 'now',
        ],
        'contact_type' => 'individual',
      ];
      $fields['contact_preferred_communication_method'] = [
        'name' => t('Preferred Communication Method(s)'),
        'type' => 'select',
        'extra' => ['multiple' => 1],
      ];
      $fields['contact_privacy'] = [
        'name' => t('Privacy Preferences'),
        'type' => 'select',
        'extra' => ['multiple' => 1],
      ];
      $fields['contact_preferred_language'] = [
        'name' => t('Preferred Language'),
        'type' => 'select',
        'value' => $this->utils->wf_crm_get_civi_setting('lcMessages', 'en_US'),
      ];
      if (isset($elements['managed_file']) && !$elements['managed_file']->isDisabled() && !$elements['managed_file']->isHidden()) {
        $fields['contact_image_url'] = [
          'name' => t('Upload Image'),
          'type' => 'managed_file',
          'extra' => array('width' => 40),
          'data_type' => 'File',
        ];
      }
      $fields['contact_contact_id'] = [
        'name' => t('Contact ID'),
        'type' => 'hidden',
      ];
      $fields['contact_user_id'] = [
        'name' => t('User ID'),
        'type' => 'hidden',
      ];
      $fields['contact_external_identifier'] = [
        'name' => t('External ID'),
        'type' => 'hidden',
      ];
      $fields['contact_source'] = [
        'name' => t('Source'),
        'type' => 'textfield',
      ];
      $fields['contact_cs'] = [
        'name' => t('Checksum'),
        'type' => 'hidden',
        'value_callback' => TRUE,
      ];
      $fields['contact_employer_id'] = [
        'name' => t('Current Employer'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('None'),
        'data_type' => 'ContactReference',
        'contact_type' => 'individual',
        'reference_contact_type' => 'organization'
      ];
      $fields['contact_is_deceased'] = [
        'name' => t('Is Deceased'),
        'type' => 'select',
        'extra' => ['aslist' => 0],
        'contact_type' => 'individual',
      ];
      $fields['contact_deceased_date'] = [
        'name' => t('Deceased Date'),
        'type' => 'date',
        'extra' => [
          'start_date' => '-100 years',
          'end_date' => 'now',
        ],
        'contact_type' => 'individual',
      ];
      $fields['email_email'] = [
        'name' => t('Email'),
        'type' => 'email',
      ];
      $addressOptions = [
        'street_address' => t('Street Address'),
        'street_name' => t('Street Name'),
        'street_number' => t('Street Number'),
        'street_unit' => t('Street Number Suffix'),
        'name' => t('Address Name'),
        'supplemental_address_1' => t('Street Address # Line 2'),
        'supplemental_address_2' => t('Street Address # Line 3'),
        'supplemental_address_3' => t('Street Address # Line 4'),
        'city' => t('City'),
      ];
      foreach ($addressOptions as $key => $value) {
        $fields['address_' . $key] = [
          'name' => $value,
          'type' => 'textfield',
          'extra' => ['width' => $key === 'city' ? 20 : 60],
        ];
      }
      $fields['address_postal_code'] = [
        'name' => t('Postal Code'),
        'type' => 'textfield',
        'extra' => ['width' => 7],
      ];
      $fields['address_postal_code_suffix'] = [
        'name' => t('Postal Code Suffix'),
        'type' => 'textfield',
        'extra' => [
          'width' => 5,
          'description' => t('+4 digits of Zip Code'),
        ],
      ];
      $fields['address_country_id'] = [
        'name' => t('Country'),
        'type' => 'select',
        'civicrm_live_options' => 1,
        'extra' => ['aslist' => 1],
        'default_value' => $this->utils->wf_crm_get_civi_setting('defaultContactCountry', 1228),
      ];
      $fields['address_state_province_id'] = [
        'name' => t('State/Province'),
        'type' => 'textfield',
        'extra' => [
          'maxlength' => 5,
          'width' => 4,
        ],
        'data_type' => 'state_province_abbr',
      ];
      $fields['address_county_id'] = [
        'name' => t('District/County'),
        'type' => 'textfield',
      ];
      $fields['address_master_id'] = [
        'name' => t('Share address of'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['aslist' => 0],
        'empty_option' => t('Do Not Share'),
      ];
      $fields['phone_phone'] = [
        'name' => t('Phone Number'),
        'type' => 'textfield',
      ];
      $fields['phone_phone_ext'] = [
        'name' => t('Phone Extension'),
        'type' => 'textfield',
        'extra' => [
          'width' => 4,
        ],
      ];
      $fields['phone_phone_type_id'] = [
        'name' => t('Phone # Type'),
        'type' => 'select',
        'table' => 'phone',
        'expose_list' => TRUE,
      ];
      $fields['im_name'] = [
        'name' => t('Screen Name'),
        'type' => 'textfield',
      ];
      $fields['im_provider_id'] = [
        'name' => t('IM Provider'),
        'type' => 'select',
        'expose_list' => TRUE,
      ];
      /*
       * @todo is this fine w/ the core file element?
       $defaultLocType = wf_crm_aval(wf_civicrm_api('LocationType', 'get', [
         'return' => ["id"],
         'is_default' => 1,
       ]), 'id');
       ...
      'value' => $defaultLocType,
      ...
       */
      foreach (['address' => t('Address # Location'), 'phone' => t('Phone # Location'), 'email' => t('Email # Location'), 'im' => t('IM # Location')] as $key => $label) {
        if (isset($sets[$key])) {
          $fields[$key . '_location_type_id'] = [
            'name' => $label,
            'type' => 'select',
            'expose_list' => TRUE,
            'value' => '1',
          ];
          $fields[$key . '_is_primary'] = [
            'name' => 'Is Primary',
            'type' => 'select',
            'expose_list' => TRUE,
            'value' => '1',
          ];
        }
      }
      $fields['website_url'] = [
        'name' => t('Website'),
        'type' => 'textfield',
        'data_type' => 'Link',
      ];
      $fields['website_website_type_id'] = [
        'name' => t('Website # Type'),
        'type' => 'select',
        'expose_list' => TRUE,
      ];
      $fields['other_group'] = [
        'name' => t('Group(s)'),
        'type' => 'select',
        'civicrm_live_options' => 1,
        'extra' => ['multiple' => 1],
        'table' => 'group',
        'expose_list' => TRUE,
      ];
      $fields['activity_activity_type_id'] = [
        'name' => t('Activity # Type'),
        'type' => 'select',
        'expose_list' => TRUE,
      ];
      $fields['activity_target_contact_id'] = [
        'name' => t('Activity # Participant(s)'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['multiple' => 1],
        'data_type' => 'ContactReference',
      ];
      $fields['activity_source_contact_id'] = [
        'name' => t('Activity # Creator'),
        'type' => 'select',
        'expose_list' => TRUE,
        'data_type' => 'ContactReference',
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['activity_subject'] = [
        'name' => t('Activity # Subject'),
        'type' => 'textfield',
      ];
      $fields['activity_details'] = [
        'name' => t('Activity # Details'),
        'type' => 'text_format',
        'allowed_formats' => [],
      ];
      $fields['activity_status_id'] = [
        'name' => t('Activity # Status'),
        'type' => 'select',
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['activity_priority_id'] = [
        'name' => t('Activity # Priority'),
        'type' => 'select',
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      ];
      $fields['activity_assignee_contact_id'] = [
        'name' => t('Assign Activity # to'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => ['multiple' => 1],
        'data_type' => 'ContactReference',
      ];
      $fields['activity_location'] = [
        'name' => t('Activity # Location'),
        'type' => 'textfield',
      ];
      $fields['activity_activity_date_time'] = [
        'name' => t('Activity # Date'),
        'type' => 'datetime',
        'default_value' => 'now',
        'date_time_step' => 60,
      ];
      $fields['activity_duration'] = [
        'name' => t('Activity # Duration'),
        'type' => 'civicrm_number',
        'field_suffix' =>  t('min.'),
        /*ToDo Figure out why setting min does not work!*/
        'min' => 0,
        'step' => 5,
       ];
      $tag_entities = ['other', 'activity'];
      if (isset($sets['case'])) {
        $tag_entities[] = 'case';
        $fields['case_case_type_id'] = [
          'name' => t('Case # Type'),
          'type' => 'select',
          'expose_list' => TRUE,
        ];
        $fields['case_client_id'] = [
          'name' => t('Case # Client'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => ['required' => 1, 'multiple' => $this->utils->wf_crm_get_civi_setting('civicaseAllowMultipleClients', 0)],
          'data_type' => 'ContactReference',
          'set' => 'caseRoles',
          'value' => 1,
        ];
        $fields['case_status_id'] = [
          'name' => t('Case # Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        ];
        $fields['case_medium_id'] = [
          'name' => t('Medium'),
          'type' => 'select',
          'expose_list' => TRUE,
        ];
        $fields['case_subject'] = [
          'name' => t('Case # Subject'),
          'type' => 'textfield',
        ];
        $fields['case_creator_id'] = [
          'name' => t('Case # Creator'),
          'type' => 'select',
          'expose_list' => TRUE,
          'data_type' => 'ContactReference',
          'set' => 'caseRoles',
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        ];
        $fields['case_start_date'] = [
          'name' => t('Case # Start Date'),
          'type' => 'date',
          'default_value' => 'now',
        ];
        $fields['case_end_date'] = [
          'name' => t('Case # End Date'),
          'type' => 'date',
          'default_value' => 'now',
        ];
        $fields['case_details'] = [
          'name' => t('Case # Details'),
          'type' => 'textarea',
        ];
        // Fetch case roles
        $sets['caseRoles'] = ['entity_type' => 'case', 'label' => t('Case Roles')];
        foreach ($this->utils->wf_crm_apivalues('case_type', 'get') as $case_type) {
          foreach ($case_type['definition']['caseRoles'] as $role) {
            foreach ($this->utils->wf_crm_get_relationship_types() as $rel_type) {
              if (in_array($role['name'], [$rel_type['name_b_a'], $rel_type['label_b_a']])) {
                $case_role_fields_key = 'case_role_' . $rel_type['id'];
                if (!isset($fields[$case_role_fields_key])) {
                  $fields[$case_role_fields_key] = [
                    'name' => $rel_type['label_b_a'],
                    'type' => 'select',
                    'expose_list' => TRUE,
                    'data_type' => 'ContactReference',
                    'set' => 'caseRoles',
                    'empty_option' => t('None'),
                    'extra' => [
                      'multiple' => 1,
                    ],
                  ];
                }
                $fields['case_role_' . $rel_type['id']]['case_types'][] = $case_type['id'];
                break;
              }
            }
          }
        }
      }
      $all_tagsets = $this->utils->wf_crm_apivalues('tag', 'get', [
        'return' => ['id', 'name', 'used_for'],
        'is_tagset' => 1,
        'parent_id' => ['IS NULL' => 1],
      ]);
      foreach ($tag_entities as $entity) {
        $table_name = $entity == 'other' ? 'civicrm_contact' : "civicrm_$entity";
        $tagsets = ['' => t('Tag(s)')];
        foreach ($all_tagsets as $set) {
          if (strpos($set['used_for'], $table_name) !== FALSE) {
            $tagsets[$set['id']] = $set['name'];
          }
        }
        foreach ($tagsets as $pid => $name) {
          $fields[$entity . '_tag' . ($pid ? "_$pid" : '')] = [
            'name' => $name,
            'type' => 'select',
            'civicrm_live_options' => 1,
            'extra' => ['multiple' => 1],
            'table' => 'tag',
            'expose_list' => TRUE,
          ];
        }
      }
      $fields['relationship_relationship_type_id'] = [
        'name' => t('Relationship Type(s)'),
        'type' => 'select',
        'expose_list' => TRUE,
        'civicrm_live_options' => 1,
        'extra' => [
          'multiple' => 1,
        ],
      ];
      $fields['relationship_is_active'] = [
        'name' => t('Is Active'),
        'type' => 'select',
        'expose_list' => TRUE,
      ];
      $fields['relationship_relationship_permission'] = [
        'name' => t('Permissions'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('No Permissions'),
      ];
      $fields['relationship_start_date'] = [
        'name' => t('Start Date'),
        'type' => 'date',
        'extra' => [
          'start_date' => '-50 years',
          'end_date' => '+10 years',
        ],
      ];
      $fields['relationship_end_date'] = [
        'name' => t('End Date'),
        'type' => 'date',
        'extra' => [
          'start_date' => '-50 years',
          'end_date' => '+10 years',
        ],
      ];
      $fields['relationship_description'] = [
        'name' => t('Description'),
        'type' => 'textarea',
      ];
      if (isset($sets['contribution'])) {
        $fields['contribution_enable_contribution'] = [
          'name' => ts('Enable Contribution?'),
          'type' => 'hidden',
          'expose_list' => TRUE,
          'empty_option' => 'None',
          'extra' => [
            'hidden_type' => 'hidden',
          ],
          'parent' => 'contribution_pagebreak',
        ];
        // @todo moved in order since we can't pass `weight`.
        $fields['contribution_total_amount'] = [
            'name' => 'Contribution Amount',
            'parent' => 'contribution_pagebreak',
          ] + $moneyDefaults;
        // @todo moved in order since we can't pass `weight`.
        $fields['contribution_payment_processor_id'] = [
          'name' => 'Payment Processor',
          'type' => 'select',
          'expose_list' => TRUE,
          'civicrm_live_options' => TRUE,
          'extra' => [
            'aslist' => 0,
            'required' => TRUE
          ],
          'exposed_empty_option' => 'Pay Later',
          // Removed due to error, when a custom element is made, revisit.
          // 'value_callback' => TRUE,
        ];
        $fields['contribution_is_test'] = [
          'name' => t('Payment Processor Mode'),
          'type' => 'hidden',
          'expose_list' => TRUE,
          'value' => 0,
          'weight' => 9996,
        ];
        $fields['contribution_note'] = [
          'name' => t('Contribution Note'),
          'type' => 'textarea',
          'parent' => 'contribution_pagebreak',
        ];
        $fields['contribution_soft'] = [
          'name' => t('Soft Credit To'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => ['multiple' => TRUE],
          'data_type' => 'ContactReference',
          'parent' => 'contribution_pagebreak',
        ];
        $fields['contribution_honor_contact_id'] = [
          'name' => t('In Honor/Memory of'),
          'type' => 'select',
          'expose_list' => TRUE,
          'empty_option' => t('No One'),
          'data_type' => 'ContactReference',
          'parent' => 'contribution_pagebreak',
        ];
        $fields['contribution_honor_type_id'] = [
          'name' => t('Honoree Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'parent' => 'contribution_pagebreak',
        ];
        $fields['contribution_source'] = [
          'name' => t('Contribution Source'),
          'type' => 'textfield',
          'parent' => 'contribution_pagebreak',
        ];
        $donationFinancialType = current($this->utils->wf_crm_apivalues('FinancialType', 'get', [
          'return' => 'id',
          'name' => 'Donation',
        ], 'id')) ?? NULL;
        $fields['contribution_financial_type_id'] = [
          'name' => t('Financial Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'civicrm_live_options' => TRUE,
          'default_value' => $donationFinancialType,
          'parent' => 'contribution_pagebreak',
          'extra' => ['required' => 1],
        ];
        // Line items
        $fields['contribution_line_total'] = [
            'name' => t('Line Item Amount'),
            'set' => 'line_items',
            'parent' => 'contribution_pagebreak',
          ] + $moneyDefaults;
        $fields['lineitem_financial_type_id'] = [
          'name' => t('Financial Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'civicrm_live_options' => TRUE,
          'default_value' => $donationFinancialType,
          'parent' => 'contribution_pagebreak',
          'set' => 'line_items',
          'fid' => 'contribution_financial_type_id',
        ];
        $sets['contributionRecur'] = ['entity_type' => 'contribution', 'label' => t('Recurring Contribution')];
        $fields['contribution_frequency_unit'] = [
          'name' => t('Frequency of Installments'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('No Installments') . ' -',
          'set' => 'contributionRecur',
        ];
        $fields['contribution_installments'] = [
          'name' => t('Number of Installments'),
          'type' => 'civicrm_number',
          'default_value' => '1',
          'min' => '0',
          'step' => '1',
          'set' => 'contributionRecur',
        ];
        $fields['contribution_frequency_interval'] = [
          'name' => t('Interval of Installments'),
          'type' => 'civicrm_number',
          'default_value' => '1',
          'min' => '0',
          'step' => '1',
          'set' => 'contributionRecur',
        ];
        $sets['billing_1_number_of_billing'] = ['entity_type' => 'contribution', 'label' => t('Billing Address')];
        $billingFields = [
          'first_name' => t('Billing First Name'),
          'middle_name' => t('Billing Middle Name'),
          'last_name' => t('Billing Last Name'),
          'street_address' => t('Street Address'),
          'postal_code' => t('Postal Code'),
          'city' => t('City'),
        ];
        foreach ($billingFields as $key => $label) {
          $width = 60;
          if ($key == 'city') {
            $width = 20;
          }
          if ($key == 'postal_code') {
            $width = 7;
          }
          $fields["contribution_billing_address_{$key}"] = [
            'name' => $label,
            'type' => 'textfield',
            'extra' => ['width' => $width],
            'set' => 'billing_1_number_of_billing',
            'parent' => 'contribution_pagebreak',
          ];
        }
        $fields['contribution_billing_address_country_id'] = [
          'name' => t('Country'),
          'type' => 'select',
          'civicrm_live_options' => 1,
          'extra' => ['aslist' => 1],
          'default_value' => $this->utils->wf_crm_get_civi_setting('defaultContactCountry', 1228),
          'set' => 'billing_1_number_of_billing',
          'parent' => 'contribution_pagebreak',
        ];
        $fields['contribution_billing_address_state_province_id'] = [
          'name' => t('State/Province'),
          'type' => 'textfield',
          'extra' => [
            'maxlength' => 5,
            'width' => 4,
          ],
          'data_type' => 'state_province_abbr',
          'set' => 'billing_1_number_of_billing',
          'parent' => 'contribution_pagebreak',
        ];
      }
      if (isset($sets['participant'])) {
        $fields['participant_event_id'] = [
          'name' => t('Event(s)'),
          'type' => 'select',
          'civicrm_live_options' => TRUE,
          'extra' => ['multiple' => 1],
          'expose_list' => TRUE,
        ];
        $fields['participant_role_id'] = [
          'name' => t('Participant Role'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => '1',
          'extra' => ['multiple' => 1, 'required' => 1],
        ];
        $fields['participant_status_id'] = [
          'name' => t('Registration Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        ];
        $fields['participant_note'] = [
          'name' => t('Participant Notes'),
          'type' => 'textarea',
        ];
        if (isset($sets['contribution'])) {
          $fields['participant_fee_amount'] = [
              'name' => t('Participant Fee'),
            ] + $moneyDefaults;
        }
      }
      if (isset($sets['membership'])) {
        $fields['membership_membership_type_id'] = [
          'name' => t('Membership Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'civicrm_live_options' => 1,
        ];
        $fields['membership_financial_type_id'] = [
          'name' => t('Membership Financial Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        ];
        $fields['membership_status_id'] = [
          'name' => t('Override Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('No') . ' -',
        ];
        $fields['membership_status_override_end_date'] = [
          'name' => t('Status Override Until Date'),
          'type' => 'date',
          'civicrm_condition' => [
            'andor' => 'or',
            'action' => 'show',
            'rules' => [
              'membership_status_id' => [
                'values' => '0',
                'operator' => 'not_equal',
              ],
            ],
          ],
        ];
        $fields['membership_num_terms'] = [
          'name' => t('Number of Terms'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 1,
          'empty_option' => t('Enter Dates Manually'),
        ];
        if (isset($sets['contribution'])) {
          $fields['membership_fee_amount'] = [
              'name' => t('Membership Fee'),
            ] + $moneyDefaults;
        }
        $fields['membership_join_date'] = [
          'name' => t('Member Since'),
          'type' => 'date',
        ];
        $fields['membership_start_date'] = [
          'name' => t('Start Date'),
          'type' => 'date',
        ];
        $fields['membership_end_date'] = [
          'name' => t('End Date'),
          'type' => 'date',
        ];
      }
      // Add campaign fields
      if (in_array('CiviCampaign', $components)) {
        $fields['activity_engagement_level'] = [
          'name' => t('Engagement Level'),
          'type' => 'select',
          'empty_option' => t('None'),
          'expose_list' => TRUE,
        ];
        $fields['activity_survey_id'] = [
          'name' => t('Survey/Petition'),
          'type' => 'select',
          'expose_list' => TRUE,
          'empty_option' => t('None'),
          'civicrm_live_options' => TRUE,
        ];
        foreach (array_intersect(['activity', 'membership', 'participant', 'contribution'], array_keys($sets)) as $ent) {
          $fields[$ent . '_campaign_id'] = [
            'name' => t('Campaign'),
            'type' => 'select',
            'expose_list' => TRUE,
            'civicrm_live_options' => TRUE,
            'empty_option' => t('None'),
          ];
        }
      }
      // CiviGrant fields
      if (isset($sets['grant'])) {
        $fields['grant_contact_id'] = [
          'name' => t('Grant Applicant'),
          'type' => 'select',
          'expose_list' => TRUE,
          'data_type' => 'ContactReference',
        ];
        $fields['grant_grant_type_id'] = [
          'name' => t('Grant Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'civicrm_live_options' => TRUE,
        ];
        $fields['grant_status_id'] = [
          'name' => t('Grant Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        ];
        $fields['grant_application_received_date'] = [
          'name' => t('Application Received Date'),
          'type' => 'date',
        ];
        $fields['grant_decision_date'] = [
          'name' => t('Decision Date'),
          'type' => 'date',
        ];
        $fields['grant_money_transfer_date'] = [
          'name' => t('Money Transfer Date'),
          'type' => 'date',
        ];
        $fields['grant_grant_due_date'] = [
          'name' => t('Grant Report Due'),
          'type' => 'date',
        ];
        $fields['grant_grant_report_received'] = [
          'name' => t('Grant Report Received?'),
          'type' => 'select',
          'extra' => ['aslist' => 0],
        ];
        $fields['grant_rationale'] = [
          'name' => t('Grant Rationale'),
          'type' => 'textarea',
        ];
        $fields['grant_note'] = [
          'name' => t('Grant Notes'),
          'type' => 'textarea',
        ];
        $fields['grant_amount_total'] = [
            'name' => t('Amount Requested'),
            'attributes' => [
              'required' => 1,
            ],
          ] + $moneyDefaults;
        $fields['grant_amount_granted'] = [
            'name' => t('Amount Granted'),
          ] + $moneyDefaults;
      }

      // File attachment fields
      $numAttachments = $this->utils->wf_crm_get_civi_setting('max_attachments', 3);
      foreach ($sets as $ent => $set) {
        if (!empty($set['attachments']) && $numAttachments) {
          $sets["{$ent}upload"] = [
            'label' => t('File Attachments'),
            'entity_type' => $ent,
          ];
          for ($i = 1; $i <= $numAttachments; $i++) {
            $fields["{$ent}upload_file_$i"] = [
              'name' => t('Attachment :num', [':num' => $i]),
              'type' => 'file',
              'data_type' => 'File',
            ];
          }
        }
      }
      // Add any elements we want from CiviCRM getfields metadata.
      $fields = $this->addFieldMetadata($fields);

      // Fetch custom groups
      list($contact_types) = $this->utils->wf_crm_get_contact_types();
      $custom_sets = [];
      $custom_groups = $this->utils->wf_crm_apivalues('CustomGroup', 'get', [
        'return' => ['title', 'extends', 'extends_entity_column_value', 'extends_entity_column_id', 'is_multiple', 'max_multiple', 'help_pre'],
        'is_active' => 1,
        'extends' => ['IN' => array_keys($contact_types + $sets)],
        'options' => ['sort' => 'weight'],
      ]);
      foreach ($custom_groups as $custom_group) {
        $set = 'cg' . $custom_group['id'];
        $entity_type = strtolower($custom_group['extends']);
        // Place these custom fields directly into their entity
        if (wf_crm_aval($sets, "$entity_type:custom_fields") == 'combined') {
          $set = $entity_type;
        }
        else {
          $sets[$set] = [
            'label' => $custom_group['title'],
            'entity_type' => $entity_type,
            'max_instances' => 1,
          ];
          if (isset($contact_types[$entity_type]) || $entity_type == 'contact') {
            $sets[$set]['entity_type'] = 'contact';
            if ($entity_type != 'contact') {
              $sets[$set]['contact_type'] = $entity_type;
            }
            if (!empty($custom_group['is_multiple'])) {
              $sets[$set]['max_instances'] = $custom_group['max_multiple'] ?? 9;
            }
          }
          if (!empty($custom_group['extends_entity_column_value'])) {
            $sets[$set]['sub_types'] = $custom_group['extends_entity_column_value'];
          }
          if (!empty($custom_group['extends_entity_column_id'])) {
            $sets[$set]['extension_of'] = $custom_group['extends_entity_column_id'];
          }
          $sets[$set]['help_text'] = $custom_group['help_pre'] ?? NULL;
        }
        $custom_sets[$custom_group['id']] = $set;
      }

      // Fetch custom fields
      $custom_types = $this->utils->wf_crm_custom_types_map_array();
      $custom_fields = [];
      if (count($custom_sets) > 0) {
        $custom_fields = $this->utils->wf_crm_apivalues('CustomField', 'get', [
          'is_active' => 1,
          'custom_group_id' => ['IN' => array_keys($custom_sets)],
          'html_type' => ['IN' => array_keys($custom_types)],
          'options' => ['sort' => 'weight'],
        ]);
      }
      foreach ($custom_fields as $custom_field) {
        $set = $custom_sets[$custom_field['custom_group_id']];
        $custom_group = $custom_groups[$custom_field['custom_group_id']];
        $id = $set . '_custom_' . $custom_field['id'];
        $fields[$id] = $custom_types[$custom_field['html_type']];
        if ($custom_field['html_type'] == 'Text' && $custom_field['data_type'] == 'Money') {
          $fields[$id] = $moneyDefaults;
        }
        $fields[$id]['name'] = $custom_field['label'];
        $fields[$id]['required'] = (int) !empty($custom_field['is_required']);
        if (!empty($custom_field['default_value'])) {
          $fields[$id]['value'] = implode(',', $this->utils->wf_crm_explode_multivalue_str($custom_field['default_value']));
        }
        $fields[$id]['data_type'] = $custom_field['data_type'];
        if (!empty($custom_field['help_pre']) || !empty($custom_field['help_post'])) {
          $fields[$id]['extra']['description'] = !empty($custom_field['help_pre']) ? $custom_field['help_pre'] : $custom_field['help_post'];
          $fields[$id]['extra']['description_above'] = (int) empty($custom_field['help_pre']);
          $fields[$id]['has_help'] = TRUE;
        }
        if (!empty($custom_field['serialize'])) {
          $fields[$id]['extra']['multiple'] = 1;
        }
        // Conditional rule - todo: support additional entities
        if ($sets[$set]['entity_type'] == 'contact' && !empty($sets[$set]['sub_types'])) {
          $fields[$id]['civicrm_condition'] = [
            'andor' => 'or',
            'action' => 'show',
            'rules' => [
              'contact_contact_sub_type' => [
                'values' => $sets[$set]['sub_types'],
              ],
            ],
          ];
        }
        if ($set == 'relationship' && !empty($custom_group['extends_entity_column_value'])) {
          $fields[$id]['attributes']['data-relationship-type'] = implode(',', $custom_group['extends_entity_column_value']);
        }

        if ($fields[$id]['type'] == 'date') {
          $fields[$id]['date_date_min'] = (!empty($custom_field['start_date_years']) ? '-' . $custom_field['start_date_years'] : '-50') . ' years';
          $fields[$id]['date_date_max'] = (!empty($custom_field['end_date_years']) ? '+' . $custom_field['end_date_years'] : '+50') . ' years';
          // Add "time" component for datetime fields
          if (!empty($custom_field['time_format'])) {
            $fields[$id]['type'] = 'datetime';
            $fields[$id]['date_time_step'] = 60;
            if ($custom_field['time_format'] == 2) {
              $fields[$id]['date_time_element'] = 'timepicker';
              $fields[$id]['date_time_placeholder'] = 'hh:mm';
              $fields[$id]['date_time_format'] = 'H:i';
            }
          }
        }
        elseif ($fields[$id]['data_type'] == 'ContactReference') {
          $fields[$id]['expose_list'] = TRUE;
          $fields[$id]['empty_option'] = t('None');
        }
        elseif ($fields[$id]['data_type'] !== 'Boolean' && $fields[$id]['type'] == 'select') {
          $fields[$id]['civicrm_live_options'] = 1;
          $fields[$id]['extra']['aslist'] = 1;
          if (in_array($custom_field['html_type'], ['Radio', 'CheckBox'])) {
            $fields[$id]['extra']['aslist'] = 0;
          }
        }
        elseif ($fields[$id]['type'] == 'textarea') {
          $fields[$id]['extra']['cols'] = $custom_field['note_columns'] ?? 60;
          $fields[$id]['extra']['rows'] = $custom_field['note_rows'] ?? 4;
        }
        // Set maximum field length.
        if (isset($custom_field['text_length']) && in_array($fields[$id]['type'], ['textfield', 'textareas'])) {
          $fields[$id]['counter_type'] = 'character';
          $fields[$id]['counter_maximum'] = $custom_field['text_length'];
          $fields[$id]['counter_maximum_message'] = ' ';
        }
      }
      // The sets are modified in this function to include the custom sets.
      $this->sets = $sets;
    }
    return $$var;
  }
}
