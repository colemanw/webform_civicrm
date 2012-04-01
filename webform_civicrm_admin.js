function web_civi_master_id(n, c) {
  id = '#edit-civicrm-'+n+'-contact-'+c+'-address-master-id';
  switch ($(id).val()) {
    case 'create_civicrm_webform_element':
    case '0':
      $(id).parent().parent().find('input:checkbox').removeAttr('disabled');
      $(id).parent().parent().find('label.option').parent().show(300);
      break;
    default:
      $(id).parent().parent().find('input:checkbox').attr('disabled', 'disabled');
      $(id).parent().parent().find('label.option').parent().hide(300);
  }
}

function web_civi_select_reset(op, id) {
  switch (op) {
    case 'all':
      $(id).find('input:checkbox').attr('checked', 'checked');
      $(id).find('select[multiple] option, option[value="create_civicrm_webform_element"]').each(function() {
        $(this).attr('selected', 'selected');
      });
      break;
    case 'none':
      $(id).find('input:checkbox').attr('checked', '');
      $(id).find('select:not([multiple])').each(function() {
        if ($(this).val() === 'create_civicrm_webform_element') {
          $(this).find('option').each(function() {
            $(this).attr('selected', $(this).attr('defaultSelected'));
          });
        }
        if ($(this).val() === 'create_civicrm_webform_element') {
          $(this).find('option:first-child+option').attr('selected', 'selected');
        }
      });
      $(id).find('select[multiple] option').each(function() {
        $(this).attr('selected', '');
      });
      break;
    case 'reset':
      $(id).find('input:checkbox').each(function() {
        $(this).attr('checked', $(this).attr('defaultChecked'));
      });
      $(id).find('select option').each(function() {
        $(this).attr('selected', $(this).attr('defaultSelected'));
      });
      break;
  }
}

function web_civi_participant_conditional(fs) {
  var info = {
    roleid:$(fs + ' .participant_role_id').val(),
    eventid:'0',
    eventtype:$('#edit-reg-options-event-type').val()
  };
  var events = [];
  var i = 0;
  $(fs + ' .participant_event_id :selected').each(function(a, selected) { 
    if ($(selected).val() !== 'create_civicrm_webform_element') {
      events[i++] = $(selected).val();
    }
  });
  for (i in events) {
    var splitstr = events[i].split('-');
    if (events.length === 1) {
      info['eventid'] = splitstr[0];
    }
    if (i == 0) {
      info['eventtype'] = splitstr[1];
    }
    else if (info['eventtype'] !== splitstr[1]) {
      info['eventtype'] = '0';
    }
  }

  $(fs + ' fieldset.extends-condition').each(function(){
    var hide = true;
    classes = $(this).attr('class').split(' ');
    for (cl in classes) {
      var c = classes[cl].split('-');
      var type = c[0];
      if (type === 'roleid' || type === 'eventtype' || type === 'eventid') {
        for (cid in c) {
          if (c[cid] === info[type]) {
            hide = false;
          }
        }
        break;
      }
    }
    if (hide) {
      $(this).find(':checkbox').attr('disabled', 'disabled');
      $(this).hide(300);
    }
    else {
      $(this).find(':checkbox').removeAttr('disabled');
      $(this).show(300);
    }
  });
}

function webform_civicrm_relationship_options() {
  var contacts = $('#edit-number-of-contacts').val();
  if (contacts > 1) {
    var types = new Object();
    for (var c=1; c<=contacts; c++) {
      var sub_type = [];
      $('#edit-civicrm-'+c+'-contact-1-contact-contact-sub-type :selected').each(function(i, selected) { 
        if ($(selected).val() !== 'create_civicrm_webform_element') {
          sub_type[i] = $(selected).val();
        }
      });
      types[c] = {
            type: $('#edit-'+c+'-contact-type').val(),
        sub_type: sub_type,
      };
    }
    $('select[id$=relationship-relationship-type-id]').each(function(){
      var selected_option = $(this).val();
      var id = $(this).attr('id').split('-');
      var contact_a = types[id[2]];
      var contact_b = types[id[4]];
      $(this).find('option').not('[value="0"],[value="create_civicrm_webform_element"]').remove();
      for (var i in webform_civicrm_relationship_data) {
        var t = webform_civicrm_relationship_data[i];
        if ( (t['type_a'] == contact_a['type'] || !t['type_a'])
          && (t['type_b'] == contact_b['type'] || !t['type_b'])
          && ($.inArray(t['sub_type_a'], contact_a['sub_type']) > -1 || !t['sub_type_a'])
          && ($.inArray(t['sub_type_b'], contact_b['sub_type']) > -1 || !t['sub_type_b'])
        ) {
          $(this).append('<option value="'+t['id']+'_a">'+t['label_a_b']+'</option>');
        }
        if ( (t['type_a'] == contact_b['type'] || !t['type_a'])
          && (t['type_b'] == contact_a['type'] || !t['type_b'])
          && ($.inArray(t['sub_type_a'], contact_b['sub_type']) > -1 || !t['sub_type_a'])
          && ($.inArray(t['sub_type_b'], contact_a['sub_type']) > -1 || !t['sub_type_b'])
          && (t['name_a_b'] !== t['name_b_a'])
        ) {
          $(this).append('<option value="'+t['id']+'_b">'+t['label_b_a']+'</option>');
        }
      }
      if ($(this).find('option[value='+selected_option+']').size()) {
        $(this).val(selected_option);
      }
      else {
        $(this).val("0");
      }
    });
  }
}

function webform_civicrm_contact_match_checkbox(){
  if($('#edit-1-contact-type').val() == 'individual') {
    $('#civi-contact-match-on').show();
    $('#civi-contact-match-off').hide();
    $('#edit-contact-matching').removeAttr('disabled');
  }
  else {
    $('#civi-contact-match-on').hide();
    $('#civi-contact-match-off').show();
    $('#edit-contact-matching').attr('disabled', 'disabled');
  }
}

$(document).ready( function(){
  if(!$('#edit-nid').is(':checked')){
    $('#webform-civicrm-configure-form fieldset > div, #webform-civicrm-configure-form fieldset > fieldset, #edit-number-of-contacts-wrapper').not('.hidden').hide();
  }

  webform_civicrm_contact_match_checkbox();

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
    }else{
      $('#edit-message').attr('disabled','disabled');
    }
  }).change();

  $('#edit-number-of-contacts').change(function(){
    $('#webform-civicrm-configure-form')[0].submit();
  });

  $('select[id*=contact-type], select[id*=contact-sub-type]').change(function(){
    webform_civicrm_relationship_options();
  });

  $('#edit-1-contact-type').change(function(){
    webform_civicrm_contact_match_checkbox();
  });

  $('select[id$=address-master-id]').change();

  // D6 Can't handle dynamically generated ahah elements, so just refresh the form
  if ($('#edit-activity-type-id').val() == 0) {
    $('#edit-activity-type-id').unbind('change');
    $('#edit-activity-type-id').change(function(){
      $('#webform-civicrm-configure-form')[0].submit();
    });
  }

});
