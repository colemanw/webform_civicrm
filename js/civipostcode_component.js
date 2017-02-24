jQuery(document).ready(function () {
    var sourceUrl = "http://localhost:7777/civicrm/civipostcode/ajax/search?json=1&term=E1+6LA";
    var postcodeElement = "#edit-submitted-civicrm-1-contact-1-fieldset-fieldset-civicrm-1-contact-1-address-webform-civipostcode-postcode";
    jQuery(postcodeElement).autocomplete({
        source: sourceUrl,
        minLength: 3,
        search: function (event, ui) {
            //$('#loaderimage_'+blockNo).show();
        },
        response: function (event, ui) {
            //$('#loaderimage_'+blockNo).hide();
        },
        select: function (event, ui) {
            var id = ui.item.id;
            var sourceUrl = 'http://localhost:7777/civicrm/civipostcode/ajax/get?json=1';

            jQuery.ajax({
                dataType: 'json',
                data: {id: id},
                url: sourceUrl,
                success: function (data) {
                    console.log(data.address);
                    var streetAddressElement = "#edit-submitted-civicrm-1-contact-1-fieldset-fieldset-civicrm-1-contact-1-address-street-address";
                    var cityElement = "#edit-submitted-civicrm-1-contact-1-fieldset-fieldset-civicrm-1-contact-1-address-city";
                    var postalCodeElement = "#edit-submitted-civicrm-1-contact-1-fieldset-fieldset-civicrm-1-contact-1-address-postal-code";
                    jQuery(streetAddressElement).val(data.address.street_address);
                    jQuery(cityElement).val(data.address.town);
                    jQuery(postalCodeElement).val(data.address.postcode);
                    //setAddressFields(data.address, blockNo);
                    //setAddressFields(true, blockNo);
                    //jQuery('#loaderimage_' + blockNo).hide();
                }
            });
            return false;
        },
        //optional (if other layers overlap autocomplete list)
        open: function (event, ui) {
            jQuery(".ui-autocomplete").css("z-index", 1000);
        }
    });
});


