<?php

namespace Drupal\webform_civicrm;

interface ContactComponentInterface {

  /**
   * Format filters for the contact get api
   *
   * @param \Drupal\webform\WebformInterface $node
   *   Webform node object
   * @param array $component
   *   Webform component of type 'civicrm_contact'
   *
   * @return array
   *   Api params
   */
  function wf_crm_search_filters($node, array $component);

  /**
   * Get a contact's relations of certain types
   *
   * @param int cid
   *   Contact id
   * @param array types
   *   Array of relationship_type_ids
   * @param bool $current
   *   Limit to current & enabled relations?
   *
   * @return array
   */
  function wf_crm_find_relations($cid, $types = [], $current = TRUE);

  /**
   * Load contact name if user has permission. Else return FALSE.
   *
   * @param $component
   *   Webform component of type 'civicrm_contact'
   * @param $filters
   *   Contact get params
   * @param $cid
   *   Contact id
   *
   * @return bool|string
   */
  function wf_crm_contact_access($component, $filters, $cid);

  /**
   * Returns a list of contacts based on component settings.
   *
   * @param \Drupal\webform\WebformInterface $node
   *   Node object
   * @param array $component
   *   Webform component
   * @param array $params
   *   Contact get params (filters)
   * @param array $contacts
   *   Existing contact data
   * @param string $str
   *   Search string (used during autocomplete)
   *
   * @return array
   */
  function wf_crm_contact_search($node, $component, $params, $contacts, $str = NULL);

}
