/**
 * JS for CiviCRM-enabled webforms
 */

var wfCivi = (function ($, D) {
  'use strict';
  /**
   * Public methods.
   */
  var pub = {};

  pub.existingSelect = function (num, nid, path, toHide, hideOrDisable, showEmpty, cid, fetch, defaults) {
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
          $(':input[name$="civicrm_'+num+'_contact_1_contact_'+i+'_name]"]', '.webform-client-form-'+nid).val(names[i]);
        }
      }
      return;
    }
    resetFields(num, nid, true, 'hide', toHide, hideOrDisable, showEmpty, 500, defaults);
    if (cid && fetch) {
      $('.webform-client-form-'+nid).addClass('contact-loading');
      var params = getCids(nid);
      params.load = 'full';
      params.cid = cid;
      $.getJSON(path, params, function(data) {
        fillValues(data, nid);
        resetFields(num, nid, false, 'hide', toHide, hideOrDisable, showEmpty);
        $('.webform-client-form-'+nid).removeClass('contact-loading');
      });
    }
  };

  pub.existingInit = function ($field, num, nid, path, toHide, tokenInputSettings) {
    var cid = $field.val(),
      prep = null,
      hideOrDisable = $field.attr('data-hide-method'),
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
      if (tokenInputSettings) {
        tokenInputSettings.queryParam = 'str';
        tokenInputSettings.tokenLimit = 1;
        tokenInputSettings.prePopulate = prep;
        $field.tokenInput(getCallbackPath, tokenInputSettings);
      }
    }
  };

  pub.initFileField = function(field, info) {
    info = info || {};
    var container = $('div.webform-component[class*="--' + field.replace(/_/g, '-') + '"] div.civicrm-enabled');
    if (container.length > 0) {
      if ($('.file', container).length > 0) {
        $('.file', container).hide();
        info.icon = $('.file a', container).attr('href');
      }
      else {
        $(container).children().hide();
        container.append('<input type="submit" class="form-submit ajax-processed civicrm-remove-file" value="' + Drupal.t('Change') + '" onclick="wfCivi.clearFileField(\'' + field + '\'); return false;">');
      }
      container.prepend('<span class="civicrm-file-icon"><img alt="' + Drupal.t('File') + '" src="' + info.icon + '" /> ' + (info.name ? ('<a href="'+ info.file_url+ '" target="_blank">'+info.name +'</a>') : '') + '</span>');
    }
  };

  pub.clearFileField = function(field) {
    var container = $('div.webform-component[class*="--' + field.replace(/_/g, '-') + '"] div.civicrm-enabled');
    $('.civicrm-remove-file, .civicrm-file-icon', container).remove();
    $(container).children().show();
  };

  /**
   * Private methods.
   */

  var stateProvinceCache = {};

  function resetFields(num, nid, clear, op, toHide, hideOrDisable, showEmpty, speed, defaults) {
    $('div.form-item.webform-component[class*="--civicrm-'+num+'-contact-"]', '.webform-client-form-'+nid).each(function() {
      var $el = $(this);
      var name = getFieldNameFromClass($el);
      if (!name) {
        return;
      }
      var n = name.split('-');
      if (n[0] === 'civicrm' && n[1] == num && n[2] === 'contact' && n[5] !== 'existing') {
        if (clear) {
          // Reset country to default
          if (n[5] === 'country') {
            $('select.civicrm-processed', this).val(D.settings.webform_civicrm.defaultCountry).trigger('change', 'webform_civicrm:reset');
          }
          //Set default value if it is specified in component settings.
          else if ($el.hasClass('webform-component-date') && typeof defaults != "undefined" && defaults.hasOwnProperty(name)) {
            var date = defaults[name].split('-');
            $el.find('select.year, input.year').val(+date[0]);
            $el.find('select.month').val(+date[1]);
            $el.find('select.day').val(+date[2]);
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
              $(this).removeAttr('checked').trigger('change', 'webform_civicrm:reset');
            });
          }
        }
        var type = (n[6] === 'name') ? 'name' : n[4];
        if ($.inArray(type, toHide) >= 0) {
          var fn = (op === 'hide' && (!showEmpty || !isFormItemBlank($el))) ? 'hide' : 'show';
          switch (hideOrDisable) {
            case 'disable':
            case 'hide':
              $(':input', $el).webformProp('disabled', fn === 'hide');
              $(':input', $el).webformProp('readonly', fn === 'hide');
              break;
            case 'readonly':
              $(':input', $el).webformProp('readonly', fn === 'hide');
              break;
          }
          $('select.civicrm-enabled[name*="_address_state_province_id"]').each(function() {
            $(this).webformProp('disabled', fn === 'hide');
            $(this).webformProp('readonly', fn === 'hide');
          });
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
      if (val.indexOf('webform-component--') === 0 && val.indexOf('--civicrm') > 0) {
        val = val.substring(val.lastIndexOf('--civicrm') + 2);
        if (val.indexOf('fieldset') < 0) {
          name = val;
        }
      }
    });
    return name;
  }

  function fillValues(data, nid) {
    $.each(data, function() {
      var fid = this.fid,
        val = this.val;
      // Handle file fields
      if (this.data_type === 'File') {
        pub.initFileField(fid, this);
        return;
      }
      // First try to find a single element - works for textfields and selects
      var $el = $('.webform-client-form-'+nid+' :input.civicrm-enabled[name$="'+fid+']"]').not(':checkbox, :radio');
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
            $el.siblings('.token-input-list').find('p').text(this.display);
          }
          $el.val(val).trigger('change', 'webform_civicrm:autofill');
        }
      }
      // Next go after the wrapper - for radios, dates & checkboxes
      else {
        var $wrapper = $('.webform-client-form-'+nid+' div.form-item.webform-component[class*="--'+(fid.replace(/_/g, '-'))+'"]');
        if ($wrapper.length) {
          // Date fields
          if ($wrapper.hasClass('webform-component-date')) {
            var vals = val.split('-');
            if (vals.length === 3) {
              $(':input[id$="year"]', $wrapper).val(vals[0]).trigger('change', 'webform_civicrm:autofill');
              $(':input[id$="month"]', $wrapper).val(parseInt(vals[1], 10)).trigger('change', 'webform_civicrm:autofill');
              $(':input[id$="day"]', $wrapper).val(parseInt(vals[2], 10)).trigger('change', 'webform_civicrm:autofill');
            }
          }
          // Checkboxes & radios
          else {
            $.each($.makeArray(val), function(k, v) {
              $(':input[value="'+v+'"]', $wrapper).webformProp('checked', true).trigger('change', 'webform_civicrm:autofill');
            });
          }
        }
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
    $(stateSelect).webformProp('disabled', true);
    if (stateProvinceCache[countryId]) {
      fillOptions(stateSelect, stateProvinceCache[countryId]);
    }
    else {
      $.getJSON(D.settings.webform_civicrm.callbackPath + '/stateProvince/' + countryId, function(data) {
        fillOptions(stateSelect, data);
        stateProvinceCache[countryId] = data;
      });
    }
  }

  function populateCounty() {
    var
      stateSelect = $(this),
      key = parseName(stateSelect.attr('name')),
      countryId = stateSelect.parents('form').find('.civicrm-enabled[name*="['+(key.replace('state_province', 'country'))+']"]').val(),
      countySelect = stateSelect.parents('form').find('.civicrm-enabled[name*="['+(key.replace('state_province','county' ))+']"]'),
      stateVal = stateSelect.val();
    if (countySelect.length) {
      if (!stateVal) {
        fillOptions(countySelect, {'': Drupal.t('- First Choose a State -')});
      }
      else if (stateVal === '-') {
        fillOptions(countySelect, null);
      }
      else {
        $.getJSON(D.settings.webform_civicrm.callbackPath + '/county/' + stateVal + '-' + countryId, function(data) {
          fillOptions(countySelect, data);
        });
      }
    }
  }

  function fillOptions(element, data) {
    var $el = $(element),
      value = $el.attr('data-val') ? $el.attr('data-val') : $el.val();
    $el.find('option').remove();
    if (!$.isEmptyObject(data || [])) {
      if (!data['']) {
        var text = $el.hasClass('required') ? Drupal.t('- Select -') : Drupal.t('- None -');
        $el.append('<option value="">'+text+'</option>');
      }
      $.each(data, function(key, val) {
        $el.append('<option value="'+key+'">'+val+'</option>');
      });
      $el.val(value);
    }
    else {
      $el.append('<option value="-">'+Drupal.t('- N/A -')+'</option>');
    }
    $el.removeAttr('disabled').trigger('change', 'webform_civicrm:chainselect');
  }

  function sharedAddress(item, action, speed) {
    var name = parseName($(item).attr('name'));
    var fields = $(item).parents('form.webform-client-form').find('[name*="['+(name.replace('master_id', ''))+'"]').not('[name*=location_type_id]').not('[name*=master_id]').not('[type="hidden"]');
    if (action === 'hide') {
      fields.parent().hide(speed, function() {$(this).css('display', 'none');});
      fields.webformProp('disabled', true);
    }
    else {
      fields.removeAttr('disabled');
      fields.parent().show(speed);
    }
  }

  function countrySelect() {
    var name = parseName($(this).attr('name'));
    var countryId = $(this).val();
    var stateSelect = $(this).parents('form.webform-client-form').find('select.civicrm-enabled[name*="['+(name.replace('country', 'state_province'))+']"]');
    if (stateSelect.length) {
      populateStates(stateSelect, countryId);
    }
  }

  function getCids(nid) {
    var cids = $('.webform-client-form-'+nid).data('civicrm-ids') || {};
    $('.webform-client-form-'+nid+' .civicrm-enabled:input[name$="_contact_1_contact_existing]"]').each(function() {
      var cid = $(this).val();
      if (cid) {
        var n = parseName($(this).attr('name')).split('_');
        cids['cid' + n[1]] = cid;
      }
    });
    return cids;
  }

  function makeSelect($el) {
    var value = $el.val(),
      classes = $el.attr('class').replace('text', 'select'),
      id = $el.attr('id'),
      $form = $el.closest('form');
    $el.replaceWith('<select id="'+$el.attr('id')+'" name="'+$el.attr('name')+'"' + ' class="' + classes + ' civicrm-processed" data-val="' + value + '"></select>');
    return $('#' + id, $form).change(function() {
      $(this).attr('data-val', '');
    });
  }

  D.behaviors.webform_civicrmForm = {
    attach: function (context) {
      if (!stateProvinceCache['default'] && D.settings.webform_civicrm) {
        stateProvinceCache['default'] = D.settings.webform_civicrm.defaultStates;
        stateProvinceCache[D.settings.webform_civicrm.defaultCountry] = D.settings.webform_civicrm.defaultStates;
        stateProvinceCache[''] = {'': D.settings.webform_civicrm.noCountry};
      }

      // Replace state/prov & county textboxes with dynamic select lists
      $('input:text.civicrm-enabled[name*="_address_state_province_id"]', context).each(function() {
        var $el = $(this);
        var key = parseName($el.attr('name'));
        var countrySelect = $el.parents('form').find('.civicrm-enabled[name*="['+(key.replace('state_province', 'country'))+']"]');
        var $county = $el.parents('form').find('.civicrm-enabled[name*="['+(key.replace('state_province', 'county'))+']"]');

        var readOnly = $el.attr('readonly');

        $el = makeSelect($el);
        if ($county.length && !$county.attr('readonly')) {
          $county = makeSelect($county);
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
          $el.webformProp('readonly', true);
          $el.webformProp('disabled', true);
        }
      });

      // Support CiviCRM's quirky way of doing optgroups
      $('option[value^=crm_optgroup]', context).each(function () {
        $(this).nextUntil('option[value^=crm_optgroup]').wrapAll('<optgroup label="' + $(this).text() + '" />');
        $(this).remove();
      });

      // Add handler to country field to trigger ajax refresh of corresponding state/prov
      $('form.webform-client-form .civicrm-enabled[name*="_address_country_id]"]').once('civicrm').change(countrySelect);

      // Show/hide address fields when sharing an address
      $('form.webform-client-form .civicrm-enabled[name*="_address_master_id"]').once('civicrm').change(function(){
        var action = ($(this).val() === '' || ($(this).is('input:checkbox:not(:checked)'))) ? 'show' : 'hide';
        sharedAddress(this, action, 500);
      });

      // Hide shared address fields on form load
      $('form.webform-client-form select.civicrm-enabled[name*="_address_master_id"], form.webform-client-form .civicrm-enabled[name*="_address_master_id"]:checked').each(function() {
        if ($(this).val() !== '') {
          sharedAddress(this, 'hide');
        }
      });

      // Handle image file ajax refresh
      $('div.civicrm-enabled[id*=contact-1-contact-image-url]:has(.file)', context).each(function() {
        pub.initFileField(getFieldNameFromClass($(this).parent()));
      });
    }
  };

  /**
   * Update membership type fees field based on selected membership.
   */
  D.behaviors.membershipTypeFeesUpdate = {
    attach: function (context, settings) {
      const membershipSelector = '[id*="membership-membership-type-id"]';

      // Function to get membership value from Drupal settings.
      function getMembershipValue(fieldsetName) {
        // Solution 1: Check Drupal settings.
        if (typeof Drupal.settings.webform !== 'undefined' &&
            typeof Drupal.settings.webform.membershipValues !== 'undefined') {
          return Drupal.settings.webform.membershipValues[fieldsetName] || '';
        }
        return '';
      }

      // Function to update fee field with AJAX call.
      function updateFeeField(membershipId, feeFieldInput) {
        if (!membershipId || feeFieldInput.length === 0) {
          return;
        }

        $.ajax({
          url: '/webform-civicrm/js/getMembershipFees/' + membershipId,
          dataType: 'json',
          success: function (response) {
            if (response.fees !== undefined) {
              $(feeFieldInput).val(response.fees);
            }
          },
          error: function () {
            console.log('Failed to fetch fee.');
          }
        });
      }

      // Function to extract core membership pattern from field name.
      function extractMembershipPattern(elementName) {
        // Extract civicrm_X_membership_Y_membership from both structures:
        // 1. submitted[civicrm_1_membership_1_membership_membership_type_id]
        // 2. submitted[...][civicrm_1_membership_1_membership_membership_type_id]
        const match = elementName.match(/(civicrm_\d+_membership_\d+_membership)_membership_type_id/);
        return match ? match[1] : null;
      }

      // Function to find corresponding fee field.
      function findCorrespondingFeeField(membershipPattern, context) {
        // Look for fee field with the same pattern.
        const feeFields = $('input[id*="membership-fee-amount-from-membership"]', context);
        return feeFields.filter(function() {
          const feeFieldName = $(this).attr('name');
          if (!feeFieldName) {
            return false;
          }

          // Check if this fee field contains the same membership pattern.
          const feePattern = membershipPattern + '_fee_amount_from_membership';
          return feeFieldName.includes(feePattern);
        });
      }

      // Function to get current membership type value from the form.
      function getCurrentMembershipValue(membershipPattern, context) {
        const membershipFields = $(membershipSelector, context).filter(function() {
          const fieldName = $(this).attr('name');
          if (!fieldName) {
            return false;
          }

          const fieldPattern = extractMembershipPattern(fieldName);
          return fieldPattern === membershipPattern;
        });

        if (membershipFields.length === 0) {
          return '';
        }

        const field = membershipFields.first();
        if (field.is('input[type="radio"]')) {
          const radioName = field.attr('name');
          const checkedRadio = $('input[type="radio"][name="' + radioName + '"]:checked', context);
          return checkedRadio.length > 0 ? checkedRadio.val() : '';
        } else {
          return field.val() || '';
        }
      }

      // Handle each membership field individually.
      $(membershipSelector, context).each(function() {
        const $element = $(this);
        const isRadio = $element.is('input[type="radio"]');
        const isSelect = $element.is('select');

        if (!isRadio && !isSelect) {
          return;
        }

        const elementName = $element.attr('name');
        const membershipPattern = extractMembershipPattern(elementName);

        if (!membershipPattern) {
          return;
        }

        // Set up change handler for this specific membership instance.
        $element.once('feeAjax-' + membershipPattern).on('change', function () {
          let selectedValue;

          // Get selected value based on element type.
          if (isRadio) {
            const radioName = $(this).attr('name');
            const checkedRadio = $('input[type="radio"][name="' + radioName + '"]:checked', context);
            if (checkedRadio.length === 0) {
              return;
            }
            selectedValue = checkedRadio.val();
          } else {
            selectedValue = $(this).val();
          }

          // Find the corresponding fee field.
          const feeFieldInput = findCorrespondingFeeField(membershipPattern, context);
          // Update fee field.
          updateFeeField(selectedValue, feeFieldInput);
        });

        // Check for stored values on page load for this membership instance.
        const storedValue = getMembershipValue(membershipPattern);
        if (storedValue) {
          // Find the corresponding fee field.
          const feeFieldInput = findCorrespondingFeeField(membershipPattern, context);
          // If fee field exists and is empty, update it.
          if (feeFieldInput.length > 0) {
            updateFeeField(storedValue, feeFieldInput);
          }
        }
      });

      // Also check for fee fields that might exist without corresponding membership fields on current step.
      const feeFields = $('input[id*="membership-fee-amount-from-membership"]', context);
      feeFields.each(function() {
        const feeInput = $(this);
        const feeInputName = feeInput.attr('name');

        if (!feeInputName) {
          return;
        }

        // Extract membership pattern from fee field name.
        const match = feeInputName.match(/(civicrm_\d+_membership_\d+_membership)_fee_amount_from_membership/);
        const membershipPattern = match ? match[1] : null;

        if (!membershipPattern) {
          return;
        }

        // Check if corresponding membership field exists on current step.
        const correspondingMembershipField = $(membershipSelector).filter(function() {
          const membershipFieldName = $(this).attr('name');
          if (!membershipFieldName) {
            return false;
          }
          const fieldPattern = extractMembershipPattern(membershipFieldName);
          return fieldPattern === membershipPattern;
        });

        // Only process if no corresponding membership field exists on current step.
        if (correspondingMembershipField.length === 0) {
          // Check stored value from Drupal settings.
          const membershipValue = getMembershipValue(membershipPattern);
          // If we have a stored value and fee field is empty, update it.
          if (membershipValue) {
            updateFeeField(membershipValue, feeInput);
          }
        }
      });
    }
  };

  return pub;
})(jQuery, Drupal);
