/**
 * Javascript Module for managing webform_civicrm options for select elements.
 */
(function (D, $, once) {
  function defaultBoxes(newType, defaultName) {
    var oldType = newType == 'radio' ? 'checkbox' : 'radio';
    var defaultValue = $('input[name*="[civicrm_defaults]"]:checked').val() || '';
    $('input:'+oldType+'[name*="[civicrm_defaults]"]').each(function() {
      var ele = $(this);
      var val = ele.attr('value');
      var classes = ele.attr('class');
      var id = ele.attr('id');
      if (newType == 'radio') {
        var name = defaultName + '[' + defaultValue + ']';
      }
      else {
        var name = defaultName + '[' + val + ']';
      }
      ele.replaceWith('<input type="'+newType+'" class="'+classes+'" id="'+id+'" name="'+name+'" value="'+val+'">');
    });
    if (defaultValue) {
      $('input:[name*="[civicrm_defaults]"][value="'+defaultValue+'"]').prop('checked', true);
    }
    $('input:checkbox.select-all-civi-defaults').change(function() {
      if ($(this).is(':checked')) {
        $('input.civicrm-default').not(':disabled').prop('checked', true);
      }
      else {
        $('input.civicrm-default, input.select-all-civi-defaults').prop('checked', false);
      }
    });
    $('input:radio[name*="[civicrm_defaults]"]').change(function() {
      if ($(this).is(':checked')) {
        $('input:radio[name*="[civicrm_defaults]"]').attr('name', defaultName + '[' + $(this).val() + ']');
      }
    });
  }

  D.behaviors.webform_civicrmOptions = {
    attach: function (context) {
      $(once('wf-civi', 'input.civicrm-enabled', context)).change(function() {
        if ($(this).is(':checked') ) {
          $(this).parents('tr').find('input.civicrm-label, input.civicrm-default').prop('disabled', false);
        }
        else {
          $(this).parents('tr').find('input.civicrm-label, input.civicrm-default').prop('disabled', true).prop('checked', false);
        }
        if ($(this).parents('tr').find('input.civicrm-label').val() == '') {
          var val = $(this).parents('tr').find('span.civicrm-option-name').text();
          $(this).parents('tr').find('input.civicrm-label').val(val);
        }
      }).change();

      var defaultName = 'civicrm_options_fieldset[civicrm_defaults]';

      var multiple = $('input[name="extra[multiple]"]');
      if (multiple.is(':checkbox')) {
        $(once('wf-civi', multiple)).change(function() {
          var type = $(this).is(':checked') ? 'checkbox' : 'radio';
          defaultBoxes(type, defaultName);
        }).change();
      }
      else if (multiple.attr('value') !== '1') {
        defaultBoxes('radio', defaultName);
      }
    }
  };
})(Drupal, jQuery, once);
