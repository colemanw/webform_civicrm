
function webformCivicrmExistingSelect(num, nid, path, toHide, cid) {
  if (cid.length === 0) {
    webformCivicrmResetFields(num, nid, true, 'show', toHide, 500);
    return;
  }
  if (cid.charAt(0) === '-') {
    webformCivicrmResetFields(num, nid, true, 'show', toHide, 500);
    // Fill name fields with name typed
    if (cid.length > 1) {
      var names = {};
      s = cid.substr(1).split(' ');
      names['first'] = '';
      names['last'] = '';
      for (i in s) {
        str = s[i].substr(0,1).toUpperCase() + s[i].substr(1).toLowerCase();
        if (i < 1) {
          names['first'] = str;
        }
        else {
          names['last'] = names['last'] + (i > 1 ? ' ' : '') + str;
        }
      }
      names['organization'] = names['first'] + (names['last'] ? ' ' : '') + names['last'];
      names['household'] = names['organization'];
      for (i in names) {
        var field = jQuery('#webform-client-form-'+nid+' :input[id$="civicrm-'+num+'-contact-1-contact-'+i+'-name"]');
        if (field.length) {
          field.val(names[i]);
        }
      }
    }
    return;
  }
  jQuery('#webform-client-form-'+nid).css('cursor', 'progress');
  webformCivicrmResetFields(num, nid, true, 'hide', toHide, 500);
  jQuery.get(path, {cid: cid, load: 'full'}, function(data) {
    webformCivicrmFillValues(data, nid);
    jQuery('#webform-client-form-'+nid).removeAttr('style');
  }, 'json');
}

function webformCivicrmExistingInit(num, nid, path, toHide, selector) {
  var field = jQuery(selector);
  var ret = null;
  if (field.length) {
    var cid = field.attr('defaultValue');
    if (cid) {
      if (cid.charAt(0) !== '-') {
        webformCivicrmResetFields(num, nid, false, 'hide', toHide, 0);
      }
      if (cid == field.attr('data-civicrm-id')) {
        ret = [{id: cid, name: field.attr('data-civicrm-name')}];
      }
      else {
        // If for some reason the data is not embedded, fetch it from the server
        jQuery.ajax({
          url: path,
          data: {cid: cid, load: 'name'},
          dataType: 'json',
          async: false,
          success: function(data) {
            if (data) {
              ret = [{id: cid, name: data}];
            }
          }
        });
      }
    }
  }
  return ret;
}

function webformCivicrmResetFields(num, nid, clear, op, toHide, speed) {
  jQuery('#webform-client-form-'+nid+' div.form-item.webform-component[id*="civicrm-'+num+'-contact-"]').each(function() {
    var name = jQuery(this).attr('id');
    name = name.slice(name.lastIndexOf('civicrm-'));
    var n = name.split('-');
    if (n[0] === 'civicrm' && n[1] == num && n[2] === 'contact' && n[5] !== 'existing') {
      if (clear) {
        jQuery(this).find('input').not(':radio, :checkbox').val('');
        jQuery(this).find('input:checkbox, input:radio').each(function() {
          jQuery(this).attr('checked', jQuery(this).attr('defaultChecked'));
        });
        jQuery(this).find('select option').each(function() {
          jQuery(this).attr('selected', jQuery(this).attr('defaultSelected'));
        });
        // Trigger chain select when changing country
        if (n[5] === 'country') {
          jQuery(this).find('select.civicrm-processed').change();
        }
      }
      if (op === 'show') {
        jQuery(this).removeAttr('disabled').show(speed);
      }
      else {
        var type = (n[6] === 'name') ? 'name' : n[4];
        if (jQuery.inArray(type, toHide) >= 0) {
          jQuery(this).hide(speed).attr('disabled', 'disabled');
        }
      }
    }
  });
}

function webformCivicrmFillValues(data, nid) {
  for (fid in data) {
    // First try to find a single element - works for textfields and selects
    var ele = jQuery('#webform-client-form-'+nid+' :input.civicrm-processed[id$="'+fid+'"]');
    if (ele.length > 0) {
      // Trigger chain select when changing country
      if (fid.substr(fid.length - 10) === 'country-id') {
        if (ele.val() != data[fid]) {
          ele.val(data[fid]);
          webformCivicrmCountrySelect('#'+ele.attr('id'), data[fid.replace('country', 'state-province')]);
        }
      } 
      ele.val(data[fid]);
    }
    // Next go after the wrapper - for radios, dates & checkboxes
    else {
      var wrapper = jQuery('#webform-client-form-'+nid+' div.form-item.webform-component[id$="'+fid+'"]');
      if (wrapper.length > 0) {
        // Date fields
        if (wrapper.hasClass('webform-component-date')) {
          var val = data[fid].split('-');
          if (val.length === 3) {
            wrapper.find(':input[id$="year"]').val(val[0]);
            wrapper.find(':input[id$="month"]').val(parseInt(val[1], 10));
            wrapper.find(':input[id$="day"]').val(parseInt(val[2], 10));
          }
        }
        // Checkboxes & radios
        else {
          var val = jQuery.makeArray(data[fid]);
          for (i in val) {
            wrapper.find(':input[value="'+val[i]+'"]').attr('checked', 'checked');
          }
        }
      }
    }
  }
}

