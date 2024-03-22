// Webform payment processing using CiviCRM's jQuery
(function (D, $, drupalSettings, once) {
  'use strict';
  var setting = drupalSettings.webform_civicrm;

  function getPaymentProcessor() {
    var $processorFields = $('[name$="civicrm_1_contribution_1_contribution_payment_processor_id"]');
    if (!$processorFields.length) {
      return setting.paymentProcessor;
    }
    return $processorFields.filter('select, :checked').val();
  }

  function loadBillingBlock() {
    var type = getPaymentProcessor();
    if (type) {
      $.ajax({
        url: setting.contributionCallback + '&' + setting.processor_id_key + '=' + type,
        success: function(data) {
          var $billingPaymentBlock = $('#billing-payment-block');
          $billingPaymentBlock.html(data);
          $billingPaymentBlock.trigger('crmLoad').trigger('crmFormLoad');
          if (setting.billingSubmission) {
            $.each(setting.billingSubmission, function(key, val) {
              $('[name="' + key + '"]').val(val);
            });
          }
          // When an express payment button is clicked, skip the billing fields and submit the form with a placeholder
          var $expressButton = $billingPaymentBlock.find('input[name$=_upload_express]');
          if ($expressButton.length) {
            $expressButton.removeClass('crm-form-submit').click(function(e) {
              e.preventDefault();
              $billingPaymentBlock.find('input[name=credit_card_number]').val('express');
              $(this).closest('form').find('input.webform-submit.button-primary').click();
            })
          }
          $('fieldset.billing_name_address-group').remove();
        }
      });
    }
    else {
      $('#billing-payment-block').html('');
    }
  }

  function getTotalAmount() {
    var totalAmount = 0.0;
    $('#wf-crm-billing-items').find('.line-item:visible').each(function() {
      totalAmount += parseFloat($(this).data('amount'));
    });
    return totalAmount;
  }

  function tally() {
    var total = 0;
    total = getTotalAmount();
    $('#wf-crm-billing-total').find('td+td').html(CRM.formatMoney(total));
    $('#billing-payment-block').toggle(total > 0);
  }

  function updateLineItem(item, amount, label) {
    var $lineItem = $('.line-item.' + item, '#wf-crm-billing-items');
    if (!$lineItem.length) {
      var oe = $('#wf-crm-billing-items tbody tr:first').hasClass('odd') ? ' even' : ' odd';
      $('#wf-crm-billing-items tbody').prepend('<tr class="line-item ' + item + oe + '" data-amount="' + amount + '">' +
        '<td>' + label + '</td>' +
        '<td>' + CRM.formatMoney(amount) + '</td>' +
      '</tr>');
    }
    else {
      var taxPara = 1;
      var tax = $lineItem.data('tax');
      if (tax && tax !== '0') {
        taxPara = 1 + (tax / 100);
      }
      $('td+td', $lineItem).html(CRM.formatMoney(amount * taxPara));
      $lineItem.data('amount', amount * taxPara);
      $lineItem.attr('data-amount', amount * taxPara);
    }
    tally();
  }

  function getFieldAmount(fid) {
    var amount, total = 0;
    $('input.civicrm-enabled[name*="' + fid + '"], select.civicrm-enabled[name*="' + fid +'"] option')
      .filter('option:selected, [type=hidden], [type=number], [type=text], :checked')
      .each(function() {
        amount = parseFloat($(this).val());
        if ($(this).is('.webform-grid-option input')) {
          var mult = parseFloat(this.name.substring(this.name.lastIndexOf('[')+1, this.name.lastIndexOf(']')));
          !isNaN(mult) && (amount = amount * mult);
        }
        total += isNaN(amount) ? 0 : amount;
      });
    return total;
  }

  function calculateLineItemAmount() {
    var fieldKey = $(this).data('civicrmFieldKey'),
      amount = getFieldAmount(fieldKey),
      label = $(this).closest('div.form-item').find('label').text() || Drupal.t('Contribution'),
      lineKey = fieldKey.split('_').slice(0, 4).join('_');
    updateLineItem(lineKey, amount, label);
  }

  D.behaviors.webform_civicrmPayment = {
    attach: function (context) {
      $('fieldset.billing_name_address-group', context).remove();

      $('[name$="civicrm_1_contribution_1_contribution_payment_processor_id"]', context).on('change', function() {
        drupalSettings.billingSubmission || (drupalSettings.billingSubmission = {});
        $('#billing-payment-block').find('input:visible, select').each(function() {
          var name = $(this).attr('name');
          name && (drupalSettings.billingSubmission[name] = $(this).val());
        });
        loadBillingBlock();
      });

      loadBillingBlock();

      $('.civicrm-enabled.contribution-line-item')
        .each(calculateLineItemAmount)
        .on('change keyup', calculateLineItemAmount)
        .each(function() {
          // Also use Drupal's jQuery to listen to this event, for compatibility with other modules
          jQuery(this).change(calculateLineItemAmount);
        });

      tally();

      var payment = {
        getTotalAmount: function() {
          return getTotalAmount();
        }
      };

      if (typeof CRM.payment === 'undefined') {
        CRM.payment = payment;
      }
      else {
        $.extend(CRM.payment, payment);
      }

      jQuery(once('wf-civi', '.webform-submission-form #edit-actions', context)).detach().appendTo('.webform-submission-form');
    }
  }

})(Drupal, CRM.$, drupalSettings, once);
