<?php

namespace Drupal\webform_civicrm;

/**
 * @file
 * Front-end form handler base class.
 */

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

/**
 * Class WebformCivicrmBase
 *
 * @property array $payment_processor
 * @property number $tax_rate
 * @property number $civicrm_version
 */
abstract class WebformCivicrmBase {

  /**
   * @var \Drupal\webform\WebformInterface
   */
  protected $node;
  protected $settings = [];
  protected $enabled = [];
  protected $data = [];
  protected $ent = [];
  protected $events = [];
  protected $line_items = [];
  protected $membership_types = [];
  protected $loadedContacts = [];
  protected $editingSubmission;

  // No direct access - storage for variables fetched via __get
  private $_payment_processor;
  // tax integration
  private $_tax_rate;

  const
    MULTIVALUE_FIELDSET_MODE_CREATE_OR_EDIT = 0,
    MULTIVALUE_FIELDSET_MODE_CREATE_ONLY = 1;

  /**
   * Magic method to retrieve otherwise inaccessible properties
   * @param $name
   * @throws Exception
   * @return mixed
   */
  function __get($name) {
    switch ($name) {
      case 'payment_processor':
        $payment_processor_id = wf_crm_aval($this->data, 'contribution:1:contribution:1:payment_processor_id');
        if ($payment_processor_id && !$this->_payment_processor) {
          $this->_payment_processor = $this->utils->wf_civicrm_api('payment_processor', 'getsingle', ['id' => $payment_processor_id]);
        }
        return $this->_payment_processor;

      case 'tax_rate':
        if (\Civi::settings()->get('invoicing')) {
          $contribution_enabled = wf_crm_aval($this->data, 'contribution:1:contribution:1:enable_contribution');
          if ($contribution_enabled) {
            // tax integration
            $taxRates = \CRM_Core_PseudoConstant::getTaxRates();
            $ft = wf_crm_aval($this->data, 'contribution:1:contribution:1:financial_type_id');
            $this->_tax_rate = $taxRates[$ft] ?? NULL;
          }
          return $this->_tax_rate;
        }
        return NULL;

      case 'civicrm_version':
        return \CRM_Utils_System::version();

      default:
        throw new Exception('Unknown property');
    }
  }

  /**
   * Load Billing Address for contact.
   */
  protected function loadBillingAddress($cid) {
    $billingFields = ["street_address", "city", "postal_code", "country_id", "state_province_id"];
    $billingAddress = $this->utils->wf_civicrm_api('Address', 'get', [
      'contact_id' => $cid,
      'location_type_id' => 'Billing',
      'return' => $billingFields,
      'options' => [
        'limit' => 1,
        'sort' => 'is_primary DESC',
      ],
    ]);
    if (!empty($billingAddress['values'])) {
      $address = array_pop($billingAddress['values']);
      foreach ($address as $key => $value) {
        if (in_array($key, $billingFields)) {
          $address['billing_address_' . $key] = $value;
        }
        unset($address[$key]);
      }
    }
    foreach (['first_name', 'middle_name', 'last_name'] as $name) {
      $address["billing_address_{$name}"] = $this->loadedContacts[1]['contact'][1][$name] ?? NULL;
    }
    return $address;
  }

