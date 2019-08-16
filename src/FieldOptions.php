<?php

namespace Drupal\webform_civicrm;

// Include legacy files for their procedural functions.
// @todo convert required functions into injectable services.
include_once __DIR__ . '/../includes/utils.inc';

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
    $pieces = Utils::wf_crm_explode_key($field['form_key']);
    if (!empty($pieces)) {
      list( , $c, $ent, $n, $table, $name) = $pieces;
      // Ensure we have complete info for this field
      if (isset($fields[$table . '_' . $name])) {
        $field += $fields[$table . '_' . $name];
      }

      if ($name === 'contact_sub_type') {
        list($contact_types, $sub_types) = wf_crm_get_contact_types();
        $ret = wf_crm_aval($sub_types, $data['contact'][$c]['contact'][1]['contact_type'], array());
      }
      elseif ($name === 'relationship_type_id') {
        $ret = wf_crm_get_contact_relationship_types($data['contact'][$c]['contact'][1]['contact_type'], $data['contact'][$n]['contact'][1]['contact_type'], $data['contact'][$c]['contact'][1]['contact_sub_type'], $data['contact'][$n]['contact'][1]['contact_sub_type']);
      }
      elseif ($name === 'relationship_permission') {
        $ret = [
          1 => t(':a may view and edit :b', [
            ':a' => wf_crm_contact_label($c, $data, 'plain'),
            ':b' => wf_crm_contact_label($n, $data, 'plain'),
          ]),
          2 => t(':a may view and edit :b', [
            ':a' => wf_crm_contact_label($n, $data, 'plain'),
            ':b' => wf_crm_contact_label($c, $data, 'plain'),
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
              $ret[$num] = wf_crm_contact_label($num, $data, 'plain');
            }
          }
        }
      }
      elseif ($name == 'privacy') {
        $ret = wf_crm_get_privacy_options();
      }
      elseif ($table === 'other') {
        if ($field['table'] === 'tag') {
          $split = explode('_', $name);
          $ret = wf_crm_get_tags($ent, wf_crm_aval($split, 1));
        }
        elseif ($field['table'] === 'group') {
          $ret = wf_crm_apivalues('group', 'get', array('is_hidden' => 0), 'title');
        }
      }
      elseif ($name === 'survey_id') {
        $ret = wf_crm_get_surveys(wf_crm_aval($data, "activity:$c:activity:1", array()));
      }
      elseif ($name == 'event_id') {
        $ret = wf_crm_get_events($data['reg_options'], $context);
      }
      elseif ($table == 'contribution' && $name == 'is_test') {
        // Getoptions would return 'yes' and 'no' - this is a bit more descriptive
        $ret = array(0 => t('Live Transactions'), 1 => t('Test Mode'));
      }
      // Not a real field so can't call getoptions for this one
      elseif ($table == 'membership' && $name == 'num_terms') {
        $ret = array_combine(range(1, 9), range(1, 9));
      }
      // Aside from the above special cases, most lists can be fetched from api.getoptions
      else {
        $params = array('field' => $name, 'context' => 'create');
        // Special case for contribution_recur fields
        if ($table == 'contribution' && strpos($name, 'frequency_') === 0) {
          $table = 'contribution_recur';
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
          $params += wf_crm_aval($data, "$ent:$c:$table:$n", array());
        }
        $ret = wf_crm_apivalues($table, 'getoptions', $params);

        // Hack to format money data correctly
        if (!empty($field['data_type']) && $field['data_type'] === 'Money') {
          $old = $ret;
          $ret = array();
          foreach ($old as $key => $val) {
            $ret[number_format(str_replace(',', '', $key), 2, '.', '')] = $val;
          }
        }
      }
      // Remove options that were set behind the scenes on the admin form
      if ($context != 'config_form' && !empty($field['extra']['multiple']) && !empty($field['expose_list'])) {
        foreach (wf_crm_aval($data, "$ent:$c:$table:$n:$name", array()) as $key => $val) {
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
