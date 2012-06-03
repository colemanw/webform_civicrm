/**
 * Javascript Module for managing webform_civicrm options for select elements.
 */
(function ($, D) {
  function defaultBoxes(newType, defaultName) {
    oldType = newType == 'radio' ? 'checkbox' : 'radio';
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
      $('input:[name*="[civicrm_defaults]"][value="'+defaultValue+'"]').attr('checked', 'checked');
    }
    $('input:checkbox.select-all-civi-defaults').change(function() {
      if ($(this).is(':checked')) {
        $('input.civicrm-default').not(':disabled').attr('checked', 'checked');
      }
      else {
        $('input.civicrm-default, input.select-all-civi-defaults').removeAttr('checked');
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
      $('input.civicrm-enabled', context).once('wf-civi').change(function() {
        if ($(this).is(':checked') ) {
          $(this).parents('tr').find('input.civicrm-label, input.civicrm-default').removeAttr('disabled');
        }
        else {
          $(this).parents('tr').find('input.civicrm-label, input.civicrm-default').attr('disabled', 'disabled').removeAttr('checked');
        }
        if ($(this).parents('tr').find('input.civicrm-label').val() == '') {
          var val = $(this).parents('tr').find('span.civicrm-option-name').text();
          $(this).parents('tr').find('input.civicrm-label').val(val);
        }
      }).change();

      $('input.select-all-civi-options').once('wf-civi').change(function() {
        if ($(this).is(':checked') ) {
          $('input.civicrm-enabled, input.select-all-civi-options').attr('checked', 'checked');
        }
        else {
          $('input.civicrm-enabled, input.select-all-civi-options, input.select-all-civi-defaults').removeAttr('checked');
        }
        $('input.civicrm-enabled').change();
      });
      
      var defaultName = 'civicrm_options_fieldset[civicrm_defaults]';
      
      var multiple = $('#edit-extra-multiple:checkbox');
      if (multiple.length > 0) {
        multiple.once('wf-civi').change(function() {
          var type = $(this).is(':checked') ? 'checkbox' : 'radio';
          defaultBoxes(type, defaultName);
        }).change();
      }
      else {
        defaultBoxes('radio', defaultName);
      }
      
      $('input[name="extra[civicrm_live_options]"]').once('wf-civi').change(function() {
        if ($(this).is(':checked')) {
          switch ($(this).attr('value')) {
            case "0":
              $('.live-options-hide, .tabledrag-handle').show();
              $('.live-options-show').hide();
              break;
            case "1":
              $('.live-options-hide, .tabledrag-handle').hide();
              $('.live-options-show').show();
              $('input.civicrm-enabled, input.select-all-civi-options').attr('checked', 'checked');
              $('input.civicrm-enabled').change();
              break;
          }
        }
      }).change();
    }
  };
})(jQuery, Drupal);