  /**
   * Fetch all relevant data for a given contact
   * Used to load contacts for pre-filling a webform, and also to fill in a contact via ajax
   *
   * @param int $c
   *   Contact #
   * @param array $exclude
   *   Fields to ignore
   *
   * @return array
   *   Contact data
   */
  protected function loadContact($c, $exclude = []) {
    if (!empty($this->loadedContacts[$c])) {
      return $this->loadedContacts[$c];
    }
    $info = [];
    $cid = $this->ent['contact'][$c]['id'];
    if (!$cid) {
      return $info;
    }
    $contact = $this->data['contact'][$c];
    $prefix = 'civicrm_' . $c . '_contact_1_';
    $existing_contact_field = $this->node->getElement($prefix . 'contact_existing');
    // If editing a webform submission, contact id might be present without being supplied by an existing_contact field
    if ($existing_contact_field) {
      $element_manager = \Drupal::getContainer()->get('plugin.manager.webform.element');
      $existing_component_plugin = $element_manager->getElementInstance($existing_contact_field);
      $element_exclude = $existing_component_plugin->getElementProperty($existing_contact_field, 'no_autofill');
      $exclude = array_merge($exclude, $element_exclude);
    }
    foreach (array_merge(['contact'], $this->utils->wf_crm_location_fields()) as $ent) {
      if ((!empty($contact['number_of_' . $ent]) && !in_array($ent, $exclude)) || $ent == 'contact') {
        $params = ['contact_id' => $cid];
        if ($ent != 'contact' && $ent != 'website') {
          $params['options']['sort'] = 'is_primary DESC';
        }
        $result = $this->utils->wf_civicrm_api($ent, 'get', $params);
        // Handle location field sorting
        if (in_array($ent, $this->utils->wf_crm_location_fields())) {
          $result['values'] = $this->reorderByLocationType($c, $ent, $result['values']);
        }
        if (!empty($result['values'])) {
          // Index array from 1 instead of 0
          $result = array_merge([0], array_values($result['values']));
          unset($result[0]);
          if ($ent == 'contact') {
            // Exclude name fields
            if (in_array('name', $exclude)) {
              unset($result[1]['first_name'], $result[1]['middle_name'], $result[1]['last_name'],
                $result[1]['formal_title'], $result[1]['prefix_id'], $result[1]['suffix_id'],
                $result[1]['nick_name'], $result[1]['organization_name'], $result[1]['household_name']
              );
            }
            // Privacy fields
            if (isset($this->enabled[$prefix . 'contact_privacy'])) {
              foreach (array_keys($this->utils->wf_crm_get_privacy_options()) as $key) {
                if (!empty($result[1][$key])) {
                  $result[1]['privacy'][] = $key;
                }
              }
            }
            // User id
            if (isset($this->enabled[$prefix . 'contact_user_id'])) {
              $result[1]['user_id'] = $this->utils->wf_crm_user_cid($cid, 'contact');
            }
            // Hack for gender as textfield. More general solution needed for all pseudoconsant fields
            $gender_field = $this->node->getElement("civicrm_{$c}_contact_1_contact_gender_id");
            if ($gender_field && $gender_field['#type'] == 'textfield') {
              $result[1]['gender_id'] = wf_crm_aval($result[1], 'gender');
            }
          }
          // Extra processing for addresses
          if ($ent == 'address') {
            foreach ($result as &$address) {
              // Load custom data
              if (isset($address['id'])){
                $custom = $this->getCustomData($address['id'], 'address');
                if (!empty($custom['address'])) {
                  $address += $custom['address'][1];
                }
              }
            }
          }
          $info[$ent] = $result;
        }
      }
    }
    // Get custom contact data if needed
    foreach ($contact as $k => $v) {
      if (substr($k, 0, 12) == 'number_of_cg' && !empty($v)) {
        $cgKey = substr($k, 10);
        if (!in_array($cgKey, $exclude, TRUE)) {
          $info += array_diff_key($this->getCustomData($cid, 'contact', TRUE, $c), array_flip($exclude));
          break;
        }
      }
    }
    // Retrieve group and tag data
    if (!in_array('other', $exclude)) {
      $api = ['tag' => 'entity_tag', 'group' => 'group_contact'];
      foreach (array_keys($this->enabled) as $fid) {
        // This way we support multiple tag fields (for tagsets)
        if (strpos($fid, $prefix . 'other') !== FALSE) {
          list(, , , , , $ent) = explode('_', $fid);
          list(, , , , , $field) = explode('_', $fid, 6);
          // Cheap way to avoid fetching the same data twice from the api
          if (!is_array($api[$ent])) {
            $api[$ent] = $this->utils->wf_civicrm_api($api[$ent], 'get', ['contact_id' => $cid]);
          }
          foreach (wf_crm_aval($api[$ent], 'values') as $val) {
            $info['other'][1][$field][] = $val[$ent . '_id'];
          }
        }
      }
    }
    // Retrieve relationship data
    if (!in_array('relationship', $exclude) && !empty($contact['number_of_relationship'])) {
      $this->enabled = $this->utils->wf_crm_enabled_fields($this->node);
      for ($r = 1; $r <= $contact['number_of_relationship']; ++$r) {
        $types = [];
        $prefix = "civicrm_{$c}_contact_{$r}_relationship_";
        if (!empty($this->ent['contact'][$r]['id'])) {
          if (!empty($contact['relationship'][$r]['relationship_type_id']) && $contact['relationship'][$r]['relationship_type_id'] != 'create_civicrm_webform_element') {
            $types = (array) $contact['relationship'][$r]['relationship_type_id'];
          }
          if (!empty($this->enabled[$prefix . 'relationship_type_id'])) {
            $types += array_keys($this->getExposedOptions($prefix . 'relationship_type_id'));
          }
        }
        $rel = $this->getRelationship($types, $cid, wf_crm_aval($this->ent['contact'], "$r:id"));
        if ($rel) {
          $info['relationship'][$r] = $rel;
        }
      }
    }
    $this->loadedContacts[$c] = $info;
    return $info;
  }

