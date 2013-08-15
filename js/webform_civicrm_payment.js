// Webform payment processing using CiviCRM's jQuery
cj(function($) {
  'use strict';
  var setting = Drupal.settings.webform_civicrm;
  var $contributionAmount = $('.civicrm-enabled[name*="[civicrm_1_contribution_1_contribution_total_amount]"]');
  function loadBillingBlock(type) {
    if (type) {
      $('#billing-payment-block').load(setting.contributionCallback + '&type=' + type, function() {
        $('#billing-payment-block').trigger('crmFormLoad');
        if (setting.billingSubmission) {
          $.each(setting.billingSubmission, function(key, val) {
            $('[name="' + key + '"]').val(val);
          });
        }
      });
    }
    else {
      $('#billing-payment-block').html('');
    }
  }
  var $processorSelect = $('select.civicrm-enabled[name$="civicrm_1_contribution_1_contribution_payment_processor_id]"]');
  $processorSelect.on('change', function() {
    setting.billingSubmission || (setting.billingSubmission = {});
    $('input:visible, select', '#billing-payment-block').each(function() {
      var name = $(this).attr('name');
      name && (setting.billingSubmission[name] = $(this).val());
    });
    loadBillingBlock($(this).val());
  });
  loadBillingBlock($processorSelect.val() || setting.paymentProcessor);

  function tally() {
    var total = 0;
    $('.line-item:visible', '#wf-crm-billing-items').each(function() {
      total += parseFloat($(this).data('amount'));
    });
    $('td+td', '#wf-crm-billing-total').html(CRM.formatMoney(total));
    if (total > 0) {
      $('#billing-payment-block').show();
    } else {
      $('#billing-payment-block').hide();
    }
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
      $('td+td', $lineItem).html(CRM.formatMoney(amount));
      $lineItem.data('amount', amount);
    }
    tally();
  }

  function getFieldAmount(fid) {
    var amount, total = 0;
    var $fields = $('input.civicrm-enabled[name*="' + fid +'"], select.civicrm-enabled[name*="' + fid +'"] option')
      .filter('option:selected, [type=hidden], [type=number], [type=text], :checked');
    $fields.each(function() {
      amount = parseFloat($(this).val());
      total += (isNaN(amount) || amount < 0) ? 0 : amount;
    });
    return total;
  }

  function calculateContributionAmount() {
    var amount = getFieldAmount('civicrm_1_contribution_1_contribution_total_amount');
    var label = $contributionAmount.closest('div.webform-component').find('label').html() || Drupal.t('Contribution');
    updateLineItem('civicrm_1_contribution_1', amount, label);
  }

  if ($contributionAmount.length) {
    calculateContributionAmount();
    $contributionAmount.on('change keyup', calculateContributionAmount);
  }
});
