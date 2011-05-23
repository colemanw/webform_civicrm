if (Drupal.jsEnabled) {
  $(document).ready( function(){

    $('#edit-toggle-message').change( function(){
      if( $(this).is(':checked') ){
        $('#edit-message').removeAttr('disabled');
      }else{
         $('#edit-message').attr('disabled','disabled');
      }
    }).change();

  });
}
