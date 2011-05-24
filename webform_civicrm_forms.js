if (Drupal.jsEnabled) {
  $(document).ready( function(){

    $('#edit-nid').change(function(){
      if( $(this).is(':checked') ){
				$('#webform-civicrm-configure-form fieldset > div, #webform-civicrm-configure-form fieldset > fieldset, #edit-number-of-contacts-wrapper').not('.hidden').show(600);
      }else{
				$('#webform-civicrm-configure-form fieldset > div, #webform-civicrm-configure-form fieldset > fieldset, #edit-number-of-contacts-wrapper').not('.hidden').hide(600);
      }
    });

    $('#edit-toggle-message').change(function(){
      if( $(this).is(':checked')){
        $('#edit-message').removeAttr('disabled');
				$('#edit-message-wrapper').show(600).removeClass('hidden');
      }else{
         $('#edit-message').attr('disabled','disabled');
				$('#edit-message-wrapper').hide().addClass('hidden');
      }
    }).change();
		
		$('#edit-number-of-contacts').change(function(){
			$('#webform-civicrm-configure-form')[0].submit();
		});
		
		if(!$('#edit-nid').is(':checked')){
			$('#webform-civicrm-configure-form fieldset > div, #webform-civicrm-configure-form fieldset > fieldset, #edit-number-of-contacts-wrapper').not('.hidden').hide();
    }
  });
}
