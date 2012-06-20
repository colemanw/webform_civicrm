/**
 * JS for CiviCRM-enabled webforms
 */

var wfCivi = (function ($, D) {
  /**
   * Public methods.
   */
  var pub = {};

  pub.existingSelect = function (num, nid, path, toHide, cid, fetch) {
    if (cid.charAt(0) === '-') {
      resetFields(num, nid, true, 'show', toHide, 500);
      // Fill name fields with name typed
      if (cid.length > 1) {
        var names = {first: '', last: ''};
        var s = cid.substr(1).split(' ');
        for (var i in s) {
          var str = s[i].substr(0,1).toUpperCase() + s[i].substr(1).toLowerCase();
          if (i < 1) {
            names.first = str;
          }
          else {
            names.last += (i > 1 ? ' ' : '') + str;
          }
        }
        names.organization = names.household = names.first + (names.last ? ' ' : '') + names.last;
        for (i in names) {
          var field = $('#webform-client-form-'+nid+' :input[id$="civicrm-'+num+'-contact-1-contact-'+i+'-name"]');
          if (field.length) {
            field.val(names[i]);
          }
        }
      }
      return;
    }
    resetFields(num, nid, true, 'hide', toHide, 500);
    if (cid && fetch) {
      $('#webform-client-form-'+nid).addClass('contact-loading');
      $.get(path, {cid: cid, load: 'full'}, function(data) {
        fillValues(data, nid);
        $('#webform-client-form-'+nid).removeClass('contact-loading');
      }, 'json');
    }
  };

  pub.existingInit = function (field, num, nid, path, toHide) {
    var ret = null;
    if (field.length) {
      if (field.is('select')) {
        var cid = $('option:selected', field).val();
      }
      else {
        var cid = field.attr('defaultValue');
      }
      if (!cid || cid.charAt(0) !== '-') {
        resetFields(num, nid, false, 'hide', toHide, 0);
      }
      if (cid) {
        if (cid == field.attr('data-civicrm-id')) {
          ret = [{id: cid, name: field.attr('data-civicrm-name')}];
        }
        else if (field.is(':text')) {
          // If for some reason the data is not embedded, fetch it from the server
          $.ajax({
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
  };

  /**
   * Private methods.
   */

  var stateProvinceCache = {};

  function resetFields(num, nid, clear, op, toHide, speed) {
    $('#webform-client-form-'+nid+' div.form-item.webform-component[id*="civicrm-'+num+'-contact-"]').each(function() {
      var ele = $(this);
      var name = ele.attr('id');
      name = name.slice(name.lastIndexOf('civicrm-'));
      var n = name.split('-');
      if (n[0] === 'civicrm' && n[1] == num && n[2] === 'contact' && n[5] !== 'existing') {
        if (clear) {
          $(':input', ele).not(':radio, :checkbox').val('');
          $('input:checkbox, input:radio', ele).each(function() {
            $(this).attr('checked', '');
          });
          // Trigger chain select when changing country
          if (n[5] === 'country') {
            $('select.civicrm-processed', ele).val(D.settings.webform_civicrm.defaultCountry).change();
          }
        }
        if (op === 'show') {
          $(':input', ele).removeAttr('disabled');
          ele.show(speed);
        }
        else {
          var type = (n[6] === 'name') ? 'name' : n[4];
          if ($.inArray(type, toHide) >= 0) {
            ele.hide(speed, function() {ele.css('display', 'none');});
            $(':input', ele).attr('disabled', 'disabled');
          }
        }
      }
    });
  }

  function fillValues(data, nid) {
    for (var fid in data) {
      // First try to find a single element - works for textfields and selects
      var ele = $('#webform-client-form-'+nid+' :input.civicrm-enabled[id$="'+fid+'"]');
      if (ele.length > 0) {
        // Trigger chain select when changing country
        if (fid.substr(fid.length - 10) === 'country-id') {
          if (ele.val() != data[fid]) {
            ele.val(data[fid]);
            countrySelect('#'+ele.attr('id'), data[fid.replace('country', 'state-province')]);
          }
        }
        ele.val(data[fid]);
      }
      // Next go after the wrapper - for radios, dates & checkboxes
      else {
        var wrapper = $('#webform-client-form-'+nid+' div.form-item.webform-component[id$="'+fid+'"]');
        if (wrapper.length > 0) {
          // Date fields
          if (wrapper.hasClass('webform-component-date')) {
            var val = data[fid].split('-');
            if (val.length === 3) {
              $(':input[id$="year"]', wrapper).val(val[0]);
              $(':input[id$="month"]', wrapper).val(parseInt(val[1], 10));
              $(':input[id$="day"]', wrapper).val(parseInt(val[2], 10));
            }
          }
          // Checkboxes & radios
          else {
            var val = $.makeArray(data[fid]);
            for (var i in val) {
              $(':input[value="'+val[i]+'"]', wrapper).attr('checked', 'checked');
            }
          }
        }
      }
    }
  }

  function parseName(name) {
    var pos = name.lastIndexOf('[civicrm_');
    name = name.slice(1 + pos);
    pos = name.indexOf(']');
    if (pos !== -1) {
      name = name.slice(0, pos);
    }
    return name;
  }

  function populateStates(stateSelect, countryId, stateVal) {
    $(stateSelect).attr('disabled', 'disabled');
    if (stateProvinceCache[countryId]) {
      fillOptions(stateSelect, stateProvinceCache[countryId], stateVal);
    }
    else {
      $.get(D.settings.webform_civicrm.callbackPath+'/'+countryId, function(data) {
        fillOptions(stateSelect, data, stateVal);
        stateProvinceCache[countryId] = data;
      }, 'json');
    }
  }

  function fillOptions(element, data, value) {
    value = value || $(element).val();
    $(element).find('option').remove();
    var dataEmpty = true;
    var noCountry = false;
    for (var key in data) {
      if (key === '') {
        noCountry = true;
      }
      dataEmpty = false;
      break;
    }
    if (!dataEmpty) {
      if (!noCountry) {
        if ($(element).hasClass('required')) {
          var text = D.t('- Select -');
        }
        else {
          var text = D.t('- None -');
        }
        if ($(element).hasClass('has-default')) {
          $(element).removeClass('has-default');
        }
        else {
          $(element).append('<option value="">'+text+'</option>');
        }
      }
      for (key in data) {
        $(element).append('<option value="'+key+'">'+data[key]+'</option>');
      }
      $(element).val(value);
    }
    else {
      $(element).removeClass('has-default');
      $(element).append('<option value="-">'+D.t('- N/A -')+'</option>');
    }
    $(element).removeAttr('disabled');
  }

  function sharedAddress(item, action, speed) {
    var name = parseName($(item).attr('name'));
    fields = $(item).parents('form.webform-client-form').find('[name*="['+(name.replace('master_id', ''))+'"]').not(item).not('[name*=location_type_id]').not('[type="hidden"]');
    if (action === 'hide') {
      fields.parent().hide(speed, function() {$(this).css('display', 'none');});
      fields.attr('disabled', 'disabled');
    }
    else {
      fields.removeAttr('disabled');
      fields.parent().show(speed);
    }
  }

  function countrySelect(ele, stateVal) {
    var name = parseName($(ele).attr('name'));
    var countryId = $(ele).val();
    var stateSelect = $(ele).parents('form.webform-client-form').find('select.civicrm-enabled[name*="['+(name.replace('country', 'state_province'))+']"]');
    if (stateSelect.length) {
      $(stateSelect).val('');
      populateStates(stateSelect, countryId, stateVal);
    }
  }

  D.behaviors.webform_civicrmForm = {
    attach: function (context) {
      if (!stateProvinceCache['default'] && D.settings.webform_civicrm) {
        stateProvinceCache['default'] = D.settings.webform_civicrm.defaultStates;
        stateProvinceCache[D.settings.webform_civicrm.defaultCountry] = D.settings.webform_civicrm.defaultStates;
        stateProvinceCache[''] = {'': D.settings.webform_civicrm.noCountry};
      }

      // Replace state/prov textboxes with dynamic select lists
      $(':text.civicrm-enabled[name*="_address_state_province_id"]', context).each(function(){
        var ele = $(this);
        var id = ele.attr('id');
        var name = ele.attr('name');
        var key = parseName(name);
        var value = ele.val();
        var countrySelect = ele.parents('form.webform-client-form').find('.civicrm-enabled[name*="['+(key.replace('state_province','country' ))+']"]');
        var classes = ele.attr('class').replace('text', 'select');
        if (value !== '') {
          classes = classes + ' has-default';
        }
        ele.replaceWith('<select id="'+id+'" name="'+name+'" class="'+classes+' civicrm-processed"><option selected="selected" value="'+value+'"> </option></select>');
        var countryVal = 'default';
        if (countrySelect.length === 1) {
          countryVal = $(countrySelect).val();
        }
        else if (countrySelect.length > 1) {
          countryVal = $(countrySelect).filter(':checked').val();
        }
        if (!countryVal) {
          countryVal = '';
        }
        populateStates($('#'+id), countryVal);
      });

      // Add handler to country field to trigger ajax refresh of corresponding state/prov
      $('form.webform-client-form .civicrm-enabled[name*="_address_country_id]"]').once('civicrm').change(function(){
        countrySelect(this);
      });

      // Show/hide address fields when sharing an address
      $('form.webform-client-form .civicrm-enabled[name*="_address_master_id"]').once('civicrm').change(function(){
        if ($(this).val() === '' || ($(this).is(':checkbox:not(:checked)'))) {
          sharedAddress(this, 'show', 500);
        }
        else {
          sharedAddress(this, 'hide', 500);
        }
      });
      // Hide shared address fields on form load
      $('form.webform-client-form select.civicrm-enabled[name*="_address_master_id"], form.webform-client-form .civicrm-enabled[name*="_address_master_id"]:checked').each(function() {
        if ($(this).val() !== '') {
          sharedAddress(this, 'hide');
        }
      });
    }
  };

  return pub;
})(jQuery, Drupal);
