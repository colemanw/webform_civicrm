<?php

namespace Drupal\webform_civicrm;

use Drupal\webform\WebformInterface;

interface UtilsInterface {

  /**
   * Explodes form key into an array and verifies that it is in the right format
   *
   * @param $key
   *   Webform component field key (string)
   *
   * @return array or NULL
   */
  public function wf_crm_explode_key($key);

  /**
   * Get options for a specific field
   *
   * @param array $field
   *   Webform component array
   * @param string $context
   *   Where is this being called from?
   * @param array $data
   *   Array of crm entity data
   *
   * @return array
   */
  public static function wf_crm_field_options($field, $context, $data);

  /**
   * Fetches CiviCRM field data.
   *
   * @param string $var
   *   Name of variable to return: fields, tokens, or sets
   *
   * @return array
   * @return array
   *   fields: The CiviCRM contact fields this module supports
   *   tokens: Available tokens keyed to field ids
   *   sets: Info on fieldsets (entities)
   */
  public function wf_crm_get_fields($var = 'fields');

  /**
   * Get list of states, keyed by ID.
   * @param null|int|string $param
   */
  public function wf_crm_get_states($param = NULL);

  /**
   * Get list of events.
   *
   * @param array $reg_options
   * @param string $context
   *
   * @return array
   */
  function wf_crm_get_events($reg_options, $context);

  /**
   * @param array $event
   * @param string $format
   * @return string
   */
  function wf_crm_format_event($event, $format);

  /**
   * Fetch tags within a given tagset
   *
   * If no tagset specified, all tags NOT within a tagset are returned.
   * Return format is a flat array with some tic marks to indicate nesting.
   *
   * @param string $used_for
   * @param int $parent_id
   * @return array
   */
  function wf_crm_get_tags($used_for, $parent_id = NULL);

  /**
   * Get list of surveys
   * @param array $act
   *
   * @return array
   */
  function wf_crm_get_surveys($act = []);

  /**
   * Get activity types related to CiviCampaign
   * @return array
   */
  function wf_crm_get_campaign_activity_types();

  /**
   * Get contact types and sub-types
   * Unlike pretty much every other option list CiviCRM wants "name" instead of "id"
   *
   * @return array
   */
  function wf_crm_get_contact_types();

  /**
   * In reality there is no contact field 'privacy' so this is not a real option list.
   * These are actually 5 separate contact fields that this module munges into 1 for better usability.
   *
   * @return array
   */
  function wf_crm_get_privacy_options();

  /**
   * Get relationship type data
   *
   * @return array
   */
  function wf_crm_get_relationship_types();

  /**
   * Get valid relationship types for a given pair of contacts
   *
   * @param $type_a
   *   Contact type
   * @param $type_b
   *   Contact type
   * @param $sub_type_a
   *   Contact sub-type
   * @param $sub_type_b
   *   Contact sub-type
   *
   * @return array
   */
  function wf_crm_get_contact_relationship_types($type_a, $type_b, $sub_type_a, $sub_type_b);

  /**
   * List dedupe rules available for a contact type
   *
   * @param string $contact_type
   * @return array
   */
  function wf_crm_get_matching_rules($contact_type);

  /**
   * Get ids or values of enabled CiviCRM fields for a webform.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   * @param array|null $submission
   *   (optional) if supplied, will match field keys with submitted values
   * @param boolean $show_all
   *   (optional) if true, get every field even if it belongs to a contact that does not exist
   *
   * @return array of enabled fields
   */
  function wf_crm_enabled_fields(WebformInterface $webform, $submission = NULL, $show_all = FALSE);

  /**
   * Get a field based on its short or full name
   * @param string $key
   * @return array|null
   */
  function wf_crm_get_field($key);

  /**
   * Lookup a uf ID from contact ID or vice-versa
   * With no arguments passed in, this function will return the contact_id of the current logged-in user
   *
   * @param $id
   *   (optional) uf or contact ID - defaults to current user
   * @param $type
   *   (optional) what type of ID is supplied - defaults to user id
   * @return int|null
   */
  function wf_crm_user_cid($id = NULL, $type = 'uf');

