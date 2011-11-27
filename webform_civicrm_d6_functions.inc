<?php

/**
 * @file
 * Keep all functions specific to a version of Drupal here,
 * to allow the rest of the code to be version-independent.
 */

// FAPI items of type markup use '#value' in D6 and '#markup' in D7
define('WEBFORM_CIVICRM_MARKUP', '#value');


/**
 * Callback to return relevant portion of a form during AHAH processing.
 */
function webform_civicrm_ahah_callback($key) {
  $snippet = webform_civicrm_process_ahah_form();

  // Isolate the portion of form to fetch (nested array keys are encoded as a . separated string)
  $keys = explode('.', substr($key, 13));
  foreach($keys as $key) {
    $snippet = $snippet[$key];
  }
  unset($snippet['#prefix'], $snippet['#suffix']);
  $messages = theme('status_messages');
  $output = drupal_render($snippet);
  exit(drupal_json(array('data' => $output)));
}


/**
 * Loads a form during AHAH callback.
 * (This is part of Drupal API in D7)
 */
function webform_civicrm_process_ahah_form() {
  $form_state = array('storage' => NULL, 'submitted' => FALSE);
  $form_build_id = $_POST['form_build_id'];
  $form = form_get_cache($form_build_id, $form_state);
  $args = $form['#parameters'];
  $form_id = array_shift($args);
  $form_state['post'] = $form['#post'] = $_POST;
  // Enable the submit/validate handlers to determine whether AHAH-submittted.
  $form_state['ahah_submission'] = TRUE;
  $form['#programmed'] = $form['#redirect'] = FALSE;
  drupal_process_form($form_id, $form, $form_state);
  return drupal_rebuild_form($form_id, $form_state, $args, $form_build_id);
}


/**
 * Return FAPI class formatted for D6
 */
function webform_civicrm_fapi_class($str) {
  return $str;
}


/**
 * Compatibility wrapper for drupal_json_output()
 */
 function webform_civicrm_json($input) {
   exit(drupal_json($input));
 }


/**
 * Delete civicrm settings for a webform.
 */
function webform_civicrm_disable($nid) {
  db_query('DELETE FROM {webform_civicrm_forms} WHERE nid = %d', $nid);
}


/**
 * Compatibility wrapper for drupal_add_js()
 */
function webform_civicrm_add_js($js, $type = 'file', $scope = 'header') {
  if ($type == 'file') {
    $js = drupal_get_path('module', 'webform_civicrm') . '/' . $js;
  }
  drupal_add_js($js, $type, $scope);
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
  $pathstr = str_replace(':', '.', $pathstr) . '.' . $container;
  $id = 'civicrm-ajax-' . str_replace(array('.', '_'), '-', $pathstr);
  $snippet[$control_element]['#ahah'] = array(
    'path' => 'webform-civicrm/js/civicrm_ahah.' . $pathstr,
    'wrapper' => $id,
    'effect' => 'fade',
  );
  $snippet[$container] = array(
    '#prefix' => '<div class="civicrm-ajax-wrapper" id="' . $id . '">',
    '#value' => ' ',
    '#suffix' => '</div>',
  );
}


/**
 * Theme function to format civicrm options form as a table
 */
function theme_webform_civicrm_options($element) {
  if ($element['civicrm_defaults']['#type'] == 'checkboxes') {
    $default_box = '<input class="select-all-civi-defaults" type="checkbox" ' . (empty($element['civicrm_defaults']['#default_value']) ? '' : 'checked="checked"') . 'title="' . t('Select All') . '"> ';
  }
  else {
    $default_box = drupal_render($element['civicrm_defaults']['']);
  }
  $select_box = '<input class="select-all-civi-options" type="checkbox" checked="checked" title="' . t('Select All') . '"> ';

  if (empty($element['civicrm_options'])) {
    $header = array(t('Item'), $default_box . t('Selected'));
  }
  else {
    $header = array(
      t('Item'),
      t('Weight'),
      $select_box . t('Enabled'),
      t('Label'),
      $default_box . t('Default'),
    );
    drupal_add_tabledrag('civicrm-options-table', 'order', 'self', 'civicrm-option-weight');
  }
  $table = array();
  foreach (element_children($element['civicrm_defaults']) as $k) {
    if ($k) {
      $v = str_replace('_web_civi_option_selected_', '', $k);
      $row = array(drupal_render($element['civicrm_option_name_' . $v]));
      if (!empty($element['civicrm_options'])) {
        $element['civicrm_option_weight_' . $v]['#attributes']['class'] = 'civicrm-option-weight';
        $element['civicrm_options'][$k]['#attributes']['class'] = 'civicrm-enabled';
        $element['civicrm_option_label_' . $v]['#attributes']['class'] = 'civicrm-label';
        $row[] = drupal_render($element['civicrm_option_weight_' . $v]);
        $row[] = drupal_render($element['civicrm_options'][$k]);
        $row[] = drupal_render($element['civicrm_option_label_' . $v]);
      }
      $element['civicrm_defaults'][$k]['#attributes']['class'] = 'civicrm-default';
      $row[] = drupal_render($element['civicrm_defaults'][$k]);
      $table[] = array(
        'data' => $row,
        'class' => 'draggable',
      );
    }
  }
  drupal_render($element);
  $output = theme_table($header, $table, array('id' => 'civicrm-options-table'));
  // Disable drupal sticky headers because they cause invalid markup
  return str_replace('<thead>', '<thead class="tableHeader-processed">', $output);
}