  /**
   * Find an existing contact based on matching criteria
   * Used to populate a webform existing contact field
   *
   * @param array $component
   *   Webform component of type 'civicrm_contact'
   */
  protected function findContact($component) {
    $contactComponent = \Drupal::service('webform_civicrm.contact_component');
    $component['#form_key'] = $component['#form_key'] ?? $component['#webform_key'];

    list(, $c,) = explode('_', $component['#form_key'], 3);
    $filters = $contactComponent->wf_crm_search_filters($this->node, $component);
    // Start with the url - that trumps everything.
    $element_manager = \Drupal::getContainer()->get('plugin.manager.webform.element');
    $existing_component_plugin = $element_manager->getElementInstance($component);
    $allow_url_autofill = $existing_component_plugin->getElementProperty($component, 'allow_url_autofill');
    if ($allow_url_autofill) {
      $query = \Drupal::request()->query;
      if ($query->has("cid$c") || ($c == 1 && $query->has('cid'))) {
        $cid = $query->has("cid$c") ? $query->get("cid$c") : $query->get('cid');
        if ($cid == 0) {
          $this->ent['contact'][$c]['id'] = $cid;
          return;
        }
        if ($contactComponent->wf_crm_contact_access($component, $filters, $cid) != FALSE) {
          $this->ent['contact'][$c]['id'] = $cid;
        }
      }
    }
    if (empty($this->ent['contact'][$c]['id']) && !empty($component['#default'])) {
      $found = [];
      switch ($component['#default']) {
        case 'user':
          $cid = $this->utils->wf_crm_user_cid();
          $found = ($c == 1 && $cid) ? [$cid] : [];
          break;
        case 'contact_id':
          if (isset($component['#default_contact_id'])) {
            $found = [$component['#default_contact_id']];
          }
          break;
        case 'relationship':
          $to = $component['#default_relationship_to'];
          if (!empty($component['#default_relationship']) && !empty($this->ent['contact'][$to]['id'])) {
            $found = $contactComponent->wf_crm_find_relations($this->ent['contact'][$to]['id'], $component['#default_relationship']);
          }
          break;
        case 'auto':
          $component['#allow_create'] = FALSE;
          $found = array_keys($contactComponent->wf_crm_contact_search($this->node, $component, $filters, wf_crm_aval($this->ent, 'contact', [])));
          break;
      }
      if (isset($component['#randomize']) && $component['#randomize']) {
        shuffle($found);
      }
      if (in_array($component['#default'], ['user', 'contact_id'])) {
        $dupes_allowed = TRUE;
      }
      else {
        $dupes_allowed = $component['#dupes_allowed'] ?? 0;
      }
      foreach ($found as $cid) {
        // Don't pick the same contact twice unless explicitly told to do so
        if (!$dupes_allowed) {
          foreach($this->ent['contact'] as $contact) {
            if (!empty($contact['id']) && $cid == $contact['id']) {
              continue 2;
            }
          }
        }
        // Check filters except for 'auto' which already applied them
        if ($component['#default'] == 'auto' || $contactComponent->wf_crm_contact_access($component, $filters, $cid) != FALSE) {
          $this->ent['contact'][$c]['id'] = $cid;
          break;
        }
      }
    }
  }

