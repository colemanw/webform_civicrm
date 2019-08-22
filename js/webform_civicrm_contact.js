(function ($, D, drupalSettings) {
  D.behaviors.webform_civicrm_contact = {
    attach: function (context) {
      $('[data-civicrm-contact]', context).each(function (i, el) {
      var toHide = []
      var field = $(el)
      field.change(function () {
        wfCivi.existingSelect(
          field.data('civicrm-contact'),
          field.data('form-id'),
          '/webform-civicrm/js/' + field.data('form-id') + '/' + field.data('civicrm-field-key'),
          toHide,
          field.data('hide-method'),
          field.data('no-hide-blank'),
          $(this).val(),
          true,
          []
        );
      })
      })
    }
  }
})(jQuery, Drupal, drupalSettings)
