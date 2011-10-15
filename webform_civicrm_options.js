(function ($) {

  $(document).ready( function(){
    $('input.civicrm-enabled').change(function(){
      if( $(this).is(':checked') ){
        $(this).parents('tr').find('input.civicrm-label, input.civicrm-default').removeAttr('disabled');
      }else{
        $(this).parents('tr').find('input.civicrm-label, input.civicrm-default').attr('disabled', 'disabled').removeAttr('checked');
      }
      if ($(this).parents('tr').find('input.civicrm-label').val() == '') {
        var val = $(this).parents('tr').find('span.civicrm-option-name').text();
        $(this).parents('tr').find('input.civicrm-label').val(val);
      }
    }).change();

    $('input.select-all-civi-options').change(function(){
      if( $(this).is(':checked') ){
        $('input.civicrm-enabled, input.select-all-civi-options').attr('checked', 'checked');
      }else{
        $('input.civicrm-enabled, input.select-all-civi-options, input.select-all-civi-defaults').removeAttr('checked');
      }
      $('input.civicrm-enabled').change();
    });

    $('input.select-all-civi-defaults').change(function(){
      if( $(this).is(':checked') ){
        $('input.civicrm-default').attr('checked', 'checked');
      }else{
        $('input.civicrm-default, input.select-all-civi-defaults').removeAttr('checked');
      }
    });

  });
})(jQuery);
