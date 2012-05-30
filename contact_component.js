/**
 * Javascript Module for administering the webform_civicrm contact field.
 */

var wfCiviContact = (function ($, D) {

  var pub = {};

  pub.init = function (path) {
    var field = $('#default-contact-id');
    var cid = field.attr('defaultValue');
    var ret = null;
    if (cid) {
      if (cid == field.attr('data-civicrm-id')) {
        ret = [{id: cid, name: field.attr('data-civicrm-name')}];
      }
      else {
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
    return ret;
  }

  D.behaviors.webform_civicrmContact = {
    attach: function (context) {
      $('#edit-extra-default', context).once('wf-civi').change(function() {
        var val = $(this).val().replace(/_/g, '-');
        $('#edit-defaults > div > .form-item', context).not('.form-item-extra-default').each(function() {
          if ($(this).hasClass('form-item-extra-default-'+val)) {
            $(this).show();
          }
          else {
            $(this).hide();
          }
        });
      }).change();
      $('#edit-extra-widget', context).once('wf-civi').change(function() {
        if ($(this).val() == 'hidden') {
          $('.form-item-extra-search-prompt', context).hide();
          $('.form-item-extra-show-hidden-contact', context).show();
        }
        else {
          $('.form-item-extra-search-prompt', context).show();
          $('.form-item-extra-show-hidden-contact', context).hide();
        }
      }).change();
    }
  };

  return pub;
})(jQuery, Drupal);
