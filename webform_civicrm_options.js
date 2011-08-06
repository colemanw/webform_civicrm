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
});