function webformCivicrmParseName(name) {
  var pos = name.lastIndexOf('[civicrm_');
  name = name.slice(1 + pos);
  pos = name.indexOf(']');
  if (pos !== -1) {
    name = name.slice(0, pos);
  }
  return name;
}

function webformCivicrmPopulateStates(stateSelect, countryId, stateVal) {
  jQuery(stateSelect).attr('disabled', 'disabled');
  if (stateProvinceCache[countryId]) {
    webformCivicrmFillOptions(stateSelect, stateProvinceCache[countryId], stateVal);
  }
  else {
    jQuery.get('/webform-civicrm/js/state_province/'+countryId, function(data) {
      webformCivicrmFillOptions(stateSelect, data, stateVal);
      stateProvinceCache[countryId] = data;
    }, 'json');
  }
}

function webformCivicrmFillOptions(element, data, value) {
  value = value || jQuery(element).val();
  jQuery(element).find('option').remove();
  var dataEmpty = true;
  var noCountry = false;
  for (key in data) {
    if (key === '') {
      noCountry = true;
    }
    dataEmpty = false;
    break;
  }
  if (!dataEmpty) {
    if (!noCountry) {
      if (jQuery(element).hasClass('required')) {
        var text = webformSelectSelect;
      }
      else {
        var text = webformSelectNone;
      }
      if (jQuery(element).hasClass('has-default')) {
        jQuery(element).removeClass('has-default');
      }
      else {
        jQuery(element).append('<option value="">'+text+'</option>');
      }
    }
    for (key in data) {
      jQuery(element).append('<option value="'+key+'">'+data[key]+'</option>');
    }
    jQuery(element).val(value);
  }
  else {
    jQuery(element).removeClass('has-default');
    jQuery(element).append('<option value="-">'+webformSelectNa+'</option>');
  }
  jQuery(element).removeAttr('disabled');
}

function webformCivicrmSharedAddress(item, action, speed) {
  var name = webformCivicrmParseName(jQuery(item).attr('name'));
  fields = jQuery(item).parents('form.webform-client-form').find('[name*="['+(name.replace('master_id', ''))+'"]').not(item).not('[name*=location_type_id]').not('[type="hidden"]');
  if (action === 'hide') {
    jQuery(fields).not(':hidden').parent().hide(speed);
    jQuery(fields).attr('disabled', 'disabled');
  }
  else {
    jQuery(fields).removeAttr('disabled');
    jQuery(fields).parent().show(speed);
  }
}

function webformCivicrmCountrySelect(ele, stateVal) {
  var name = webformCivicrmParseName(jQuery(ele).attr('name'));
  var countryId = jQuery(ele).val();
  var stateSelect = jQuery(ele).parents('form.webform-client-form').find('select.civicrm-processed[name*="['+(name.replace('country', 'state_province'))+']"]');
  if (stateSelect.length) {
    jQuery(stateSelect).val('');
    webformCivicrmPopulateStates(stateSelect, countryId, stateVal);
  }
}

jQuery(document).ready(function(){
  // Replace state/prov textboxes with dynamic select lists
  jQuery('form.webform-client-form').find('input.civicrm-processed[name*="_address_state_province_id"][type="text"]').each(function(){
    var id = jQuery(this).attr('id');
    var name = jQuery(this).attr('name');
    var key = webformCivicrmParseName(name);
    var value = jQuery(this).val();
    var countrySelect = jQuery(this).parents('form.webform-client-form').find('.civicrm-processed[name*="['+(key.replace('state_province','country' ))+']"]');
    var classes = jQuery(this).attr('class').replace('text', 'select');
    if (value !== '') {
      classes = classes + ' has-default';
    }
    jQuery(this).replaceWith('<select id="'+id+'" name="'+name+'" class="'+classes+'"><option selected="selected" value="'+value+'"> </option></select>');
    var countryVal = 'default';
    if (countrySelect.length === 1) {
      countryVal = jQuery(countrySelect).val();
    }
    else if (countrySelect.length > 1) {
      countryVal = jQuery(countrySelect).filter(':checked').val();
    }
    if (!countryVal) {
      countryVal = '';
    }
    webformCivicrmPopulateStates(jQuery('#'+id), countryVal);
  });

  // Add handler to country field to trigger ajax refresh of corresponding state/prov
  jQuery('form.webform-client-form .civicrm-processed[name*="_address_country_id]"]').change(function(){
    webformCivicrmCountrySelect(this);
  });

  // Show/hide address fields when sharing an address
  jQuery('form.webform-client-form .civicrm-processed[name*="_address_master_id"]').change(function(){
    if (jQuery(this).val() === '' || (jQuery(this).is(':checkbox:not(:checked)'))) {
      webformCivicrmSharedAddress(this, 'show', 500);
    }
    else {
      webformCivicrmSharedAddress(this, 'hide', 500);
    }
  });
  // Hide shared address fields on form load
  jQuery('form.webform-client-form select.civicrm-processed[name*="_address_master_id"], form.webform-client-form .civicrm-processed[name*="_address_master_id"]:checked').each(function() {
    if (jQuery(this).val() !== '') {
      webformCivicrmSharedAddress(this, 'hide');
    }
  });

});
