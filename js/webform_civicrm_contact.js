(function ($, D, drupalSettings) {
  D.behaviors.webform_civicrm_contact = {
    attach: function (context) {
      $('[data-civicrm-contact]', context).once('webform_civicrm_contact').each(function (i, el) {
        var field = $(el);
        var toHide = [];
        if (field.data('hide-fields')) {
          toHide = field.data('hide-fields').split(', ');
        }
        var autocompleteUrl = D.url('webform-civicrm/js/' + field.data('form-id') + '/' + field.data('civicrm-field-key'));
        var isSelect = field.data('is-select');
        if (!isSelect) {
          wfCivi.existingInit(
            field,
            field.data('civicrm-contact'),
            field.data('form-id'),
            autocompleteUrl,
            toHide, {
            hintText: field.data('search-prompt'),
            noResultsText: field.data('none-prompt'),
            searchingText: "Searching..."
          });
        }

        field.change(function () {
          wfCivi.existingSelect(
            field.data('civicrm-contact'),
            field.data('form-id'),
            autocompleteUrl,
            toHide,
            field.data('hide-method'),
            field.data('no-hide-blank'),
            $(this).val(),
            true,
            []
          );
        });
      });


      function changeDefault() {
        var val = $(this).val().replace(/_/g, '-');

        $('[data-drupal-selector=edit-contact-defaults] > div > .form-item', context).not('[class$=properties-default], [class$=properties-allow-url-autofill]').each(function() {
          if (val.length && $(this).is('[class*=form-item-properties-default-'+val+']')) {
            $(this).removeAttr('style');
          }
          else {
            $(this).css('display', 'none');
            $(':checkbox', this).prop('disabled', true);
          }
        });
        if (val === 'auto' || val === 'relationship') {
          $('.form-item-properties-randomize, .form-item-properties-dupes-allowed')
            .removeAttr('style')
            .find(':checkbox')
            .removeAttr('disabled');
        }
        $('[data-drupal-selector=edit-properties-default-relationship-to]', context).each(changeDefaultRelationTo);
      }

      function changeDefaultRelationTo() {
        var c = $(this).val(),
        types = $('[data-drupal-selector=edit-properties-default-relationship]').data('reltypes')[c],
        placeholder = types.length ? false : '- ' + Drupal.ts('No relationship types available for these contact types') + ' -';

        CRM.utils.setOptions('[data-drupal-selector=edit-properties-default-relationship]', types, placeholder);
        // Provide default to circumvent "required" validation error
        if ($('[data-drupal-selector=edit-properties-default]').val() !== 'relationship' && !types.length && types[0].key === '') {
          CRM.utils.setOptions('[data-drupal-selector=edit-properties-default-relationship]', {key: '-', value: '-'});
          $('[data-drupal-selector=edit-properties-default-relationship]').val('-');
        }
      }

      function changeFiltersRelationTo() {
        var c = $(this).val(),
        types = $('[data-drupal-selector=edit-properties-filter-relationship-types]').data('reltypes')[c];
        $('.form-item-properties-filter-relationship-types', context).toggle(!!c);
        if (c) {
          CRM.utils.setOptions('[data-drupal-selector=edit-properties-filter-relationship-types]', types);
        }
      }

      $('[data-drupal-selector=edit-properties-default]', context).change(changeDefault).each(changeDefault);
      $('[data-drupal-selector=edit-properties-default-relationship-to]', context).change(changeDefaultRelationTo);
      $('[data-drupal-selector=edit-properties-filter-relationship-contact]', context).change(changeFiltersRelationTo).each(changeFiltersRelationTo);
      $('[data-drupal-selector=edit-properties-widget]', context).change(function() {
        if ($(this).val() == 'hidden') {
          $('.form-item-properties-search-prompt', context).css('display', 'none');
          $('.form-item-properties-show-hidden-contact', context).removeAttr('style');
        }
        else {
          $('.form-item-properties-search-prompt', context).removeAttr('style');
          $('.form-item-properties-show-hidden-contact', context).css('display', 'none');
        }
      }).change();

    }
  }
})(jQuery, Drupal, drupalSettings)
