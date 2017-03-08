<?php

function _webform_edit_civipostcode($component) { 
  $form = array();

  $form['#attached']['js'][] = wf_crm_tokeninput_path();
  $form['#attached']['js'][] = drupal_get_path('module', 'webform_civicrm') . '/js/civipostcode_component.js';
  return $form;
}

function _webform_defaults_civipostcode() {
  return array(
    'name' => '',
    'form_key' => NULL,
    'pid' => 0,
    'weight' => 0,
    'value' => '',
    'mandatory' => 0,
    'extra' => array(
      'widget' => 'textfield',
      'attributes' => array(),
      'title_display' => 'before',
    ),
  );
}

function _webform_display_civipostcode($component, $value, $format = 'html') {
  return array(
    '#title' => $component['extra']['field_prefix'] .' / '. $component['extra']['field_suffix'],
    '#weight' => $component['weight'],
    '#theme' => 'webform_display_civipostcode',
    '#theme_wrappers' => $format == 'html' ? array('webform_element') : array('webform_element_text'),
    '#format' => $format,
    '#value' => isset($value[0]) ? $value[0] : '',
    '#translatable' => array('title', 'field_prefix', 'field_suffix'),
  );
}

function _webform_render_civipostcode($component, $value = NULL, $filter = TRUE) {

   $element = array(
    '#type' => 'textfield',
    '#title' => $filter ? webform_filter_xss($component['name']) : $component['name'],
    '#title_display' => $component['extra']['title_display'] ? $component['extra']['title_display'] : 'before',
    '#required' => $component['required'],
    '#weight' => $component['weight'],
    '#attributes' => array(),
    '#theme_wrappers' => array('webform_element'),
  );

  return $element;
}