  /**
   * Reorder returned results according to settings chosen in wf_civicrm backend
   *
   * @param integer $c
   * @param string $ent
   * @param array $values
   * @return array $reorderedArray
   */
  protected function reorderByLocationType($c, $ent, $values = []){
    $reorderedArray = [];

    if (isset($this->settings['data']['contact'][$c][$ent])){
      // First pass
      if ($ent == 'website') {
        $reorderedArray = $this->matchWebsiteTypes($c, $ent, $values);
      }
      else {
        $reorderedArray = $this->matchLocationTypes($c, $ent, $values);
      }
      // Second pass
      $reorderedArray = $this->handleRemainingValues($reorderedArray, $values);

      return $reorderedArray;
    } else {
      return $values;
    }
  }

  /**
   * Organize values according to location types
   *
   * @param integer $c
   * @param string $ent
   * @param array $values
   * @return array $reorderedArray
   */
  protected function matchLocationTypes($c, $ent, &$values) {
    // create temporary settings array to include 'user-select' fields
    // on the right place in array
    $settingsArray = $this->add_user_select_field_placeholder($ent, $this->settings['data']['contact'][$c]);
    $userSelectIndex = 0;
    // Go through the array and match up locations by type
    // Put placeholder 'user-select' where location_type_id is empty for second pass
    foreach ($settingsArray[$ent] as $setting) {
      $valueFound = FALSE;
      foreach ($values as $key => $value) {
        if ((in_array($ent, ['address', 'email']) && $value['location_type_id'] == $setting['location_type_id'])
          || (
            isset($setting['location_type_id']) && $value['location_type_id'] == $setting['location_type_id'] &&
            (
              !isset($setting[$ent . '_type_id']) ||
              (isset($value[$ent . '_type_id'])) && $value[$ent . '_type_id'] == $setting[$ent . '_type_id']
            )
          )
        ) {
          $reorderedArray[$key] = $value;
          $valueFound = TRUE;
          unset($values[$key]);
          break;
        }
        // For 'user-select' fields
        elseif (empty($setting['location_type_id'])) {
          $valueFound = TRUE;
          $reorderedArray['us' . $userSelectIndex] = 'user-select';
          $userSelectIndex++;
          break;
        }
      }

      // always keep number of returned values equal to chosen settings
      // if value is not found then set an empty array
      if (!$valueFound) {
        $reorderedArray[] = [];
      }
    }
    return $reorderedArray;
  }

  /**
   * Organize values according to website types
   *
   * @param integer $c
   * @param string $ent
   * @param array $values
   * @return array $reorderedArray
   */
  protected function matchWebsiteTypes($c, $ent, &$values) {
    // create temporary settings array to include 'user-select' fields
    // on the right place in array
    $settingsArray = $this->add_user_select_field_placeholder($ent, $this->settings['data']['contact'][$c]);
    $userSelectIndex = 0;
    // Go through the array and match up locations by type
    // Put placeholder 'user-select' where location_type_id is empty for second pass
    foreach ($settingsArray[$ent] as $setting) {
      $valueFound = FALSE;
      foreach ($values as $key => $value) {
        if (($value[$ent . '_type_id'] == $setting[$ent . '_type_id'])
        ) {
          $reorderedArray[$key] = $value;
          $valueFound = TRUE;
          unset($values[$key]);
          break;
        }
        else {
          if (empty($setting['website_type_id'])) { // for 'user-select' fields
            $valueFound = TRUE;
            $reorderedArray['us' . $userSelectIndex] = 'user-select';
            $userSelectIndex++;
            break;
          }
        }
      }

      // always keep number of returned values equal to chosen settings
      // if value is not found then set an empty array
      if (!$valueFound){
        $reorderedArray[] = [];
      }
    }
    return $reorderedArray;
  }

