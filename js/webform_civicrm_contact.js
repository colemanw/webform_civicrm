(function (D, $, drupalSettings, once) {
  D.behaviors.webform_civicrm_contact = {
    attach: function (context) {
      $(once('webform_civicrm_contact', '[data-civicrm-contact]', context)).each(function (i, el) {
        var field = $(el);
        var toHide = [];
        if (field.data('hide-fields')) {
          toHide = field.data('hide-fields').split(', ');
        }
        var autocompleteUrl = D.url('webform-civicrm/js/' + field.data('form-id') + '/' + field.data('civicrm-field-key'));
        var isSelect = field.data('is-select');
        if (!isSelect) {
          var tokenValues = field.data('is-contactid') ? false : {
            hintText: field.data('search-prompt'),
            noResultsText: field.data('none-prompt'),
            resultsFormatter: formatChoices,
            searchingText: "Searching...",
            enableHTML: true
          };
        }
        else {
          var tokenValues = false;
        }
        
        wfCivi.existingInit(
          field,
          field.data('civicrm-contact'),
          field.data('form-id'),
          autocompleteUrl,
          toHide,
          tokenValues
        );

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
            field.data('form-defaults'),
          );
        });

        //In case of error, highlight the token-input field.
        if (field.hasClass('error')) {
          field.parent('div.form-item').addClass('has-error');
        }
      });

      /**
       * Format the choices in the "Existing Contact widget", with a special format for the "No Results" item.
       */
      function formatChoices(item){
        var string = item[this.propertyToSearch];
        if (string == this.noResultsText) {
          return "<li><em><i>" + string + "</i></em></li>";
        }
        return "<li>" + string + "</li>";
      }

      /**
       * TODO: Remove this function and use states api instead once
       * https://www.drupal.org/project/drupal/issues/1149078 is fixed in core webform module.
       */
      function changeDefault() {
        var val = $(this).val().replace(/_/g, '-');

        $('[data-drupal-selector=edit-contact-defaults] > div > .form-item', context).not('[class$=properties-default], [class*=properties-allow-url-autofill]').each(function() {
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
})(Drupal, jQuery, drupalSettings, once)