  /**
   * Fetch contact display name
   *
   * @param $cid
   *   Contact id
   *
   * @return string
   */
  function wf_crm_display_name($cid);

  /**
   * @param integer $n
   * @param array $data Form data
   * @param string $html Controls how html should be treated. Options are:
   *  * 'escape': (default) Escape html characters
   *  * 'wrap': Escape html characters and wrap in a span
   *  * 'plain': Do not escape (use when passing into an FAPI options list which does its own escaping)
   * @return string
   */
  function wf_crm_contact_label($n, $data = [], $html = 'escape');

  /**
   * Convert a | separated string into an array
   *
   * @param string $str
   *   String representation of key => value select options
   *
   * @return array of select options
   */
  function wf_crm_str2array($str);

  /**
   * Convert an array into a | separated string
   *
   * @param array $arr
   *   Array of select options
   *
   * @return string
   *   String representation of key => value select options
   */
  function wf_crm_array2str($arr);

  /**
   * Wrapper for all CiviCRM API calls
   * For consistency, future-proofing, and error handling
   *
   * @param string $entity
   *   API entity
   * @param string $operation
   *   API operation
   * @param array $params
   *   API params
   *
   * @return array
   *   Result of API call
   */
  function wf_civicrm_api($entity, $operation, $params);

  /**
   * Get the values from an api call
   *
   * @param string $entity
   *   API entity
   * @param string $operation
   *   API operation
   * @param array $params
   *   API params
   * @param string $value
   *   Reduce each result to this single value
   *
   * @return array
   *   Values from API call
   */
  function wf_crm_apivalues($entity, $operation, $params = [], $value = NULL);

  /**
   * Check if a name or email field exists for this contact.
   * This determines whether a new contact can be created on the webform.
   *
   * @param $enabled
   *   Array of enabled fields
   * @param $c
   *   Contact #
   * @param $contact_type
   *   Contact type
   * @return int
   */
  function wf_crm_name_field_exists($enabled, $c, $contact_type);

  /**
   * At least one of these fields is required to create a contact
   *
   * @param string $contact_type
   * @return array of fields
   */
  function wf_crm_required_contact_fields($contact_type);

  /**
   * These are the contact location fields this module supports
   *
   * @return array
   */
  function wf_crm_location_fields();

  /**
   * These are the address fields this module supports
   *
   * @return array
   */
  function wf_crm_address_fields();

  /**
   * @param string
   * @return array
   */
  function wf_crm_explode_multivalue_str($str);

  /**
   * Check if value is a positive integer
   * @param mixed $val
   * @return bool
   */
  function wf_crm_is_positive($val);

  /**
   * Returns empty custom civicrm field sets
   *
   * @return array $sets
   */
  function wf_crm_get_empty_sets();

  /**
   * Pull custom fields to match with Webform element types
   *
   * @return array
   */
  function wf_crm_custom_types_map_array();

  /**
   * @param string $setting_name
   * @param mixed $default_value
   * @return mixed
   */
  function wf_crm_get_civi_setting($setting_name, $default_value = NULL);

  /**
   * Check if user checksum is available in the URL.
   * Set checksum user in the session.
   *
   * @param int $c
   * @param int $cid
   *
   * @return boolean
   *   TRUE if checksum is valid.
   */
  function checksumUserAccess($c, $cid);

  /**
   * Wrapper for all CiviCRM APIv4 calls
   *
   * @param string $entity
   *   API entity
   * @param string $operation
   *   API operation
   * @param array $params
   *   API params
   * @param string|int|array $index
   *   Controls the Result array format.
   *
   * @return array|\Civi\Api4\Generic\Result
   *   Result of API call
   */
  function wf_civicrm_api4($entity, $operation, $params, $index = NULL);

  /**
   * Check if logged in user or the checksum user
   * is allowed to view a contact.
   *
   * @param int $cid
   *
   * @return boolean
   *   TRUE if checksum user is allowed to view $cid.
   */
  function isContactAccessible($cid);

}
