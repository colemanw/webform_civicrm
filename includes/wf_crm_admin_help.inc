<?php

/**
 * @file
 * Admin form inline-help.
 */

class wf_crm_admin_help {

  static function contact_existing() {
    print '<p>' .
      t('Gives you many options for how a contact can be autofilled or selected. Once added to the form you can configure:') .'</p><ul>' .
      '<li>' . t('Widget: Determine whether to expose this field to the form as an autocomplete or select element, or hide it and pick the contact automatically.') . '</li>' .
      '<li>' . t('Default Value: Select a contact based on the current user, relationships, or other options.') . '</li>' .
      '<li>' . t('Filters: Limit the list of available choices from which this contact may be autofilled or selected.') . '</li>' .
      '<li>' . t('Show/Hide Fields: Control which other fields the user is allowed to edit and which will be hidden.') . '</li>' .
      '</ul>';
  }

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

  static function address_master_id() {
    print '<p>' .
      t('When selected, will hide other fields for this address and use those of the other contact.') .
      '</p><p>' .
      t('Tip: In many use-cases it is desirable to show this field as a single checkbox. You can configure that by editing the field and removing all but one option (the one this contact is allowed to share) and re-labelling it something like "Same as my address".') .
      '</p>';
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

  static function contribution_payment_processor_id() {
    print '<p>' .
      t("All payment processors enabled on the contribution page are available here. Note that only on-site credit card processors are supported on Webforms. Services that redirect to an external website, such as Paypal Standard, are not supported.") .
      '</p>';
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

  static function membership_membership_type_id() {
    print '<p>' .
      t('Fee will be calculated automatically based on selected membership type and number of terms chosen. A contribution page must be enabled to collect fees.') .
     '</p>';
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
      t('Labels help you keep track of the role of each contact on the form. For example, you might label Contact 1 "Parent", Contact 2 "Spouse" and Contact 3 "Child".') .
      '</p><p>' .
      t("Labels do not have to appear on the form. By default they will be the title of each contact's fieldset, but you may rename fieldsets without affecting this label.") .
      '</p>';
  }

  static function activity_target_contact_id() {
    print '<p>' .
      t('Which contacts should be tagged as part of this activity?') .
      '</p>';
  }

  static function activity_duration() {
    print '<p>' .
      t('Total time spent on this activity (in minutes).') .
      '</p>';
  }

  static function existing_activity_status() {
    print '<p>' .
      t('If any of these options are selected and a matching activity of the chosen type already exists for Contact 1, it will be autofilled and updated.') .
      '</p><p>' .
      t('Note: an activity can also be autofilled by passing "aid" in the url.') .
      '</p>';
  }

  static function existing_case_activity_status() {
    print '<p>' .
      t('If any of these options are selected and a matching activity of the chosen type already exists for this case, it will be autofilled and updated.') .
      '</p><p>' .
      t('Note: an activity can also be autofilled by passing "aid" in the url.') .
      '</p>';
  }

  static function existing_case_status() {
    print '<p>' .
      t('If any of these options are selected and a matching case of the chosen type already exists for the client, it will be autofilled and updated.') .
      '</p><p>' .
      t('Note: a case can also be autofilled by passing "caseid" in the url.') .
      '</p>';
  }

  static function case_medium_id() {
    print '<p>' .
      t('Medium for activities added to cases from this webform.') .
      '</p>';
  }

  static function case_creator_id() {
    print '<p>' .
      t('Owner of newly created cases.') .
      '</p>';
  }

  /**
   * Get help for a custom field
   */
  static function custom($field) {
    list( , $id) = explode('_', $field);
    if (!is_numeric($id)) {
      return;
    }
    civicrm_initialize();
    module_load_include('inc', 'webform_civicrm', 'includes/utils');
    $info = wf_civicrm_api('custom_field', 'getsingle', array('id' => $id));
    if (!empty($info['help_pre'])) {
      print '<p>' . $info['help_pre'] . '</p>';
    }
    if (!empty($info['help_post'])) {
      print '<p>' . $info['help_post'] . '</p>';
    }
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
  elseif (strpos($topic, 'custom_') === 0) {
    wf_crm_admin_help::custom($topic);
  }
  exit();
}
