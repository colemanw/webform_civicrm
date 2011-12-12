(function ($) {

function webform_civicrm_parse_name(name) {
  var pos = name.lastIndexOf('[civicrm_');
  name = name.slice(1+pos);
  pos = name.indexOf(']');
  if (pos !== -1) {
    name = name.slice(0, pos);
  }
  return name;
}

function webform_civicrm_populate_states(stateSelect, countryId) {
  $(stateSelect).attr('disabled', 'disabled');
  if (stateProvinceCache[countryId]) {
    webform_civicrm_fill_options(stateSelect, stateProvinceCache[countryId]);
  }
  else {
    $.ajax({
      url: '/webform-civicrm/js/state_province/'+countryId,
      dataType: 'json',
      success: function(data) {
        webform_civicrm_fill_options(stateSelect, data);
        stateProvinceCache[countryId] = data;
      }
    });
  }
}

function webform_civicrm_fill_options(element, data) {
  var value = $(element).val();
  $(element).find('option').remove();
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
      if ($(element).hasClass('required')) {
        var text = webformSelectSelect;
      }
      else {
        var text = webformSelectNone;
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
    $(element).append('<option value="-">'+webformSelectNa+'</option>');
  }
  $(element).removeAttr('disabled');
}

function webform_civicrm_shared_address(item, action, speed) {
  var name = webform_civicrm_parse_name($(item).attr('name'));
  fields = $(item).parents('form.webform-client-form').find('[name*="['+(name.replace('master_id', ''))+'"]').not(item).not('[name*=location_type_id]').not('[type="hidden"]');
  if (action === 'hide') {
    $(fields).not(':hidden').parent().hide(speed);
    $(fields).attr('disabled', 'disabled');
  }
  else {
    $(fields).removeAttr('disabled');
    $(fields).parent().show(speed);
  }
}

$(document).ready(function(){
  // Replace state/prov textboxes with dynamic select lists
  $('form.webform-client-form').find('input[name*="_address_state_province_id"][name*="[civicrm_"][type="text"]').each(function(){
    var id = $(this).attr('id');
    var name = $(this).attr('name');
    var key = webform_civicrm_parse_name(name);
    var value = $(this).val();
    var countrySelect = $(this).parents('form.webform-client-form').find('[name*="['+(key.replace('state_province','country' ))+']"]');
    var classes = $(this).attr('class').replace('text', 'select');
    if (value !== '') {
      classes = classes + ' has-default';
    }
    $(this).replaceWith('<select id="'+id+'" name="'+name+'" class="'+classes+'"><option selected="selected" value="'+value+'"> </option></select>');
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
    webform_civicrm_populate_states($('#'+id), countryVal);
  });

  // Add handler to country field to trigger ajax refresh of corresponding state/prov
  $('form.webform-client-form [name*="_address_country_id]"][name*="[civicrm_"]').change(function(){
    var name = webform_civicrm_parse_name($(this).attr('name'));
    var countryId = $(this).val();
    var stateSelect = $(this).parents('form.webform-client-form').find('select[name*="['+(name.replace('country', 'state_province'))+']"]');
    if (stateSelect.length) {
      $(stateSelect).val('');
      webform_civicrm_populate_states(stateSelect, countryId);
    }
  });

  // Show/hide address fields when sharing an address
  $('form.webform-client-form [name*="_address_master_id"][name*="[civicrm_"]').change(function(){
    if ($(this).val() === '' || ($(this).is(':checkbox:not(:checked)'))) {
      webform_civicrm_shared_address(this, 'show', 500);
    }
    else {
      webform_civicrm_shared_address(this, 'hide', 500);
    }
  });
  // Hide shared address fields on form load
  $('form.webform-client-form select[name*="_address_master_id"][name*="[civicrm_"], form.webform-client-form [name*="_address_master_id"][name*="[civicrm_"]:checked').each(function() {
    if ($(this).val() !== '') {
      webform_civicrm_shared_address(this, 'hide');
    }
  });

});

})(jQuery);
