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
  var value = $(stateSelect).val();
  $(stateSelect).attr('disabled', 'disabled');
  $.ajax({
    url: '/webform-civicrm/js/state_province/'+countryId,
    dataType: 'json',
    success: function(data) {
      $(stateSelect).find('option').remove();
      for (key in data) {
        $(stateSelect).append('<option value="'+key+'">'+data[key]+'</option>');
      }
      $(stateSelect).val(value);
      $(stateSelect).removeAttr('disabled');
    }
  });
}

$(document).ready(function(){
  // Replace state/prov textboxes with dynamic select lists
  $('form.webform-client-form').find('input[name*="_address_state_province_id"][name*="[civicrm_"]').each(function(){
    var id = $(this).attr('id');
    var name = $(this).attr('name');
    var key = webform_civicrm_parse_name(name);
    var value = $(this).val();
    var countrySelect = $(this).parents('form.webform-client-form').find('[name*="['+(key.replace('state_province','country' ))+']"]');
    var classes = $(this).attr('class').replace('text', 'select');
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
    var name = webform_civicrm_parse_name($(this).attr('name'));
    if ($(this).val() === '' || ($(this).is(':checkbox:not(:checked)'))) {
      $(this).parents('form.webform-client-form').find('[name*="['+(name.replace('master_id', ''))+'"]').not(this).parent().show(500);
    }
    else {
      $(this).parents('form.webform-client-form').find('[name*="['+(name.replace('master_id', ''))+'"]').not(this).parent().hide(500);
    }
  });
  // Initialize hidden shared address
  $('form.webform-client-form select[name*="_address_master_id"][name*="[civicrm_"], form.webform-client-form [name*="_address_master_id"][name*="[civicrm_"]:checked').each(function() {
    if ($(this).val() !== '') {
      var name = webform_civicrm_parse_name($(this).attr('name'));
      $(this).parents('form.webform-client-form').find('[name*="['+(name.replace('master_id', ''))+'"]').not(this).parent().hide();
    }
  });

});
