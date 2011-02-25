if (Drupal.jsEnabled) {
  $(document).ready( function(){

    $('#edit-nid').change( function(){
      if( $(this).is(':checked') ){
        $('#webform-civicrm-configure-form fieldset div').show(600);
      }else{
        $('#webform-civicrm-configure-form fieldset div').hide(600);
      }
    }).change();

    $('#edit-toggle-message').change( function(){
      if( $(this).is(':checked') ){
        $('#edit-message').removeAttr('disabled');
      }else{
         $('#edit-message').attr('disabled','disabled');
      }
    }).change();

    $('#edit-activity-type-id').change( function(){
      if( $(this).val()==0 ){
        $('#edit-activity-subject').attr('disabled','disabled');
      }else{
         $('#edit-activity-subject').removeAttr('disabled');
      }
    }).change();

  });
}
