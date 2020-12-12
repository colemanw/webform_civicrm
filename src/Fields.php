<?php

namespace Drupal\webform_civicrm;

use CRM_Case_XMLProcessor_Process;
use CRM_Core_BAO_Tag;
use CRM_Core_Config;
use CRM_Core_DAO;

class Fields implements FieldsInterface {

  protected $components = [];
  protected $sets = [];

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
      $this->components = wf_crm_get_civi_setting('enable_components');
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
      $extra_sets = wf_crm_get_empty_sets();
      $sets += $extra_sets;
      $this->sets = $sets;
    }

    return $this->sets;
  }

  protected function getMoneyDefaults(): array {
    return [
      'type' => 'number',
      'data_type' => 'Money',
      'extra' => [
        'field_prefix' => wf_crm_get_civi_setting('defaultCurrencySymbol', '$'),
        'point' => wf_crm_get_civi_setting('monetaryDecimalPoint', '.'),
        'separator' => wf_crm_get_civi_setting('monetaryThousandSeparator', ','),
        'decimals' => 2,
        'min' => 0,
      ],
    ];
  }

  protected function wf_crm_get_fields($var = 'fields') {
    $components = $this->getComponents();
    $sets = $this->getSets($components);

    static $fields = [];

    if (!$fields) {
      $moneyDefaults = $this->getMoneyDefaults();

      // Field keys are in the format table_column
      // Use a # sign as a placeholder for field number in the title (or by default it will be appended to the end)
      // Setting 'expose_list' allows the value to be set on the config form
      // Set label for 'empty_option' for exposed lists that do not require input
      $fields['contact_contact_sub_type'] = array(
        'name' => t('Type of @contact'),
        'type' => 'select',
        'extra' => array('multiple' => 1, 'civicrm_live_options' => 1),
        'expose_list' => TRUE,
      );
      $fields['contact_existing'] = array(
        'name' => t('Existing Contact'),
        'type' => 'civicrm_contact',
        'search_prompt' => t('- Choose existing -'),
        'widget' => 'hidden',
      );
      // Organization / household names
      foreach (array('organization' => t('Organization Name'), 'legal' => t('Legal Name'), 'household' => t('Household Name')) as $key => $label) {
        $fields['contact_' . $key . '_name'] = array(
          'name' => $label,
          'type' => 'textfield',
          'contact_type' => $key == 'household' ? 'household' : 'organization',
        );
      }
      $fields['contact_sic_code'] = array(
        'name' => t('SIC Code'),
        'type' => 'textfield',
        'contact_type' => 'organization',
      );
      // Individual names
      $enabled_names = wf_crm_get_civi_setting('contact_edit_options');
      $name_options = array_column(wf_crm_apivalues('OptionValue', 'get', ['option_group_id' => 'contact_edit_options', 'return' => ['name', 'value']]), 'name', 'value');
      $enabled_names = array_intersect_key($name_options, array_flip($enabled_names));
      foreach (array('prefix_id' => t('Name Prefix'), 'formal_title' => t('Formal Title'), 'first_name' => t('First Name'), 'middle_name' => t('Middle Name'), 'last_name' => t('Last Name'), 'suffix_id' => t('Name Suffix')) as $key => $label) {
        if (in_array(ucwords(str_replace(['_id', '_'], ['', ' '], $key)),
          $enabled_names, TRUE)) {
          $fields['contact_' . $key] = array(
            'name' => $label,
            'type' => strpos($key, '_id') ? 'select' : 'textfield',
            'contact_type' => 'individual',
          );
        }
      }
      $fields['contact_nick_name'] = array(
        'name' => t('Nickname'),
        'type' => 'textfield',
      );
      $fields['contact_gender_id'] = array(
        'name' => t('Gender'),
        // Gender should be textfield if using https://civicrm.org/extensions/gender-self-identify
        'type' => function_exists('genderselfidentify_civicrm_apiWrappers') ? 'textfield' : 'select',
        'contact_type' => 'individual',
      );
      $fields['contact_job_title'] = array(
        'name' => t('Job Title'),
        'type' => 'textfield',
        'contact_type' => 'individual',
      );
      $fields['contact_birth_date'] = array(
        'name' => t('Birth Date'),
        'type' => 'date',
        'extra' => array(
          'start_date' => '-100 years',
          'end_date' => 'now',
        ),
        'contact_type' => 'individual',
      );
      $fields['contact_preferred_communication_method'] = array(
        'name' => t('Preferred Communication Method(s)'),
        'type' => 'select',
        'extra' => array('multiple' => 1),
      );
      $fields['contact_privacy'] = array(
        'name' => t('Privacy Preferences'),
        'type' => 'select',
        'extra' => array('multiple' => 1),
      );
      $fields['contact_preferred_language'] = array(
        'name' => t('Preferred Language'),
        'type' => 'select',
        'value' => wf_crm_get_civi_setting('lcMessages', 'en_US'),
      );
      /*
       * @todo is this fine w/ the core file element?
      if (array_key_exists('file', webform_components())) {
        $fields['contact_image_URL'] = array(
          'name' => t('Upload Image'),
          'type' => 'file',
          'extra' => array('width' => 40),
          'data_type' => 'File',
        );
      }
      */
      $fields['contact_contact_id'] = array(
        'name' => t('Contact ID'),
        'type' => 'hidden',
      );
      $fields['contact_user_id'] = array(
        'name' => t('User ID'),
        'type' => 'hidden',
      );
      $fields['contact_external_identifier'] = array(
        'name' => t('External ID'),
        'type' => 'hidden',
      );
      $fields['contact_source'] = array(
        'name' => t('Source'),
        'type' => 'textfield',
      );
      $fields['contact_cs'] = array(
        'name' => t('Checksum'),
        'type' => 'hidden',
        'value_callback' => TRUE,
      );
      $fields['contact_employer_id'] = array(
        'name' => t('Current Employer'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('None'),
        'data_type' => 'ContactReference',
        'contact_type' => 'individual',
        'reference_contact_type' => 'organization'
      );
      $fields['contact_is_deceased'] = array(
        'name' => t('Is Deceased'),
        'type' => 'select',
        'extra' => array('aslist' => 0),
        'contact_type' => 'individual',
      );
      $fields['contact_deceased_date'] = array(
        'name' => t('Deceased Date'),
        'type' => 'date',
        'extra' => array(
          'start_date' => '-100 years',
          'end_date' => 'now',
        ),
        'contact_type' => 'individual',
      );
      $fields['email_email'] = array(
        'name' => t('Email'),
        'type' => 'email',
      );
      $addressOptions = array(
        'street_address' => t('Street Address'),
        'street_name' => t('Street Name'),
        'street_number' => t('Street Number'),
        'street_unit' => t('Street Number Suffix'),
        'name' => t('Address Name'),
        'supplemental_address_1' => t('Street Address # Line 2'),
        'supplemental_address_2' => t('Street Address # Line 3'),
        'supplemental_address_3' => t('Street Address # Line 4'),
        'city' => t('City'),
      );
      foreach ($addressOptions as $key => $value) {
        $fields['address_' . $key] = array(
          'name' => $value,
          'type' => 'textfield',
          'extra' => array('width' => $key === 'city' ? 20 : 60),
        );
      }
      $fields['address_postal_code'] = array(
        'name' => t('Postal Code'),
        'type' => 'textfield',
        'extra' => array('width' => 7),
      );
      $fields['address_postal_code_suffix'] = array(
        'name' => t('Postal Code Suffix'),
        'type' => 'textfield',
        'extra' => array(
          'width' => 5,
          'description' => t('+4 digits of Zip Code'),
        ),
      );
      $fields['address_country_id'] = array(
        'name' => t('Country'),
        'type' => 'select',
        'extra' => array('civicrm_live_options' => 1),
        'value' => wf_crm_get_civi_setting('defaultContactCountry', 1228),
      );
      $fields['address_state_province_id'] = array(
        'name' => t('State/Province'),
        'type' => 'textfield',
        'extra' => array(
          'maxlength' => 5,
          'width' => 4,
        ),
        'data_type' => 'state_province_abbr',
      );
      $fields['address_county_id'] = array(
        'name' => t('District/County'),
        'type' => 'textfield',
      );
      $fields['address_master_id'] = array(
        'name' => t('Share address of'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => array('aslist' => 0),
        'empty_option' => t('Do Not Share'),
      );
      $fields['phone_phone'] = array(
        'name' => t('Phone Number'),
        'type' => 'textfield',
      );
      $fields['phone_phone_ext'] = array(
        'name' => t('Phone Extension'),
        'type' => 'textfield',
        'extra' => array(
          'width' => 4,
        ),
      );
      $fields['phone_phone_type_id'] = array(
        'name' => t('Phone # Type'),
        'type' => 'select',
        'table' => 'phone',
        'expose_list' => TRUE,
      );
      $fields['im_name'] = array(
        'name' => t('Screen Name'),
        'type' => 'textfield',
      );
      $fields['im_provider_id'] = array(
        'name' => t('IM Provider'),
        'type' => 'select',
        'expose_list' => TRUE,
      );
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
      foreach (array('address' => t('Address # Location'), 'phone' => t('Phone # Location'), 'email' => t('Email # Location'), 'im' => t('IM # Location')) as $key => $label) {
        if (isset($sets[$key])) {
          $fields[$key . '_location_type_id'] = array(
            'name' => $label,
            'type' => 'select',
            'expose_list' => TRUE,
            'value' => '1',
          );
        }
      }
      $fields['website_url'] = array(
        'name' => t('Website'),
        'type' => 'textfield',
        'data_type' => 'Link',
      );
      $fields['website_website_type_id'] = array(
        'name' => t('Website # Type'),
        'type' => 'select',
        'expose_list' => TRUE,
      );
      $fields['other_group'] = array(
        'name' => t('Group(s)'),
        'type' => 'select',
        'extra' => array('multiple' => 1, 'civicrm_live_options' => 1),
        'table' => 'group',
        'expose_list' => TRUE,
      );
      $fields['activity_activity_type_id'] = array(
        'name' => t('Activity # Type'),
        'type' => 'select',
        'expose_list' => TRUE,
      );
      $fields['activity_target_contact_id'] = array(
        'name' => t('Activity # Participant(s)'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => array('multiple' => 1),
        'data_type' => 'ContactReference',
      );
      $fields['activity_source_contact_id'] = array(
        'name' => t('Activity # Creator'),
        'type' => 'select',
        'expose_list' => TRUE,
        'data_type' => 'ContactReference',
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      );
      $fields['activity_subject'] = array(
        'name' => t('Activity # Subject'),
        'type' => 'textfield',
      );
      $fields['activity_details'] = array(
        'name' => t('Activity # Details'),
        'type' => \Drupal::moduleHandler()->moduleExists('webform_html_textarea') ? 'html_textarea' : 'textarea',
      );
      $fields['activity_status_id'] = array(
        'name' => t('Activity # Status'),
        'type' => 'select',
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      );
      $fields['activity_priority_id'] = array(
        'name' => t('Activity # Priority'),
        'type' => 'select',
        'expose_list' => TRUE,
        'exposed_empty_option' => '- ' . t('Automatic') . ' -',
      );
      $fields['activity_assignee_contact_id'] = [
        'name' => t('Assign Activity # to'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('No One'),
        'extra' => ['multiple' => 1],
        'data_type' => 'ContactReference',
      ];
      $fields['activity_location'] = array(
        'name' => t('Activity # Location'),
        'type' => 'textfield',
      );
      $fields['activity_activity_date_time'] = array(
        'name' => t('Activity # Date'),
        'type' => 'date',
        'default_value' => 'now',
      );
      $fields['activity_activity_date_time_timepart'] = array(
        'name' => t('Activity # Time'),
        'type' => 'time',
        'default_value' => 'now',
      );
      $fields['activity_duration'] = array(
        'name' => t('Activity # Duration'),
        'type' => 'number',
        'field_suffix' =>  t('min.'),
        /*ToDo Figure out why setting min does not work!*/
        'min' => 0,
        'step' => 5,
       );
      $tag_entities = array('other', 'activity');
      if (isset($sets['case'])) {
        $tag_entities[] = 'case';
        $fields['case_case_type_id'] = array(
          'name' => t('Case # Type'),
          'type' => 'select',
          'expose_list' => TRUE,
        );
        $fields['case_client_id'] = array(
          'name' => t('Case # Client'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => array('required' => 1, 'multiple' => wf_crm_get_civi_setting('civicaseAllowMultipleClients', 0)),
          'data_type' => 'ContactReference',
          'set' => 'caseRoles',
          'value' => 1,
        );
        $fields['case_status_id'] = array(
          'name' => t('Case # Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        );
        $fields['case_medium_id'] = array(
          'name' => t('Medium'),
          'type' => 'select',
          'expose_list' => TRUE,
        );
        $fields['case_subject'] = array(
          'name' => t('Case # Subject'),
          'type' => 'textfield',
        );
        $fields['case_creator_id'] = array(
          'name' => t('Case # Creator'),
          'type' => 'select',
          'expose_list' => TRUE,
          'data_type' => 'ContactReference',
          'set' => 'caseRoles',
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        );
        $fields['case_start_date'] = array(
          'name' => t('Case # Start Date'),
          'type' => 'date',
          'value' => 'now',
        );
        $fields['case_end_date'] = array(
          'name' => t('Case # End Date'),
          'type' => 'date',
          'value' => 'now',
        );
        $fields['case_details'] = array(
          'name' => t('Case # Details'),
          'type' => 'textarea',
        );
        // Fetch case roles
        $sets['caseRoles'] = array('entity_type' => 'case', 'label' => t('Case Roles'));
        foreach (wf_crm_apivalues('case_type', 'get') as $case_type) {
          foreach ($case_type['definition']['caseRoles'] as $role) {
            foreach (wf_crm_get_relationship_types() as $rel_type) {
              if (in_array($role['name'], [$rel_type['name_b_a'], $rel_type['label_b_a']])) {
                $case_role_fields_key = 'case_role_' . $rel_type['id'];
                if (!isset($fields[$case_role_fields_key])) {
                  $fields[$case_role_fields_key] = array(
                    'name' => $rel_type['label_b_a'],
                    'type' => 'select',
                    'expose_list' => TRUE,
                    'data_type' => 'ContactReference',
                    'set' => 'caseRoles',
                    'empty_option' => t('None'),
                  );
                }
                $fields['case_role_' . $rel_type['id']]['case_types'][] = $case_type['id'];
                break;
              }
            }
          }
        }
      }
      $all_tagsets = wf_crm_apivalues('tag', 'get', [
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
            'extra' => ['multiple' => 1, 'civicrm_live_options' => 1],
            'table' => 'tag',
            'expose_list' => TRUE,
          ];
        }
      }
      $fields['relationship_relationship_type_id'] = array(
        'name' => t('Relationship Type(s)'),
        'type' => 'select',
        'expose_list' => TRUE,
        'extra' => array(
          'civicrm_live_options' => 1,
          'multiple' => 1,
        ),
      );
      $fields['relationship_is_active'] = array(
        'name' => t('Is Active'),
        'type' => 'select',
        'expose_list' => TRUE,
        'value' => '1',
      );
      $fields['relationship_relationship_permission'] = array(
        'name' => t('Permissions'),
        'type' => 'select',
        'expose_list' => TRUE,
        'empty_option' => t('No Permissions'),
      );
      $fields['relationship_start_date'] = array(
        'name' => t('Start Date'),
        'type' => 'date',
        'extra' => array(
          'start_date' => '-50 years',
          'end_date' => '+10 years',
        ),
      );
      $fields['relationship_end_date'] = array(
        'name' => t('End Date'),
        'type' => 'date',
        'extra' => array(
          'start_date' => '-50 years',
          'end_date' => '+10 years',
        ),
      );
      $fields['relationship_description'] = array(
        'name' => t('Description'),
        'type' => 'textarea',
      );
      if (isset($sets['contribution'])) {
        // @todo moved in order since we can't pass `weight`.
        $fields['contribution_total_amount'] = array(
            'name' => 'Contribution Amount',
            'parent' => 'contribution_pagebreak',
          ) + $moneyDefaults;
        // @todo moved in order since we can't pass `weight`.
        $fields['contribution_payment_processor_id'] = array(
          'name' => 'Payment Processor',
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => [
            'aslist' => 0,
            'civicrm_live_options' => TRUE,
            'required' => TRUE
          ],
          'exposed_empty_option' => 'Pay Later',
          // Removed due to error, when a custom element is made, revisit.
          // 'value_callback' => TRUE,
        );
        $fields['contribution_is_test'] = [
          'name' => t('Payment Processor Mode'),
          'type' => 'hidden',
          'expose_list' => TRUE,
          'value' => 0,
          'weight' => 9996,
        ];
        $fields['contribution_enable_contribution'] = array(
          'name' => ts('Enable Contribution?'),
          'type' => 'hidden',
          'expose_list' => TRUE,
          'empty_option' => 'None',
          'extra' => array(
            'hidden_type' => 'hidden',
          ),
          'parent' => 'contribution_pagebreak',
        );
        $fields['contribution_note'] = array(
          'name' => t('Contribution Note'),
          'type' => 'textarea',
          'parent' => 'contribution_pagebreak',
        );
        $fields['contribution_soft'] = array(
          'name' => t('Soft Credit To'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => array('multiple' => TRUE),
          'data_type' => 'ContactReference',
          'parent' => 'contribution_pagebreak',
        );
        $fields['contribution_honor_contact_id'] = array(
          'name' => t('In Honor/Memory of'),
          'type' => 'select',
          'expose_list' => TRUE,
          'empty_option' => t('No One'),
          'data_type' => 'ContactReference',
          'parent' => 'contribution_pagebreak',
        );
        $fields['contribution_honor_type_id'] = array(
          'name' => t('Honoree Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'parent' => 'contribution_pagebreak',
        );
        $fields['contribution_source'] = array(
          'name' => t('Contribution Source'),
          'type' => 'textfield',
          'parent' => 'contribution_pagebreak',
        );
        // Line items
        $fields['contribution_line_total'] = array(
            'name' => t('Line Item Amount'),
            'set' => 'line_items',
            'parent' => 'contribution_pagebreak',
          ) + $moneyDefaults;
        $fields['contribution_financial_type_id'] = array(
          'name' => t('Financial Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => array('civicrm_live_options' => 1),
          'value' => 1,
          'default' => 1,
          'set' => 'line_items',
          'parent' => 'contribution_pagebreak',
        );
        $sets['contributionRecur'] = array('entity_type' => 'contribution', 'label' => t('Recurring Contribution'));
        $fields['contribution_frequency_unit'] = array(
          'name' => t('Frequency of Installments'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('No Installments') . ' -',
          'set' => 'contributionRecur',
        );
        $fields['contribution_installments'] = array(
          'name' => t('Number of Installments'),
          'type' => 'number',
          'default_value' => '1',
          'min' => '0',
          'step' => '1',
          'set' => 'contributionRecur',
        );
        $fields['contribution_frequency_interval'] = array(
          'name' => t('Interval of Installments'),
          'type' => 'number',
          'default_value' => '1',
          'min' => '0',
          'step' => '1',
          'set' => 'contributionRecur',
        );
      }
      if (isset($sets['participant'])) {
        $fields['participant_event_id'] = array(
          'name' => t('Event(s)'),
          'type' => 'select',
          'extra' => array('multiple' => 1, 'civicrm_live_options' => 1),
          'expose_list' => TRUE,
        );
        $fields['participant_role_id'] = array(
          'name' => t('Participant Role'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => '1',
          'extra' => array('multiple' => 1, 'required' => 1),
        );
        $fields['participant_status_id'] = array(
          'name' => t('Registration Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        );
        $fields['participant_note'] = [
          'name' => t('Participant Notes'),
          'type' => 'textarea',
        ];
        if (isset($sets['contribution'])) {
          $fields['participant_fee_amount'] = array(
              'name' => t('Participant Fee'),
            ) + $moneyDefaults;
        }
      }
      if (isset($sets['membership'])) {
        $fields['membership_membership_type_id'] = array(
          'name' => t('Membership Type'),
          'type' => 'civicrm_select',
          'expose_list' => TRUE,
          'civicrm_live_options' => 1,
        );
        $fields['membership_financial_type_id'] = array(
          'name' => t('Membership Financial Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        );
        $fields['membership_status_id'] = array(
          'name' => t('Override Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('No') . ' -',
        );
        $fields['membership_status_override_end_date'] = array(
          'name' => t('Status Override Until Date'),
          'type' => 'date',
          'civicrm_condition' => array(
            'andor' => 'or',
            'action' => 'show',
            'rules' => array(
              'membership_status_id' => array(
                'values' => '0',
                'operator' => 'not_equal',
              ),
            ),
          ),
        );
        $fields['membership_num_terms'] = array(
          'name' => t('Number of Terms'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 1,
          'empty_option' => t('Enter Dates Manually'),
        );
        if (isset($sets['contribution'])) {
          $fields['membership_fee_amount'] = array(
              'name' => t('Membership Fee'),
            ) + $moneyDefaults;
        }
        $fields['membership_join_date'] = array(
          'name' => t('Member Since'),
          'type' => 'date',
        );
        $fields['membership_start_date'] = array(
          'name' => t('Start Date'),
          'type' => 'date',
        );
        $fields['membership_end_date'] = array(
          'name' => t('End Date'),
          'type' => 'date',
        );
      }
      // Add campaign fields
      if (in_array('CiviCampaign', $components)) {
        $fields['activity_engagement_level'] = array(
          'name' => t('Engagement Level'),
          'type' => 'select',
          'empty_option' => t('None'),
          'expose_list' => TRUE,
        );
        $fields['activity_survey_id'] = array(
          'name' => t('Survey/Petition'),
          'type' => 'select',
          'expose_list' => TRUE,
          'empty_option' => t('None'),
          'extra' => array('civicrm_live_options' => 1),
        );
        foreach (array_intersect(array('activity', 'membership', 'participant', 'contribution'), array_keys($sets)) as $ent) {
          $fields[$ent . '_campaign_id'] = array(
            'name' => t('Campaign'),
            'type' => 'select',
            'expose_list' => TRUE,
            'extra' => array('civicrm_live_options' => 1),
            'empty_option' => t('None'),
          );
        }
      }
      // CiviGrant fields
      if (isset($sets['grant'])) {
        $fields['grant_contact_id'] = array(
          'name' => t('Grant Applicant'),
          'type' => 'select',
          'expose_list' => TRUE,
          'data_type' => 'ContactReference',
        );
        $fields['grant_grant_type_id'] = array(
          'name' => t('Grant Type'),
          'type' => 'select',
          'expose_list' => TRUE,
          'extra' => array('civicrm_live_options' => 1),
        );
        $fields['grant_status_id'] = array(
          'name' => t('Grant Status'),
          'type' => 'select',
          'expose_list' => TRUE,
          'value' => 0,
          'exposed_empty_option' => '- ' . t('Automatic') . ' -',
        );
        $fields['grant_application_received_date'] = array(
          'name' => t('Application Received Date'),
          'type' => 'date',
        );
        $fields['grant_decision_date'] = array(
          'name' => t('Decision Date'),
          'type' => 'date',
        );
        $fields['grant_money_transfer_date'] = array(
          'name' => t('Money Transfer Date'),
          'type' => 'date',
        );
        $fields['grant_grant_due_date'] = array(
          'name' => t('Grant Report Due'),
          'type' => 'date',
        );
        $fields['grant_grant_report_received'] = array(
          'name' => t('Grant Report Received?'),
          'type' => 'select',
          'extra' => array('aslist' => 0),
        );
        $fields['grant_rationale'] = array(
          'name' => t('Grant Rationale'),
          'type' => 'textarea',
        );
        $fields['grant_note'] = array(
          'name' => t('Grant Notes'),
          'type' => 'textarea',
        );
        $fields['grant_amount_total'] = array(
            'name' => t('Amount Requested'),
          ) + $moneyDefaults;
        $fields['grant_amount_granted'] = array(
            'name' => t('Amount Granted'),
          ) + $moneyDefaults;
      }

      // File attachment fields
      $numAttachments = wf_crm_get_civi_setting('max_attachments', 3);
      foreach ($sets as $ent => $set) {
        if (!empty($set['attachments']) && $numAttachments) {
          $sets["{$ent}upload"] = array(
            'label' => t('File Attachments'),
            'entity_type' => $ent,
          );
          for ($i = 1; $i <= $numAttachments; $i++) {
            $fields["{$ent}upload_file_$i"] = array(
              'name' => t('Attachment :num', array(':num' => $i)),
              'type' => 'file',
              'data_type' => 'File',
            );
          }
        }
      }

      // Fetch custom groups
      list($contact_types) = wf_crm_get_contact_types();
      $custom_sets = [];
      $custom_groups = wf_crm_apivalues('CustomGroup', 'get', array(
        'return' => array('title', 'extends', 'extends_entity_column_value', 'extends_entity_column_id', 'is_multiple', 'max_multiple', 'help_pre'),
        'is_active' => 1,
        'extends' => array('IN' => array_keys($contact_types + $sets)),
        'options' => array('sort' => 'weight'),
      ));
      foreach ($custom_groups as $custom_group) {
        $set = 'cg' . $custom_group['id'];
        $entity_type = strtolower($custom_group['extends']);
        // Place these custom fields directly into their entity
        if (wf_crm_aval($sets, "$entity_type:custom_fields") == 'combined') {
          $set = $entity_type;
        }
        else {
          $sets[$set] = array(
            'label' => $custom_group['title'],
            'entity_type' => $entity_type,
            'max_instances' => 1,
          );
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
      $custom_types = wf_crm_custom_types_map_array();
      $custom_fields = wf_crm_apivalues('CustomField', 'get', array(
        'is_active' => 1,
        'custom_group_id' => array('IN' => array_keys($custom_sets)),
        'html_type' => array('IN' => array_keys($custom_types)),
        'options' => array('sort' => 'weight'),
      ));
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
          $fields[$id]['value'] = implode(',', wf_crm_explode_multivalue_str($custom_field['default_value']));
        }
        $fields[$id]['data_type'] = $custom_field['data_type'];
        if (!empty($custom_field['help_pre']) || !empty($custom_field['help_post'])) {
          $fields[$id]['extra']['description'] = !empty($custom_field['help_pre']) ? $custom_field['help_pre'] : $custom_field['help_post'];
          $fields[$id]['extra']['description_above'] = (int) empty($custom_field['help_pre']);
          $fields[$id]['has_help'] = TRUE;
        }
        // Conditional rule - todo: support additional entities
        if ($sets[$set]['entity_type'] == 'contact' && !empty($sets[$set]['sub_types'])) {
          $fields[$id]['civicrm_condition'] = array(
            'andor' => 'or',
            'action' => 'show',
            'rules' => array(
              'contact_contact_sub_type' => array(
                'values' => $sets[$set]['sub_types'],
              ),
            ),
          );
        }
        if ($set == 'relationship' && !empty($custom_group['extends_entity_column_value'])) {
          $fields[$id]['attributes']['data-relationship-type'] = implode(',', $custom_group['extends_entity_column_value']);
        }

        if ($fields[$id]['type'] == 'date') {
          $fields[$id]['extra']['start_date'] = (!empty($custom_field['start_date_years']) ? '-' . $custom_field['start_date_years'] : '-50') . ' years';
          $fields[$id]['extra']['end_date'] = (!empty($custom_field['end_date_years']) ? '+' . $custom_field['end_date_years'] : '+50') . ' years';
          // Add "time" component for datetime fields
          if (!empty($custom_field['time_format'])) {
            $fields[$id]['name'] .= ' - ' . t('date');
            $fields[$id . '_timepart'] = array(
              'name' => $custom_field['label'] . ' - ' . t('time'),
              'type' => 'time',
              'extra' => array('hourformat' => $custom_field['time_format'] == 1 ? '12-hour' : '24-hour'),
            );
          }
        }
        elseif ($fields[$id]['data_type'] == 'ContactReference') {
          $fields[$id]['expose_list'] = TRUE;
          $fields[$id]['empty_option'] = t('None');
        }
        elseif ($fields[$id]['data_type'] !== 'Boolean' && $fields[$id]['type'] == 'select') {
          $fields[$id]['extra']['civicrm_live_options'] = 1;
        }
        elseif ($fields[$id]['type'] == 'textarea') {
          $fields[$id]['extra']['cols'] = $custom_field['note_columns'] ?? 60;
          $fields[$id]['extra']['rows'] = $custom_field['note_rows'] ?? 4;
        }
      }
      // The sets are modified in this function to include the custom sets.
      $this->sets = $sets;
    }
    return $$var;
  }
}
