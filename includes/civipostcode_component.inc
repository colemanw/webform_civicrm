<?php
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
    '#title' => $component['name'],
    '#weight' => $component['weight'],
    '#theme' => 'webform_display_textfield',
    '#theme_wrappers' => $format == 'html' ? array('webform_element') : array('webform_element_text'),
    '#format' => $format,
    '#value' => isset($value[0]) ? $value[0] : '',
    '#translatable' => array('title'),
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

  // Preseve value during pagebreaks
  if (isset($value)) {
    $element['#default_value'] = $value[0];
  }
  return $element;
}