  /**
   * Put remaining values in 'user-select' fields
   *
   * @param array $reorderedArray
   * @param array $values
   * @return array $reorderedArray
   */
  protected function handleRemainingValues($reorderedArray, &$values){
    // Put leftover values in fields marked as 'user-select'
    foreach($reorderedArray as $key => $value){
      if ($reorderedArray[$key] == 'user-select'){
        $reorderedArray[$key] = !empty($values) ? array_shift($values) : '';
      }
    }
    return $reorderedArray;
  }

  /**
   * Add location_type_id = NULL for user-select fields for identification later
   *
   * @param string $ent
   * @param array $settings
   * @return array $settings
   */
  protected function add_user_select_field_placeholder($ent, $settings = []){
    if ($settings['number_of_'.$ent] > count($settings[$ent])){
      for($i = 1; $i <= $settings['number_of_'.$ent]; $i++){
        if (!array_key_exists($i, $settings[$ent])){
          $settings[$ent][$i]['location_type_id'] = NULL;
        }
      }
      ksort($settings[$ent]);
    }
    return $settings;
  }

  /**
   * Fetch relationship for a pair of contacts
   *
   * @param $r_types
   *   Array of relationship type ids
   * @param $cid1
   *   Contact id
   * @param $cid2
   *   Contact id
   * @param $active_only
   *   if TRUE - only active relationships are returned.
   * @return array
   */
  protected function getRelationship($r_types, $cid1, $cid2, $active_only = FALSE) {
    $found = [];
    if (!$active_only) {
      $active_only = !empty($this->settings['create_new_relationship']);
    }

    if ($r_types && $cid1 && $cid2) {
      $types = [];
      foreach ($r_types as $r_type) {
        list($type, $side) = explode('_', $r_type);
        $types[$type] = $type;
      }
      $params = [
        'contact_id_a' => ['IN' => [$cid1, $cid2]],
        'contact_id_b' => ['IN' => [$cid1, $cid2]],
        'relationship_type_id' => ['IN' => $types],
      ];
      if ($active_only) {
        $params['is_active'] = 1;
        $params['options']['sort'] = 'is_active DESC, end_date ASC';
      }
      foreach ($this->utils->wf_crm_apivalues('relationship', 'get', $params) as $rel) {
        $type = $rel['relationship_type_id'];
        $side = $rel['contact_id_a'] == $cid1 ? 'a' : 'b';
        if (
          // Verify relationship orientation
          (in_array("{$type}_$side", $r_types) || in_array("{$type}_r", $r_types))
          // Verify 2 contacts are different unless we're specifically looking for a self-relationship
          && ($rel['contact_id_a'] != $rel['contact_id_b'] || $cid1 == $cid2)
          // Verify end date is not past when searching for active only
          && (empty($rel['end_date']) || !$active_only || strtotime($rel['end_date']) > time())
        ) {
          // Support multi-valued relationship type fields, fudge the rest
          $found['relationship_type_id'][] = in_array("{$type}_r", $r_types) ? "{$type}_r" : "{$type}_$side";
          $found['relationship_permission'] = (!empty($rel['is_permission_a_b']) ? 1 : 0) + (!empty($rel['is_permission_b_a']) ? 2 : 0);
          $found += $rel;
        }
      }
    }
    return $found;
  }

