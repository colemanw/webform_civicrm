/**
 * Javascript Module for administering the webform_civicrm contact field.
 */

var wfCiviContact = (function (D, $, once) {

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
  };

  D.behaviors.webform_civicrmContact = {
    attach: function (context) {
      $(once('wf-civi', '#edit-extra-default', context)).change(function() {
        var val = $(this).val().replace(/_/g, '-');
        $('#edit-contact-defaults > div > .form-item', context).not('.form-item-extra-default, .form-item-extra-allow-url-autofill').each(function() {
          if ($(this).hasClass('form-item-extra-default-'+val)) {
            $(this).removeAttr('style');
          }
          else {
            $(this).css('display', 'none');
            $(':checkbox', this).prop('disabled', true);
          }
        });
        if (val === 'auto' || val === 'relationship') {
          $('.form-item-extra-randomize, .form-item-extra-dupes-allowed')
            .removeAttr('style')
            .find(':checkbox')
            .removeAttr('disabled');
        }
        $('#edit-extra-default-relationship-to', context).each(changeDefaultRelationTo);
      });
      function changeDefaultRelationTo() {
        var c = $(this).val(),
          types = $(this).closest('form').data('reltypes')[c],
          placeholder = types.length ? false : '- ' + Drupal.ts('No relationship types available for these contact types') + ' -';
        CRM.utils.setOptions('#edit-extra-default-relationship', types, placeholder);
        // Provide default to circumvent "required" validation error
        if ($('#edit-extra-default').val() !== 'relationship' && !types.length && types[0].key === '') {
          CRM.utils.setOptions('#edit-extra-default-relationship', {key: '-', value: '-'});
          $('#edit-extra-default-relationship').val('-');
        }
      }
      function changeFiltersRelationTo() {
        var c = $(this).val(),
          types = $(this).closest('form').data('reltypes')[c];
        $('.form-item-extra-filters-relationship-type', context).toggle(!!c);
        if (c) {
          CRM.utils.setOptions('#edit-extra-filters-relationship-type', types);
        }
      }
      $(once('wf-civi', '#edit-extra-default', context)).change(changeDefault).each(changeDefault);
      $(once('wf-civi', '#edit-extra-default-relationship-to', context)).change(changeDefaultRelationTo);
      $(once('wf-civi', '#edit-extra-filters-relationship-contact', context)).change(changeFiltersRelationTo).each(changeFiltersRelationTo);
      $(once('wf-civi', '#edit-extra-widget', context)).change(function() {
        if ($(this).val() == 'hidden') {
          $('.form-item-extra-search-prompt', context).css('display', 'none');
          $('.form-item-extra-show-hidden-contact', context).removeAttr('style');
        }
        else {
          $('.form-item-extra-search-prompt', context).removeAttr('style');
          $('.form-item-extra-show-hidden-contact', context).css('display', 'none');
        }
      }).change();

      $(once('wf-civi', 'select[name*=hide_fields]', context)).change(function() {
        $(this).parent().nextAll('.form-item').toggle(!!$(this).val());
      }).change();

      // Warning if enforce permissions is disabled
      $(once('wf-civi', '#webform-component-edit-form', context)).submit(function() {
        if (!$('input[name="extra[filters][check_permissions]"]').is(':checked') && $('input[name="extra[allow_url_autofill]"]').is(':checked')) {
          return confirm(Drupal.t('Warning: "Enforce Permissions" is disabled but "Use contact id from URL" is enabled. Anyone with access to this webform will be able to view any contact in the database (who meets the filter criteria) by typing their contact id in the URL.'));
        }
      });
    }
  };

  return pub;
})(Drupal, jQuery, once);
