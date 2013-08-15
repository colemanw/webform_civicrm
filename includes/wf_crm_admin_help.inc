<?php

/**
 * @file
 * Admin form inline-help.
 */

class wf_crm_admin_help {

  static function contact_employer_id() {
    print '<p>' .
      t('Choose a contact of type "Organization" to be the employer for this individual.') .
      '</p><p>' .
      t('Use the "Existing Contact" field for that organization to enable autocomplete or select of employers.') .
      '</p><p>' .
      t('Set the default value to be "Employer" relationship to this individual.') .
      '</p>';
  }

  static function contact_image_URL() {
    print '<p>' .
      t('Allows an image to be associated with a contact. This image will appear in CiviCRM, but the file is stored in Drupal. If the webform submission or entire webform were to be deleted, the image would be lost.') .
      '</p>';
  }

  static function contact_contact_id() {
    print '<p>' .
      t('This hidden field can be used to as a token to generate links, for example to include an email link back to this form to update their info.') .
      '</p>';
  }

  static function contact_external_identifier() {
    self::contact_contact_id();
  }

  static function contact_cs() {
    self::contact_contact_id();
  }

  static function contribution_contribution_page_id() {
    print '<p>' .
      t("It is recommended to create a new contribution page solely for webform use. When configuring the page, most options will be irrelevant (such as profiles, premiums, widgets, etc.). Only the following need to be configured:") .
      '</p><ul>' .
      '<li>' . t('Title') . '</li>' .
      '<li>' . t('Financial Type') . '</li>' .
      '<li>' . t('Currency') . '</li>' .
      '<li>' . t('Payment Processor') . '</li>' .
      '<li>' . t('Email Receipt') . '</li>' .
      '</ul>';
  }

  static function contribution_total_amount() {
    print '<p>' .
      t('If selected, this amount will be in addition to any paid events and memberships.') .
     '</p>';
    self::fee();
  }

  static function participant_fee_amount() {
    print '<p>' .
      t('Price for this event. If multiple events or participants are registered with this field, the amount will be charged per-person, per-event.') .
     '</p>';
    self::fee();
  }

  static function fee() {
    print '<p>' .
      t('Once added to the webform, this field can be configured in a number of ways by changing its widget and settings:') .
     '</p><ul>' .
      '<li>' . t('Number (default): Allow the user to enter an amount, optionally constrained by min, max, and increments.') . '</li>' .
      '<li>' . t('Hidden: Set the amount without giving the user a choice.') . '</li>' .
      '<li>' . t('Select/Radios: Allow the user to choose from one of several options.') . '</li>' .
      '<li>' . t('MultiSelect/Checkboxes: Each choice the user selects will be added together.') . '</li>' .
      '</ul>';
  }

  static function membership_num_terms() {
    print '<p>' .
      t('Membership dates will be filled automatically by selecting terms. This can be overridden by enabling date fields and entering dates manually.') .
     '</p>';
  }
  static function multiselect_options() {
    print '<p>' .
      t('You may set options here and/or add this element to the webform ("user select"). If you do both, options set here will not appear on the form.') .
      '</p>';
  }

  static function webform_label() {
    print '<p>' .
      t('Labels help you keep track of the role of each contact on the form. For example, you might label contact 1 "Parent", contact 2 "Spouse" and contact 3 "Child".') .
      '</p><p>' .
      t("Labels do not have to appear on the form. By default they will be the title of each contact's fieldset, but you may rename fieldsets without affecting this label.") .
      '</p>';
  }

  /**
   * Copied from Core CiviCRM's templates/notifications.tpl
   * @return string
   */
  static function helpTemplate() {
    return '
      <div id="crm-notification-container" class="crm-container" style="display:none">
        <div id="crm-notification-alert" class="#{type}">
          <div class="icon ui-notify-close" title="' . t('close') . '"> </div>
          <a class="ui-notify-cross ui-notify-close" href="#" title="' . t('close') . '">x</a>
          <h1>#{title}</h1>
          <div class="notify-content">#{text}</div>
        </div>
      </div>
    ';
  }
}

function wf_crm_admin_help($topic) {
  if (method_exists('wf_crm_admin_help', $topic)) {
    wf_crm_admin_help::$topic();
  }
  exit();
}
