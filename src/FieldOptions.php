<?php

namespace Drupal\webform_civicrm;

class FieldOptions implements FieldOptionsInterface {

  protected $fields;

  public function __construct(FieldsInterface $fields) {
    $this->fields = $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function get($field, $context, $data) {
    $ret = [];
    $fields = $this->fields->get();
    $utils = \Drupal::service('webform_civicrm.utils');
    $pieces = $utils->wf_crm_explode_key($field['form_key']);
    if (!empty($pieces)) {
      list( , $c, $ent, $n, $table, $name) = $pieces;
      // Ensure we have complete info for this field
      if (isset($fields[$table . '_' . $name])) {
        $field += $fields[$table . '_' . $name];
      }

      if ($name === 'contact_sub_type') {
        list($contact_types, $sub_types) = $utils->wf_crm_get_contact_types();
        $ret = wf_crm_aval($sub_types, $data['contact'][$c]['contact'][1]['contact_type'], []);
      }
      elseif ((strpos($name, 'state_province_id') !== false) || (isset($field['type']) && $field['type'] === 'civicrm_number')) {
        return [];
      }
      elseif ($name === 'relationship_type_id') {
        $ret = $utils->wf_crm_get_contact_relationship_types($data['contact'][$c]['contact'][1]['contact_type'], $data['contact'][$n]['contact'][1]['contact_type'], $data['contact'][$c]['contact'][1]['contact_sub_type'], $data['contact'][$n]['contact'][1]['contact_sub_type']);
      }
      elseif ($name === 'relationship_permission') {
        $ret = [
          1 => t(':a may view and edit :b', [
            ':a' => $utils->wf_crm_contact_label($c, $data, 'plain'),
            ':b' => $utils->wf_crm_contact_label($n, $data, 'plain'),
          ]),
          2 => t(':a may view and edit :b', [
            ':a' => $utils->wf_crm_contact_label($n, $data, 'plain'),
            ':b' => $utils->wf_crm_contact_label($c, $data, 'plain'),
          ]),
          3 => t('Both contacts may view and edit each other'),
        ];
      }
      // If this is a contact reference or shared address field, list webform contacts
      elseif ($name === 'master_id' || wf_crm_aval($field, 'data_type') === 'ContactReference') {
        $contact_type = wf_crm_aval($field, 'reference_contact_type', 'contact');
        foreach ($data['contact'] as $num => $contact) {
          if ($num != $c || $name != 'master_id') {
            if ($contact_type == 'contact' || $contact_type == $contact['contact'][1]['contact_type']) {
              $ret[$num] = $utils->wf_crm_contact_label($num, $data, 'plain');
            }
          }
        }
      }
      elseif ($name == 'privacy') {
        $ret = $utils->wf_crm_get_privacy_options();
      }
      elseif (isset($field['table']) && $field['table'] === 'tag') {
        $split = explode('_', $name);
        $ret = $utils->wf_crm_get_tags($ent, wf_crm_aval($split, 1));
      }
      elseif (isset($field['table']) && $field['table'] === 'group') {
        $params = ['is_hidden' => 0];
        $options = wf_crm_aval($data, "contact:$c:other:1:group");
        if (!empty($options) && !empty($options['public_groups'])) {
          $params['visibility'] = "Public Pages";
        }
        $ret = $utils->wf_crm_apivalues('group', 'get',  $params, 'title');
      }
      elseif ($name === 'survey_id') {
        $ret = $utils->wf_crm_get_surveys(wf_crm_aval($data, "activity:$c:activity:1", []));
      }
      elseif ($name == 'event_id') {
        $ret = $utils->wf_crm_get_events($data['reg_options'], $context);
      }
      elseif ($table == 'contribution' && $name == 'is_test') {
        // Getoptions would return 'yes' and 'no' - this is a bit more descriptive
        $ret = [0 => t('Live Transactions'), 1 => t('Test Mode')];
      }
      // Not a real field so can't call getoptions for this one
      elseif ($table == 'membership' && $name == 'num_terms') {
        $ret = array_combine(range(1, 9), range(1, 9));
      }
      elseif ($table === 'contribution' && $name === 'payment_processor_id') {
        // For the config form we display a list of all active (live) payment processors
        // Saving will map the IDs to live or test.
        // For the frontend we display the selected payment processors (with correct ID for live or test)
        $params = wf_crm_aval($data, "$ent:$c:$table:$n", []);
        $paymentProcessors = $utils->wf_crm_apivalues('PaymentProcessor', 'get', ['is_test' => $params['is_test'] ?? 0, 'is_active' => 1]);
        $paymentProcessors[0]['name'] = $field['exposed_empty_option'];
        foreach ($paymentProcessors as $paymentProcessorID => $paymentProcessor) {
          if ($context === 'config_form') {
            $ret[$paymentProcessorID] = $paymentProcessor['name'];
          }
          else {
            $ret[$paymentProcessorID] = $paymentProcessor['title'] ?? $paymentProcessor['name'];
          }
        }
        return $ret;
      }
      // Aside from the above special cases, most lists can be fetched from api.getoptions
      else {
        $params = ['field' => $name, 'context' => 'create'];
        // Special case for contribution_recur fields
        if ($table === 'contribution') {
          if (str_starts_with($name, 'frequency_')) {
            $table = 'contribution_recur';
          }
          elseif ($name === 'soft_credit_type_id') {
            $table = 'contribution_soft';
          }
          elseif (str_starts_with($name, 'billing_address_')) {
            $table = 'address';
            $params['field'] = str_replace('billing_address_', '', $params['field']);
          }
        }
        // Use the Contribution table to pull up financial type id-s
        if ($table == 'membership' && $name == 'financial_type_id') {
          $table = 'contribution';
        }
        // Custom fields - use main entity
        if (substr($table, 0, 2) == 'cg') {
          $table = $ent;
        }
        else {
          // Pass data into api.getoptions for contextual filtering
          $params += wf_crm_aval($data, "$ent:$c:$table:$n", []);
        }
        $ret = $utils->wf_crm_apivalues($table, 'getoptions', $params);

        // Hack to format money data correctly
        if (!empty($field['data_type']) && $field['data_type'] === 'Money') {
          $old = $ret;
          $ret = [];
          foreach ($old as $key => $val) {
            $ret[number_format(str_replace(',', '', $key), 2, '.', '')] = $val;
          }
        }
      }
      // Remove options that were set behind the scenes on the admin form
      if ($context != 'config_form' && !empty($field['extra']['multiple']) && !empty($field['expose_list'])) {
        foreach (wf_crm_aval($data, "$ent:$c:$table:$n:$name", []) as $key => $val) {
          unset($ret[$key]);
        }
      }
    }
    if (!empty($field['exposed_empty_option'])) {
      $ret = [0 => $field['exposed_empty_option']] + $ret;
    }
    return $ret;
  }

}
