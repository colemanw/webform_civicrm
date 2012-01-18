<?php

/**
 * @file
 * Keep all functions specific to a version of Drupal here,
 * to allow the rest of the code to be version-independent.
 */

// FAPI items of type markup use '#value' in D6 and '#markup' in D7
define('WEBFORM_CIVICRM_MARKUP', '#markup');
// FAPI form_state uses 'post' in D6 and 'input' in D7
define('WEBFORM_CIVICRM_FAPI_INPUT', 'input');

/**
 * Return FAPI class formatted for D7
 */
function webform_civicrm_fapi_class($str) {
  return explode(' ', $str);
}


/**
 * Compatibility wrapper for drupal_json_output()
 */
 function webform_civicrm_json($input) {
   exit(drupal_json_output($input));
 }


/**
 * Delete civicrm settings for a webform.
 */
function webform_civicrm_disable($nid) {
  db_delete('webform_civicrm_forms')
    ->condition('nid', $nid)
    ->execute();
}


/**
 * Compatibility wrapper for drupal_add_js()
 */
function webform_civicrm_add_js($js, $type = 'file', $scope = 'header') {
  if ($type == 'file') {
    $js = drupal_get_path('module', 'webform_civicrm') . '/' . $js;
  }
  drupal_add_js($js, array('type' => $type, 'scope' => $scope));
}


/**
 * Add an ajax container to a form, and set an existing form element to control it
 * @param $form: the entire form
 * @param $pathstr: a : separated string of nested array keys leading to the relevant form snippet
 * @param $control_element: str. Array key of the existing element to add ajax behavior to
 * @param $container: str. Array key of the container to be created
 */
function webform_civicrm_ajax_form_item(&$form, $pathstr, $control_element, $container) {
  eval('$snippet = &$form[\'' . str_replace(':', "']['", $pathstr) . "'];");
  $pathstr .= ':' . $container;
  $id = 'civicrm-ajax-' . str_replace(array(':', '_'), '-', $pathstr);
  $snippet[$control_element]['#ajax'] = array(
    'callback' => 'webform_civicrm_configure_form_ajax',
    'pathstr' => $pathstr,
    'wrapper' => $id,
    'effect' => 'fade',
  );
  $snippet[$container] = array(
    '#prefix' => '<div class="civicrm-ajax-wrapper" id="' . $id . '">',
    '#type' => 'markup',
    '#suffix' => '</div>',
  );
}


/**
 * Fetch the path of a file
 * And format it as a public url that CiviCRM can read
 */
function webform_civicrm_filepath($id) {
  if ($file = file_load($id)) {
    global $base_url;
    return str_replace('public://', $base_url . '/' . variable_get('file_public_path', conf_path() . '/files') . '/', str_replace(array('%3A', '%2F'), array(':', '/'), rawurlencode($file->uri)));
  }
}


/**
 * Theme function to format civicrm options form as a table
 */
function theme_webform_civicrm_options($variables) {
  $element = $variables['element'];
  if ($element['civicrm_defaults']['#type'] == 'checkboxes') {
    $default_box = '<input class="select-all-civi-defaults" type="checkbox" ' . (empty($element['civicrm_defaults']['#default_value']) ? '' : 'checked="checked"') . 'title="' . t('Select All') . '"> ';
  }
  else {
    $default_box = drupal_render($element['civicrm_defaults']['']);
  }
  $select_box = '<input class="select-all-civi-options" type="checkbox" checked="checked" title="' . t('Select All') . '"> ';
  $table = array(
    'rows' => array(),
    'attributes' => array('id' => 'civicrm-options-table'),
    'sticky' => FALSE,
  );
  if (empty($element['civicrm_options'])) {
    $table['header'] = array(t('Item'), $default_box . t('Selected'));
  }
  else {
    $table['header'] = array(
      t('Item'),
      t('Weight'),
      $select_box . t('Enabled'),
      t('Label'),
      $default_box . t('Default'),
    );
    drupal_add_tabledrag('civicrm-options-table', 'order', 'self', 'civicrm-option-weight');
  }
  foreach (element_children($element['civicrm_defaults']) as $k) {
    if ($k) {
      $v = str_replace('_web_civi_option_selected_', '', $k);
      $row = array(drupal_render($element['civicrm_option_name_' . $v]));
      if (!empty($element['civicrm_options'])) {
        $element['civicrm_option_weight_' . $v]['#attributes']['class'] = webform_civicrm_fapi_class('civicrm-option-weight');
        $element['civicrm_options'][$k]['#attributes']['class'] = webform_civicrm_fapi_class('civicrm-enabled');
        $element['civicrm_option_label_' . $v]['#attributes']['class'] = webform_civicrm_fapi_class('civicrm-label');
        $row[] = drupal_render($element['civicrm_option_weight_' . $v]);
        $row[] = drupal_render($element['civicrm_options'][$k]);
        $row[] = drupal_render($element['civicrm_option_label_' . $v]);
      }
      $element['civicrm_defaults'][$k]['#attributes']['class'] = webform_civicrm_fapi_class('civicrm-default');
      $row[] = drupal_render($element['civicrm_defaults'][$k]);
      $table['rows'][] = array(
        'data' => $row,
        'class' => webform_civicrm_fapi_class('draggable'),
      );
    }
  }
  drupal_render_children($element);
  return theme('table', $table);
}
