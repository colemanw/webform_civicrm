$(document).ready( function(){
  $('input.civicrm-enabled').change(function(){
    if( $(this).is(':checked') ){
      $(this).parents('tr').find('.civicrm-label, .civicrm-default').removeAttr('disabled');
    }else{
      $(this).parents('tr').find('.civicrm-label, .civicrm-default').attr('disabled', 'disabled');
    }
  }).change();
});