  /**
   * For a given field, find the options that are exposed to the webform.
   *
   * @param $field_key
   *   Field key
   * @param array $exclude
   *   Options to ignore
   *
   * @return array
   */
  protected function getExposedOptions($field_key, $exclude = []) {
    $field = $this->getComponent($field_key);

    if ($field && ($field['#type'] == 'hidden' || !empty($field['#civicrm_live_options']))) {
      // Fetch live options
      $params = [
        'extra' => wf_crm_aval($field, 'extra', []) + wf_crm_aval($field, '#extra', []),
        'form_key' => $field['#form_key'],
      ];
      $exposed = $this->utils->wf_crm_field_options($params, 'civicrm_live_options', $this->data);
      foreach ($exclude as $i) {
        unset($exposed[$i]);
      }
      return $exposed;
    }

    $supportedTypes = ['civicrm_options', 'checkboxes', 'radios', 'select'];
    if (isset($field['#options']) && in_array($field['#type'], $supportedTypes)) {
      return array_diff_key($field['#options'], array_flip($exclude));
    }
    return [];
  }

  /**
   * Fetch a webform component given its civicrm field key
   * @param $field_key
   * @return array|null
   */
  protected function getComponent($field_key) {
    if ($field_key && isset($this->enabled[$field_key])) {
      $elements = $this->node->getElementsDecodedAndFlattened();
      return $elements[$this->enabled[$field_key]];
    }
    return NULL;
  }

  /**
   * Get memberships for a contact
   * @param $cid
   * @return array
   */
  protected function findMemberships($cid) {
    static $status_types;
    static $membership_types;

    if (!isset($membership_types)) {
      $domain = $this->utils->wf_civicrm_api('domain', 'get', ['current_domain' => 1, 'return' => 'id']);
      $domain = wf_crm_aval($domain, 'id', 1);
      $membership_types = array_keys($this->utils->wf_crm_apivalues('membershipType', 'get', ['is_active' => 1, 'domain_id' => $domain, 'return' => 'id']));
    }
    $existing = $this->utils->wf_crm_apivalues('membership', 'get', [
      'contact_id' => $cid,
      // Limit to only enabled membership types
      'membership_type_id' => ['IN' => $membership_types],
      // skip membership through Inheritance.
      'owner_membership_id' => ['IS NULL' => 1],
      'options' => ['sort' => 'end_date DESC'],
    ]);
    if (!$existing) {
      return [];
    }
    if (!$status_types) {
      $status_types = $this->utils->wf_crm_apivalues('membership_status', 'get');
    }
    // Attempt to order memberships by most recent and active
    $active = $expired = [];
    foreach ($existing as $membership) {
      $membership['is_active'] = $status_types[$membership['status_id']]['is_current_member'];
      $membership['status'] = $status_types[$membership['status_id']]['label'];
      $list = $membership['is_active'] ? 'active' : 'expired';
      $$list[] = $membership;
    }

    return array_merge($active, $expired);
  }

  /**
   * Fetch info and remaining spaces for events
   */
  protected function loadEvents() {
    if (!empty($this->events)) {
      $now = time();
      $events = $this->utils->wf_crm_apivalues('Event', 'get', [
        'return' => ['title', 'start_date', 'end_date', 'event_type_id', 'max_participants', 'financial_type_id', 'event_full_text', 'is_full'],
        'id' => ['IN' => array_keys($this->events)],
      ]);
      foreach ($events as $id => $event) {
        $this->events[$id] = $event + $this->events[$id] + ['available_places' => 0];
        $this->events[$id]['ended'] = !empty($event['end_date']) && strtotime($event['end_date']) < $now;
      }
    }
  }

