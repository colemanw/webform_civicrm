function webformCivicrmMasterId(n, c) {
  id = '#edit-civicrm-'+n+'-contact-'+c+'-address-master-id';

  switch (jQuery(id).val()) {
    case 'create_civicrm_webform_element':
    case '0':
      jQuery(id).parent().parent().find('input:checkbox').removeAttr('disabled');
      jQuery(id).parent().parent().find('div.form-type-checkbox').show(300);
      break;
    default:
      jQuery(id).parent().parent().find('input:checkbox').attr('disabled', 'disabled');
      jQuery(id).parent().parent().find('div.form-type-checkbox').hide(300);
  }
}

function webformCivicrmSelectReset(op, id) {
  switch (op) {
    case 'all':
      jQuery(id).find('input:checkbox').attr('checked', 'checked');
      jQuery(id).find('select[multiple] option, option[value="create_civicrm_webform_element"]').each(function() {
        jQuery(this).attr('selected', 'selected');
      });
      break;
    case 'none':
      jQuery(id).find('input:checkbox').attr('checked', '');
      jQuery(id).find('select:not([multiple])').each(function() {
        if (jQuery(this).val() === 'create_civicrm_webform_element') {
          jQuery(this).find('option').each(function() {
            jQuery(this).attr('selected', jQuery(this).attr('defaultSelected'));
          });
        }
        if (jQuery(this).val() === 'create_civicrm_webform_element') {
          jQuery(this).find('option:first-child+option').attr('selected', 'selected');
        }
      });
      jQuery(id).find('select[multiple] option').each(function() {
        jQuery(this).attr('selected', '');
      });
      break;
    case 'reset':
      jQuery(id).find('input:checkbox').each(function() {
        jQuery(this).attr('checked', jQuery(this).attr('defaultChecked'));
      });
      jQuery(id).find('select option').each(function() {
        jQuery(this).attr('selected', jQuery(this).attr('defaultSelected'));
      });
      break;
  }
}

function webformCivicrmParticipantConditional(fs) {
  var info = {
    roleid:jQuery(fs + ' .participant_role_id').val(),
    eventid:'0',
    eventtype:jQuery('#edit-reg-options-event-type').val()
  };
  var events = [];
  var i = 0;
  jQuery(fs + ' .participant_event_id :selected').each(function(a, selected) { 
    if (jQuery(selected).val() !== 'create_civicrm_webform_element') {
      events[i++] = jQuery(selected).val();
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

  jQuery(fs + ' fieldset.extends-condition').each(function() {
    var hide = true;
    classes = jQuery(this).attr('class').split(' ');
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
      jQuery(this).find(':checkbox').attr('disabled', 'disabled');
      jQuery(this).hide(300);
    }
    else {
      jQuery(this).find(':checkbox').removeAttr('disabled');
      jQuery(this).show(300);
    }
  });
}

(function ($) {
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
      $('select[id$=relationship-relationship-type-id]').each(function() {
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

  function webformCivicrmContactMatchCheckbox() {
    if($('#edit-1-contact-type').val() == 'individual') {
      $('#civi-contact-match-on').show();
      $('#civi-contact-match-off').hide();
    }
    else {
      $('#civi-contact-match-on').hide();
      $('#civi-contact-match-off').show();
    }
  }
  
  function webformCivicrmCheckLength(str) {
    str = Drupal.checkPlain(str);
    if (str.length > 45) {
      str = str.substr(0, 43) + '...';
    }
    return str;
  }
  
  /**
   * Summary for vertical tabs.
   */
  Drupal.behaviors.webform_civicrmFieldsetSummaries = {
    attach: function (context) {
      $('fieldset[id^="edit-contact-"]', context).drupalSetSummary(function (context) {
        var label = $('select[name$="_contact_type"] option:selected', context).text();
        if ($('select[name$="_contact_sub_type[]"]', context).val()) {
          var first = true;
          $('select[name$="_contact_sub_type[]"] option:selected', context).each(function() {
            label += (first ? ' (' : ', ') + $.trim($(this).text());
            first = false;
          });
          label += ')';
        }
        return label;
      });
      $('fieldset#edit-st-message', context).drupalSetSummary(function (context) {
        if ($('[name="toggle_message"]', context).attr('checked')) {
          return webformCivicrmCheckLength($('#edit-message', context).val());
        }
        else {
          return Drupal.t('- None -');
        }
      });
      $('fieldset#edit-prefix', context).drupalSetSummary(function (context) {
        var label = $('[name="prefix_known"]', context).val();
        if (!(label.length > 0)) {
          label = $('[name="prefix_unknown"]', context).val();
        }
        if (label.length > 0) {
          return webformCivicrmCheckLength(label);
        }
        else {
          return Drupal.t('- None -');
        }
      });
      $('fieldset#edit-event', context).drupalSetSummary(function (context) {
        return $('select[name="participant_reg_type"] option:selected', context).text();
      });
      $('fieldset#edit-act', context).drupalSetSummary(function (context) {
        var label = $('select[name="activity_type_id"] option:selected', context).text();
        if ($('select[name="case_type_id"] option:selected', context).val() > 0) {
          label = $('select[name="case_type_id"] option:selected', context).text() + ' ' + label;
        }
        return label;
      });
      $('fieldset#edit-options', context).drupalSetSummary(function (context) {
        var label = '';
        $(':checked', context).each(function() {
          label = (label ? label + ', ' : '') + $.trim($(this).siblings('label').text());
        });
        return label;
      });
    }
  };

  $(document).ready( function() {

    webformCivicrmContactMatchCheckbox();

    $('#edit-nid').change(function() {
      if ($(this).is(':checked')) {
        $('#webform-civicrm-configure-form .vertical-tabs').removeAttr('style');
        $('#webform-civicrm-configure-form .vertical-tabs-panes').removeClass('hidden');
      }
      else {
        $('#webform-civicrm-configure-form .vertical-tabs').css('opacity', '0.4');
        $('#webform-civicrm-configure-form .vertical-tabs-panes').addClass('hidden');
      }
    }).change();

    $('#edit-toggle-message').change(function() {
      if($(this).is(':checked')) {
        $('#edit-message').removeAttr('disabled');
      }
      else {
        $('#edit-message').attr('disabled','disabled');
      }
    }).change();

    $('select[id*=contact-type], select[id*=contact-sub-type]').change(function() {
      webform_civicrm_relationship_options();
    });

    $('select[id$=address-master-id]').change();

    $('#edit-number-of-contacts').change(function() {
      $('#webform-civicrm-configure-form')[0].submit();
    });

    $('#edit-1-contact-type').change(function() {
      webformCivicrmContactMatchCheckbox();
    });
  });
})(jQuery);
