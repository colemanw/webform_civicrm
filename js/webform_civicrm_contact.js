(function ($, D, drupalSettings) {
  D.behaviors.webform_civicrm_contact = {
    attach: function (context) {
      $('[data-civicrm-contact]', context).each(function (i, el) {
        var toHide = []
        var field = $(el)
        var autocompleteUrl = D.url('webform-civicrm/js/' + field.data('form-id') + '/' + field.data('civicrm-field-key'));
        wfCivi.existingInit(
          field,
          field.data('civicrm-contact'),
          field.data('form-id'),
          autocompleteUrl,
          toHide, {
          hintText: "- Choose existing -",
          noResultsText: "+ Create new +",
          searchingText: "Searching..."
        });
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
      })
    }
  }
})(jQuery, Drupal, drupalSettings)