  /**
   * Get custom data for an entity
   *
   * @param $entity_id
   *   Numeric id of entity
   * @param $entity_type
   *   Type of crm entity. 'contact' is assumed
   * @param $normalize
   *   Default true: if true shift all arrays to start at index 1
   * @param int $entity_num
   *   Index of entity on the form; used for matching multi-record custom data
   *
   * @return array
   */
  protected function getCustomData($entity_id, $entity_type = 'contact', $normalize = TRUE, $entity_num = 1) {
    static $parents = [];
    if (empty($parents)) {
      // Create matching table to sort fields by group
      foreach ($this->utils->wf_crm_get_fields() as $key => $value) {
        list($group, $field) = explode('_', $key, 2);
        if (strpos($field, 'custom_') === 0) {
          $parents[$field] = $group;
        }
      }
    }
    $params = [
      'entity_id' => $entity_id,
      'entity_table' => ucfirst($entity_type),
    ];
    $result = $this->utils->wf_crm_apivalues('CustomValue', 'get', $params);
    $values = [];
    foreach ($result as $key => $value) {
      $name = 'custom_' . $key;
      // Sort into groups
      if (isset($parents[$name])) {
        $cgKey = $parents[$name];
        $n = 1;
        // When editing a submission, match existing data to submitted ids
        if (!empty($this->ent['contact'][$entity_num][$cgKey])) {
          foreach ($this->ent['contact'][$entity_num][$cgKey] as $id) {
            $values[$cgKey][$normalize ? $n++ : $id][$name] = $value[$id] ?? NULL;
          }
        }
        // If not editing a submission, exclude "create only" custom data
        elseif (empty($this->data['config']['create_mode']["civicrm_{$entity_num}_contact_1_{$cgKey}_createmode"])) {
          foreach ($value as $id => $item) {
            // Non-numeric keys are api extras like "id" and "latest"
            if (is_numeric($id)) {
              $values[$cgKey][$normalize ? $n++ : $id][$name] = $item;
            }
          }
        }
      }
    }
    return $values;
  }

  /**
   * @param string $fid
   * @param mixed $default
   * @param bool $strict
   * @return mixed
   */
  protected function getData($fid, $default = NULL, $strict = FALSE) {
    if ($pieces = $this->utils->wf_crm_explode_key($fid)) {
      list( , $c, $ent, $n, $table, $name) = $pieces;
      return wf_crm_aval($this->data, "{$ent}:{$c}:{$table}:{$n}:{$name}", $default, $strict);
    }
  }

  /**
   * Find a case matching criteria
   *
   * Normally we could do this by passing filters into the api, but legacy case api doesn't support them
   * So instead we fetch every case for the contact and loop through them to test against filters.
   *
   * @param array|int $cid
   * @param array $filters
   * @return null|array
   */
  function findCaseForContact($cid, $filters) {
    $case = NULL;
    foreach ($this->utils->wf_crm_apivalues('case', 'get', ['client_id' => $cid]) as $item) {
      if (empty($item['is_deleted'])) {
        $match = TRUE;
        foreach (array_filter($filters) as $filter => $value) {
          if (!array_intersect((array)$item[$filter], (array)$value)) {
            $match = FALSE;
          }
        }
        // Note: this loop has no break on purpose - this way we find the most recent case instead of stopping at the first
        if ($match) {
          $case = $item;
        }
      }
    }
    return $case;
  }

  /**
   * @param $type
   * @param $field
   * @return array|null
   */
  protected function getMembershipTypeField($type, $field) {
    if (!$this->membership_types) {
      $this->membership_types = $this->utils->wf_crm_apivalues('membership_type', 'get');
    }
    return wf_crm_aval($this->membership_types, $type . ':' . $field);
  }

  /**
   * CiviCRM JS can't be attached to a drupal form so have to manually re-add this during validation
   *
   * @return void
   */
  function addPaymentJs() {
    $currentVer = \CRM_Core_BAO_Domain::version();
    if (version_compare($currentVer, '5.8') <= 0 && method_exists('CRM_Core_Payment_Form', 'getCreditCardCSSNames')) {
      $credit_card_types = \CRM_Core_Payment_Form::getCreditCardCSSNames();
      \CRM_Core_Resources::singleton()
        ->addCoreResources()
        ->addSetting(['config' => ['creditCardTypes' => $credit_card_types]])
        ->addScriptFile('civicrm', 'templates/CRM/Core/BillingBlock.js', -10, 'html-header');
    }
    else {
      \CRM_Core_Resources::singleton()->addCoreResources();
      \CRM_Financial_Form_Payment::addCreditCardJs(NULL, 'html-header');
    }
  }

