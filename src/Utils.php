<?php

namespace Drupal\webform_civicrm;

use CRM_Core_Config;
use CRM_Core_DAO;
use CRM_Core_I18n;
use CRM_Utils_Array;
use CRM_Utils_System;

class Utils {

  /**
   * Explodes form key into an array and verifies that it is in the right format
   *
   * @param $key
   *   Webform component field key (string)
   *
   * @return array or NULL
   */
  public static function wf_crm_explode_key($key) {
    $pieces = explode('_', $key, 6);
    if (count($pieces) !== 6 || $pieces[0] !== 'civicrm') {
      return NULL;
    }
    return $pieces;
  }

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
  public static function wf_crm_field_options($field, $context, $data) {
    return \Drupal::service('webform_civicrm.field_options')->get($field, $context, $data);
  }

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
  public static function wf_crm_get_fields($var = 'fields') {
    return \Drupal::service('webform_civicrm.fields')->get($var);
  }

  /**
   * Get list of states, keyed by abbreviation rather than ID.
   * FIXME use the api for this.
   * @param null|int|string $param
   */
  public static function wf_crm_get_states($param = NULL) {
    $ret = array();
    if (!$param || $param === 'default') {
      $config = CRM_Core_Config::singleton();
      if (!$param && !empty($config->provinceLimit)) {
        $param = implode(',', $config->provinceLimit);
      }
      else {
        $param = (int) $config->defaultContactCountry;
      }
    }
    else {
      $param = (int) $param;
    }
    $sql = "SELECT name AS label, UPPER(abbreviation) AS value FROM civicrm_state_province WHERE country_id IN ($param) ORDER BY name";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $ret[$dao->value] = $dao->label;
    }
    // Localize the state/province names if in an non-en_US locale
    $tsLocale = CRM_Utils_System::getUFLocale();
    if ($tsLocale !== '' && $tsLocale !== 'en_US') {
      $i18n = CRM_Core_I18n::singleton();
      $i18n->localizeArray($ret, array('context' => 'province'));
      CRM_Utils_Array::asort($ret);
    }
    return $ret;
  }

}
