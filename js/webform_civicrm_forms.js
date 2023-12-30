/**
 * JS for CiviCRM-enabled webforms
 */

var wfCivi = (function (D, $, drupalSettings, once) {
  'use strict';
  var setting = drupalSettings.webform_civicrm;
  /**
   * Public methods.
   */
  var pub = {};

  pub.existingSelect = function (num, nid, path, toHide, hideOrDisable, showEmpty, cid, fetch, defaults) {
    var formClass = getFormClass(nid);
    var defaults = $(formClass).data('form-defaults') || {};

    if (cid.charAt(0) === '-') {
      resetFields(num, nid, true, 'show', toHide, hideOrDisable, showEmpty, 500, defaults);
      // Fill name fields with name typed
      if (cid.length > 1) {
        var names = {first: '', last: ''};
        var s = cid.substr(1).replace(/%/g, ' ').split(' ');
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
          $(':input[name$="civicrm_' + num + '_contact_1_contact_' + i + '_name"]', formClass).val(names[i]);
        }
      }
      return;
    }
    resetFields(num, nid, true, 'hide', toHide, hideOrDisable, showEmpty, 500, defaults);
    if (cid && fetch) {
      $(formClass).addClass('contact-loading');
      var params = getCids(nid);
      params.load = 'full';
      params.cid = cid;
      $.getJSON(path, params, function(data) {
        fillValues(data, nid);
        resetFields(num, nid, false, 'hide', toHide, hideOrDisable, showEmpty);
        $(formClass).removeClass('contact-loading');
      });
    }
  };

  pub.existingInit = function ($field, num, nid, path, toHide, tokenInputSettings) {
    var cid = $field.val(),
      prep = null,
      hideOrDisable = $field.attr('data-hide-method'),
      showHiddenContact = $field.attr('show-hidden-contact') == '1',
      showEmpty = $field.attr('data-no-hide-blank') == '1';

    function getCallbackPath() {
      return path + (path.indexOf('?') < 0 ? '?' : '&') + $.param(getCids(nid));
    }

    if ($field.length) {
      if ($field.is('[type=hidden]') && !cid) {
        return;
      }
      if (!cid || cid.charAt(0) !== '-') {
        resetFields(num, nid, false, 'hide', toHide, hideOrDisable, showEmpty);
      }
      if (cid) {
        if (cid == $field.attr('data-civicrm-id')) {
          prep = [{id: cid, name: $field.attr('data-civicrm-name')}];
        }
        else if (tokenInputSettings) {
          // If for some reason the data is not embedded, fetch it from the server
          $.ajax({
            url: path,
            data: {cid: cid, load: 'name'},
            dataType: 'json',
            async: false,
            success: function(data) {
              if (data) {
                prep = [{id: cid, name: data}];
              }
            }
          });
        }
      }
      if ($field.is('[type=hidden]') && !showHiddenContact) {
        return;
      }
      if (tokenInputSettings) {
        tokenInputSettings.queryParam = 'str';
        tokenInputSettings.tokenLimit = 1;
        tokenInputSettings.prePopulate = prep;
        if (showHiddenContact) {
          tokenInputSettings.deleteText = '';
        }
        $field.tokenInput(getCallbackPath, tokenInputSettings);
      }
    }
  };

  pub.initFileField = function(field, info) {
    info = info || {};
    var element = 'div#edit-' + field.replace(/_/g, '-') + '.civicrm-enabled';
    var container = $(element.toLowerCase());
    if (container.length > 0) {
      if ($('.file', container).length > 0) {
        if ($('.file', container).is(':visible')) {
          $('.file', container).hide();
        }
        else {
          return;
        }
      }
      else {
        $(container).children().hide();
        container.append('<input type="submit" class="button form-submit ajax-processed civicrm-remove-file" value="' + Drupal.t('Change') + '" onclick="wfCivi.clearFileField(\'' + field + '\'); return false;">');
      }
      container.prepend('<span class="file civicrm-file-icon file--'+info.icon+'">' + (info.name ? ('<a href="'+ info.file_url+ '" target="_blank">'+info.name +'</a>') : '') + '</span>');
    }
  };

  pub.clearFileField = function(field) {
    var element = 'div#edit-' + field.replace(/_/g, '-') + '.civicrm-enabled';
    var container = $(element.toLowerCase());
    $('.civicrm-remove-file, .civicrm-file-icon', container).remove();
    $(container).children().show();
  };

  /**
   * Private methods.
   */

  var stateProvinceCache = {};

  function getFormClass(webformId) {
    return '.webform-submission-' + webformId.toString().replace(/_/g, '-') + '-form';
  }

  function resetFields(num, nid, clear, op, toHide, hideOrDisable, showEmpty, speed, defaults) {
    var formClass = getFormClass(nid);
    $('div.form-item[class*="-civicrm-'+num+'-contact-"]', formClass).each(function() {
      var $el = $(this);
      var name = getFieldNameFromClass($el);
      if (!name) {
        return;
      }
      var n = name.split('-');
      if (n[0] === 'civicrm' && parseInt(n[1]) == num && n[2] === 'contact' && n[5] !== 'existing') {
        if (clear) {
          var $wrapper = $(formClass +' div.form-item[class*="-'+(name.replace(/_/g, '-'))+'"]');
          // Reset country to default
          if (n[5] === 'country') {
            $('select.civicrm-processed', this).val(setting.defaultCountry).trigger('change', 'webform_civicrm:reset');
          }
          //Set default value if it is specified in component settings.
          else if ($wrapper.length && $wrapper.is('[class*="form-type-date"]')) {
            if (typeof defaults != "undefined" && defaults.hasOwnProperty(name)) {
              var date = defaults[name].split('-');
              $(':input[id$="year"]', $wrapper).val(date[0]).trigger('change', 'webform_civicrm:autofill');
              $(':input[id$="month"]', $wrapper).val(parseInt(date[1], 10)).trigger('change', 'webform_civicrm:autofill');
              $(':input[id$="day"]', $wrapper).val(parseInt(date[2], 10)).trigger('change', 'webform_civicrm:autofill');
            }
            else {
              $(':input', this).val('').trigger('change', 'webform_civicrm:reset');;
            }
          }
          else {
            $(':input', this).not(':radio, :checkbox, :button, :submit, :file, .form-file').each(function() {
              if (this.id && $(this).val() != '') {
                (typeof defaults != "undefined" && defaults.hasOwnProperty(name)) ? $(this).val(defaults[name]) : $(this).val('');
                $(this).trigger('change', 'webform_civicrm:reset');
              }
            });
            $('.civicrm-remove-file', this).click();
            $('input:checkbox, input:radio', this).each(function() {
              $(this).prop('checked', false).trigger('change', 'webform_civicrm:reset');
            });
          }
        }
        var type = (n[6] === 'name') ? 'name' : n[4];
        if ($.inArray(type, toHide) >= 0) {
          var fn = (op === 'hide' && (!showEmpty || !isFormItemBlank($el))) ? 'hide' : 'show';
          $(':input', $el).prop('disabled', fn === 'hide');
          $(':input', $el).prop('readonly', fn === 'hide');
          if (hideOrDisable === 'hide') {
            $el[fn](speed, function() {$el[fn];});
          }
        }
      }
    });
  }

  function isFormItemBlank($el) {
    var isBlank = true;
    if ($(':input:checked', $el).length) {
      return false;
    }
    $(':input', $el).not(':radio, :checkbox, :button, :submit').each(function() {
      if ($(this).val()) {
        isBlank = false;
      }
    });
    return isBlank;
  }

  function getFieldNameFromClass($el) {
    var name = false;
    $.each($el.attr('class').split(' '), function(k, val) {
      if (val.indexOf('-civicrm') > 0) {
        val = val.substring(val.lastIndexOf('-civicrm') + 1);
        if (val.indexOf('fieldset') < 0) {
          if (val.indexOf('custom-') != -1 && val.lastIndexOf('-year') != -5) {
            name = val.replace('-year', '');
          }
          else {
            name = val;
          }
        }
      }
    });
    return name;
  }

  function fillValues(data, nid) {
    var formClass = getFormClass(nid);
    $.each(data, function() {
      var fid = this.fid,
        val = this.val;
      // Handle file fields
      if (this.data_type === 'File') {
        pub.initFileField(fid, this);
        return;
      }
      var $wrapper = $(formClass + ' div.form-item[class*="-' + (fid.replace(/_/g, '-')) + '"]');
      if (this.data_type === 'Date') {
        var vals = val.split(' ');
        var $date_el = $('input[name="' + fid + '[date]"]', $wrapper);
        var $time_el = $('input[name="' + fid + '[time]"]', $wrapper);
        if ($date_el.length) {
          $date_el.val(vals[0]).trigger('change', 'webform_civicrm:autofill');
          $time_el.val(vals[1]).trigger('change', 'webform_civicrm:autofill');
        }
        else {
          var date = val.split('-');
          if (date.length === 3) {
            $(':input[id$="year"]', $wrapper).val(date[0]).trigger('change', 'webform_civicrm:autofill');
            $(':input[id$="month"]', $wrapper).val(parseInt(date[1], 10)).trigger('change', 'webform_civicrm:autofill');
            $(':input[id$="day"]', $wrapper).val(parseInt(date[2], 10)).trigger('change', 'webform_civicrm:autofill');
          }
        }
        return;
      }
      // First try to find a single element - works for textfields and selects
      var $el = $(formClass +' :input.civicrm-enabled[name$="'+fid+'"]').not(':checkbox, :radio');
      if ($el.length) {
        // For chain-select fields, store value for later if it's not available
        if ((fid.substr(fid.length - 9) === 'county_id' || fid.substr(fid.length - 11) === 'province_id') && !$('option[value='+val+']', $el).length) {
          $el.attr('data-val', val);
        }
        else if ($el.val() !== val) {
          if ($el.data('tokenInputObject')) {
            $el.tokenInput('clear').tokenInput('add', {id: val, name: this.display});
          }
          else if ($el.is('[type=hidden]')) {
            $el.parents('.token-input-list').find('p').text(this.display);
          }
          $el.val(val).trigger('change', 'webform_civicrm:autofill');
        }
      }
      // Next go after the wrapper - for radios & checkboxes
      else {
        $.each($.makeArray(val), function(k, v) {
          $('input[value="' + v + '"]', $wrapper).prop('checked', true).trigger('change', 'webform_civicrm:autofill');
        });
        $('input[type="checkbox"]:first-child', $wrapper).removeAttr('required');
      }
    });
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

  function populateStates(stateSelect, countryId) {
    $(stateSelect).prop('disabled', true);
    if (stateProvinceCache[countryId]) {
      fillOptions(stateSelect, stateProvinceCache[countryId]);
    }
    else {
      $.getJSON(setting.callbackPath+'/stateProvince/' + countryId, function(data) {
        fillOptions(stateSelect, data);
        stateProvinceCache[countryId] = data;
        sameBillingAddress(true);
      });
    }
  }

  function populateCounty() {
    var
      stateSelect = $(this),
      key = parseName(stateSelect.attr('name')),
      countryId = stateSelect.parents('form').find('.civicrm-enabled[name*="'+(key.replace('state_province', 'country'))+'"]').val(),
      countySelect = stateSelect.parents('form').find('.civicrm-enabled[name*="'+(key.replace('state_province','county' ))+'"]'),
      stateVal = stateSelect.val();
    if (countySelect.length) {
      if (!stateVal) {
        fillOptions(countySelect, {'': Drupal.t('- First Choose a State -')});
      }
      else if (stateVal === '-') {
        fillOptions(countySelect, null);
      }
      else {
        $.getJSON(setting.callbackPath+'/county/'+stateVal+'-'+countryId, function(data) {
          fillOptions(countySelect, data);
        });
      }
    }
  }

  function fillOptions(element, data) {
    var sortedData = Object.entries(data).sort(([,a],[,b]) => a > b);
    var $el = $(element),
      value = $el.attr('data-val') ? $el.attr('data-val') : $el.val();
    $el.find('option').remove();
    if (!sortedData.length == 0) {
      if (!data['']) {
        var text = $el.hasClass('required') ? Drupal.t('- Select -') : Drupal.t('- None -');
        $el.append('<option value="">'+text+'</option>');
      }
      for (let i = 0; i < sortedData.length; i++) {
        $el.append('<option value="'+sortedData[i][0]+'">'+sortedData[i][1]+'</option>');
        if (sortedData[i][0] == value) {
          $el.val(value);
        }
      };
    }
    else {
      $el.append('<option value="-">'+Drupal.t('- N/A -')+'</option>');
    }
    $el.removeAttr('disabled').trigger('change', 'webform_civicrm:chainselect');
  }

  function sharedAddress(item, action, speed) {
    var name = parseName($(item).attr('name'));
    var fields = $(item).parents('form.webform-submission-form').find('[name*="'+(name.replace(/master_id.*$/, ''))+'"]').not('[name*=location_type_id]').not('[name*=master_id]').not('[type="hidden"]');
    if (action === 'hide') {
      fields.parent().hide(speed, function() {$(this).css('display', 'none');});
      fields.prop('disabled', true);
    }
    else {
      fields.removeAttr('disabled');
      fields.parent().show(speed);
    }
  }

  /**
   * Copy Values from Contact 1 Address fields to billing address.
   *
   * @param bool state_only
   *  true, if only state field needs to be populated.
   */
  function sameBillingAddress(state_only = false) {
    if ($('input[name="civicrm_1_contribution_1_contribution_billing_address_same_as"]').length && $('input[name="civicrm_1_contribution_1_contribution_billing_address_same_as"]').is(':checked')) {
      // Address fields are on different pages.
      if (typeof setting.billing_values != "undefined") {
        $.each(setting.billing_values, function(k, v) {
          if (state_only && k == 'state_province_id') {
            $('[name=civicrm_1_contribution_1_contribution_billing_address_' + k + ']').val(v);
          }
          else if (!state_only) {
            $('[name=civicrm_1_contribution_1_contribution_billing_address_' + k + ']').val(v).change();
          }
        });
      }
      else {
        // Address fields are on same page.
        var billing_fields = state_only ? ['state_province_id'] : ['street_address', 'city', 'postal_code', 'state_province_id', 'country_id', 'first_name', 'middle_name', 'last_name'];
        $.each(billing_fields, function(key, field_name) {
          if ($('[name=civicrm_1_contact_1_address_' + field_name).length > 0) {
            var v = (key < 5) ? $('[name=civicrm_1_contact_1_address_' + field_name).val() : $('[name=civicrm_1_contact_1_contact_' + field_name).val();
            if (state_only && field_name == 'state_province_id') {
              $('[name=civicrm_1_contribution_1_contribution_billing_address_' + field_name + ']').val(v);
            }
            else if (!state_only) {
              $('[name=civicrm_1_contribution_1_contribution_billing_address_' + field_name + ']').val(v).change();
            }
          }
        });
      }
    }
  }

  function countrySelect() {
    var name = parseName($(this).attr('name'));
    var countryId = $(this).val();
    var stateSelect = $(this).parents('form.webform-submission-form').find('select.civicrm-enabled[name*="'+name.replace('country', 'state_province')+'"]');
    if (stateSelect.length) {
      populateStates(stateSelect, countryId);
    }
  }

  function getCids(nid) {
    var formClass = getFormClass(nid);
    var cids = $(formClass).data('civicrm-ids') || {};
    $(formClass + ' .civicrm-enabled:input[name$="_contact_1_contact_existing"]').each(function() {
      var cid = $(this).val();
      if (cid) {
        var n = parseName($(this).attr('name')).split('_');
        cids['cid' + n[1]] = cid;
      }
    });
    return cids;
  }

  D.behaviors.webform_civicrmForm = {
    attach: function (context) {
      if (!stateProvinceCache['default'] && setting) {
        stateProvinceCache['default'] = setting.defaultStates;
        stateProvinceCache[setting.defaultCountry] = setting.defaultStates;
        stateProvinceCache[''] = {'': setting.noCountry};
      }

      // Replace state/prov & county with dynamic select lists
      $('select.civicrm-enabled[name*="_address_state_province_id"]', context).each(function() {
        var $el = $(this);
        var $form = $el.parents('form');
        var key = parseName($el.attr('name'));
        var countrySelectKey = key.replace('state_province', 'country');

        var countrySelect = $form.find('.civicrm-enabled[name*="'+ countrySelectKey +'"]');
        var $county = $form.find('.civicrm-enabled[name*="'+(key.replace('state_province', 'county'))+'"]');

        var readOnly = $el.attr('readonly');

        if ($county.length && !$county.attr('readonly')) {
          $el.change(populateCounty);
        }

        var countryVal = 'default';
        if (countrySelect.length === 1) {
          countryVal = $(countrySelect).val();
        }
        else if (countrySelect.length > 1) {
          countryVal = $(countrySelect).filter(':checked').val();
        }
        countryVal || (countryVal = '');

        populateStates($el, countryVal);

        if (readOnly) {
          $el.prop('readonly', true);
          $el.prop('disabled', true);
        }
      });

      // Support CiviCRM's quirky way of doing optgroups
      $('option[value^=crm_optgroup]', context).each(function () {
        $(this).nextUntil('option[value^=crm_optgroup]').wrapAll('<optgroup label="' + $(this).text() + '" />');
        $(this).remove();
      });

      // Add handler to country field to trigger ajax refresh of corresponding state/prov
      $(once('civicrm', 'form.webform-submission-form .civicrm-enabled[name*="_address_country_id"]')).change(countrySelect);

      // Copy address fields to billing section if "Same As" checkbox is enabled.
      $(once('civicrm', 'form.webform-submission-form .civicrm-enabled[name="civicrm_1_contribution_1_contribution_billing_address_same_as"]')).change(function(){
        sameBillingAddress();
      });
      sameBillingAddress();

      // Show/hide address fields when sharing an address
      $(once('civicrm', 'form.webform-submission-form .civicrm-enabled[name*="_address_master_id"]')).change(function(){
        var action = ($(this).val() === '' || ($(this).is('input:checkbox:not(:checked)'))) ? 'show' : 'hide';
        sharedAddress(this, action, 500);
      });

      // Hide shared address fields on form load
      $('form.webform-submission-form select.civicrm-enabled[name*="_address_master_id"], form.webform-submission-form .civicrm-enabled[name*="_address_master_id"]:checked').each(function() {
        if ($(this).val() !== '') {
          sharedAddress(this, 'hide');
        }
      });

      // Handle image file ajax refresh
      $('div.civicrm-enabled[id*=contact-1-contact-image-url]:has(.file)', context).each(function() {
        pub.initFileField(getFieldNameFromClass($(this).parent()));
      });

      $(once('civicrm', 'form.webform-submission-form')).each(function () {
        if (Array.isArray(drupalSettings.webform_civicrm.fileFields)) {
          drupalSettings.webform_civicrm.fileFields.forEach(function (fileField){
            wfCivi.initFileField(fileField.eid, fileField.fileInfo);
          });
        }
      });
    }
  };
  return pub;
  })(Drupal, jQuery, drupalSettings, once);
