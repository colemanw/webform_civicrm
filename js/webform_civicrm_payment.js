// Webform payment processing using CiviCRM's jQuery
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.webformCivirmPayment = {
    attach: function attach(context, settings) {
      var
        setting = settings.webform_civicrm,
        $processorFields = $('[name$="civicrm_1_contribution_1_contribution_payment_processor_id"]');

      $('.civicrm-enabled.contribution-line-item', context)
        .once('calculate-line-item-amount')
        .each(calculateLineItemAmount)
        .on('change keyup', calculateLineItemAmount)
        .each(function() {
          // Also use Drupal's jQuery to listen to this event, for compatibility with other modules
          $(this).change(calculateLineItemAmount);
        });

      tally();

      function getPaymentProcessor() {
        if (!$processorFields.length) {
          return setting.paymentProcessor;
        }
        return $processorFields.filter('select, :checked').val();
      }

      function loadBillingBlock() {
        var type = getPaymentProcessor();
        if (type && type !== '0') {
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
            }
          });
        }
        else {
          $('#billing-payment-block').html('');
        }
      }
      $processorFields.on('change', function() {
        setting.billingSubmission || (setting.billingSubmission = {});
        $('#billing-payment-block').find('input:visible, select').each(function() {
          var name = $(this).attr('name');
          name && (setting.billingSubmission[name] = $(this).val());
        });
        loadBillingBlock();
      });
      loadBillingBlock();

      function tally() {
        var total = 0;
        $('#wf-crm-billing-items').find('.line-item').each(function() {
          var li = $(this);
          li.children('td').each(function(index, item){
            if (index == 1) {
              if (item.textContent == '$ 0.00') {
                li.css('display', 'none');
              }
              else {
                li.css('display', 'table-row');
              }
            }
          });
        });
        $('#wf-crm-billing-items').find('.line-item:visible').each(function() {
          total += parseFloat($(this).data('amount'));
        });
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
        /* Allow negative value line items. M.H https://projects.skvare.com/issues/12029 */
        //return total < 0 ? 0 : total;
        return total;
      }

      function calculateLineItemAmount() {
        var fieldKey = $(this).data('civicrmFieldKey'),
          amount = getFieldAmount(fieldKey),
          label = $(this).siblings('label').text() || Drupal.t('Contribution'),
          lineKey = fieldKey.split('_').slice(0, 4).join('_');
        updateLineItem(lineKey, amount, label);
      }
    }
  }
})(jQuery, Drupal);
