function webformCivicrmExistingAdminInit(path) {
  var field = jQuery('#default-contact-id');
  var cid = field.attr('defaultValue');
  var ret = null;
  if (cid) {
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
  return ret;
}

jQuery(document).ready(function() {
  jQuery('#edit-extra-default').change(function() {
    var val = jQuery(this).val().replace(/_/g, '-');
    jQuery('#edit-defaults > div > .form-item').not('.form-item-extra-default').each(function() {
      if (jQuery(this).hasClass('form-item-extra-default-'+val)) {
        jQuery(this).show();
      }
      else {
        jQuery(this).hide();
      }
    });
  }).change();
});