  /**
   * Copies a drupal file into the Civi file system
   *
   * @param int $id: drupal file id
   * @return int|null Civi file id
   */
  public static function saveDrupalFileToCivi($id) {
    $file = File::load($id);
    if ($file) {
      $config = \CRM_Core_Config::singleton();
      $path = \Drupal::service('file_system')->copy($file->getFileUri(), $config->customFileUploadDir);
      if ($path) {
        $result = \Drupal::service('webform_civicrm.utils')->wf_civicrm_api('file', 'create', [
          'uri' => str_replace($config->customFileUploadDir, '', $path),
          'mime_type' => $file->getMimeType(),
        ]);
        return wf_crm_aval($result, 'id');
      }
    }
    return NULL;
  }

  /**
   * Retrieve info needed for pre-filling a webform file field
   * @param string $fieldName
   * @param string|int $val: url or civi file id
   * @param string|null $entity: entity name
   * @param int|null $n: entity id
   * @return array|null
   */
  function getFileInfo($fieldName, $val, $entity, $n) {
    if (!$val) {
      return NULL;
    }
    if ($fieldName === 'image_url') {
      $parsed = UrlHelper::parse($val);

      return [
        'data_type' => 'File',
        'name' => $parsed['query']['photo'],
        'icon' => 'image',
        'file_url' => $val,
      ];
    }
    $file = $this->utils->wf_crm_apivalues('Attachment', 'get', $val);
    if (!empty($file[$val])) {
      return [
        'data_type' => 'File',
        'name' => $file[$val]['name'],
        'file_url'=> $file[$val]['url'],
        'icon' => file_icon_class($file[$val]['mime_type']),
      ];
    }
    return NULL;
  }

  /**
   * Fetch the public url of a file in the Drupal file system
   *
   * @param int $id Drupal file id
   *
   * @return mixed
   *   An array of the file entity or empty string.
   */
  function getDrupalFileUrl($id) {
    if ($id = $this->saveDrupalFileToCivi($id)) {
      $config = \CRM_Core_Config::singleton();
      $result = $this->utils->wf_civicrm_api('file', 'getsingle', ['id' => $id]);

      if ($result) {
        $photo = basename($config->customFileUploadDir . wf_crm_aval($result, 'uri'));
        return Url::fromRoute('civicrm.civicrm_contact_imagefile', [], [
          'query' => ['photo' => $photo],
          'absolute' => TRUE,
        ])->toString();
      }
    }

    return '';
  }

  /**
   * FIXME: Use the api for this
   * @param string $ent - entity type
   * @param int $id - entity id
   * @return array starting at index 1
   */
  public function getAttachments($ent, $id) {
    $n = 1;
    $attachments = [];
    $dao = \CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_entity_file WHERE entity_table = 'civicrm_$ent' AND entity_id = $id");
    while ($dao->fetch()) {
      $attachments[$n++] = ['id' => $dao->id, 'file_id' => $dao->file_id];
    }
    return $attachments;
  }

  /**
   * Generate the quickform key needed to access a contribution form
   * @return string
   */
  public function getQfKey() {
    return \CRM_Core_Key::get('CRM_Contribute_Controller_Contribution', TRUE);
  }

  /**
   * Returns default values for elements
   * set on the webform.
   *
   * @return array
   */
  function getWebformDefaults() {
    $utils = \Drupal::service('webform_civicrm.utils');
    $defaults = [];
    $elements = $this->node->getElementsDecodedAndFlattened();
    foreach ($elements as $comp) {
      if (!empty($comp['#default_value']) && isset($comp['#form_key'])) {
        $key = str_replace('_', '-', $comp['#form_key']);
        if ($comp['#type'] == 'date' || $comp['#type'] == 'datelist') {
          $defaults[$key] = date('Y-m-d', strtotime($comp['#default_value']));
        }
        else {
          $defaults[$key] = $comp['#default_value'];
        }
      }
    }
    return $defaults;
  }

}
