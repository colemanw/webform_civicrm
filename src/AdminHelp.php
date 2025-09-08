<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Url;

/**
 * @file
 * Admin form inline-help.
 */

/**
 * Class AdminHelp
 * Adding a function to this class with the same name as a field will magically add pop-up help for that field to the admin form.
 */
class AdminHelp implements AdminHelpInterface {

  /**
   * @var \Drupal\webform_civicrm\UtilsInterface
   */
  protected $utils;

  public function __construct(UtilsInterface $utils) {
    $this->utils = $utils;
  }

  protected function intro() {
    return '<p>' .
      t('Create anything from a simple newsletter signup, to a complex multi-step registration system.') .
      '</p><strong>' .
      t('Getting Started:') .
      '</strong><ul>' .
      '<li>' . t('Enable fields for one or more contacts.') . '</li>' .
      '<li>' . t('Arrange and configure those fields on the "Webform" tab.') . '</li>' .
      '<li>' . t('Click the blue help icons to learn more.') . '</li>' .
      '<li><a href="https://docs.civicrm.org/webform-civicrm/en/latest/" target="_blank">' . t('Read the instructions.') . '</a></li>' .
      '</ul>';
  }

  protected function contact_existing() {
    return '<p>' .
      t('Gives many options for how this contact can be autofilled or selected. From the Webform tab you can edit this field to configure:') .'</p><ul>' .
      '<li>' . t('Widget: Determine whether to expose this field to the form as an autocomplete or select element, or hide it and pick the contact automatically.') . '</li>' .
      '<li>' . t('Default Value: Select a contact based on the current user, relationships, or other options.') . '</li>' .
      '<li>' . t('Filters: Limit the list of available choices from which this contact may be autofilled or selected.') . '</li>' .
      '<li>' . t('Show/Hide Fields: Control which other fields the user is allowed to edit and which will be hidden.') . '</li>' .
      '</ul>';
  }

  protected function contact_employer_id() {
    return '<p>' .
      t('Choose a webform contact of type "Organization" to be the employer for this individual.') .
      '</p><p>' .
      t('Use the "Existing Contact" field for that organization to enable autocomplete or selection of employers.') .
      '</p><p>' .
      t('You can also autofill the employer by configuring the organization\'s "Existing Contact" default value to be "Employer" relationship to Contact 1.') .
      '</p>';
  }

  protected function contact_image_url() {
    return '<p>' .
      t('Allows an image to be associated with a contact. This image will appear in CiviCRM, but the file is stored in Drupal. If the webform submission or entire webform were to be deleted, the image would be lost.') .
      '</p>';
  }

  protected function contact_contact_id() {
    return '<p>' .
      t('This read-only field can be used to as a token to generate links, for example to include an email link back to this form to update their info.') .
      '</p>';
  }

  protected function contact_user_id() {
    return '<p>' .
      t("This read-only field will load the contact's drupal user id. Works even for anonymous users following a checksum.") .
      '</p>';
  }

  protected function contribution_contact_id() {
    return '<p>' .
      t('The contribution record created after webform submission is assigned to this contact.') .
      '</p><p>' .
      t('Enable "-User Select-" option if you want user to select the payment contact while submitting the form.') .
      '</p><p>' .
      t('Please enable respective email fields on the contact tab. Webform submission may lead to fatal error if email value is not sent to the selected payment processor.') .
      '</p>';
  }

  protected function contact_external_identifier() {
    return $this->contact_contact_id();
  }

  protected function contact_source() {
    return '<p>' .
      t('This field will override the "Source Label" in "Additional Options".') .
      '</p>';
  }

  protected function contact_cs() {
    $this->contact_contact_id();
  }

  protected function matching_rule() {
    return '<p>' .
      t('This determines how an <em>unknown</em> contact will be handled when the webform is submitted.') .
      '</p><ul>' .
      '<li>' . t('Select the "Default Unsupervised" rule for the same duplicate matching used by CiviCRM event registration and contribution forms.') . '</li>' .
      '<li>' . t('Select a specific rule to customize how matching is performed.') . '</li>' .
      '<li>' . t('Or select "- None -" to always create a new contact.') . '</li>' .
      '</ul><p>' .
      t('Note: Matching rules are only used if the contact is not already selected via "Existing Contact" field.') . '</p><p>' .
      t('Note: Contacts are created (using this matching rule) before any other entities are stored as Contacts need to exist before e.g. shared addresses can be processed.') . '</p><p>' .
      t('Note: Ensure all fields that are configured in your matching rule are added and required on your Webform - else you may get unexpected results') .
      '</p>';
  }

  protected function address_street_address() {
    return '<p>' .
      t('Single field for whole address line: Street Name, Number and Number Suffix. Don\'t use together with the separate fields.') .
      '</p>';
  }

  protected function address_street_name() {
    return '<p>' .
      t('Use together with Street Number and Street Number Suffix as an alternative for Street Address.') .
      '</p>';
  }

  protected function address_street_number() {
    return '<p>' .
      t('Use together with Street Name and Street Number Suffix as an alternative for Street Address.') .
      '</p>';
  }

  protected function address_street_unit() {
    return '<p>' .
      t('Use together with Street Name and Street Number as an alternative for Street Address.') .
      '</p>';
  }

  protected function address_master_id() {
    return '<p>' .
      t('When selected, will hide fields for this address and use those of the other contact.') .
      '</p><p>' .
      t('Tip: In many use-cases it is desirable to show this field as a single checkbox. You can configure that by editing the field and removing all but one option (the one this contact is allowed to share) and re-labelling it something like "Same as my address".') .
      '</p>';
  }

  protected function contribution_payment_processor_id() {
    return '<p>' .
      t('Supported payment processors enabled on the contribution page are available here. "Pay Later" option allows the user to purchase events/memberships without entering a credit card.') .
      '</p><p>' .
      t("Note that only on-site credit card processors are currently supported on Webforms. Services that redirect to an external website, such as Paypal Standard, are not supported. Note: Recurring payments may or may not be supported by your Payment Processor.") .
      '</p>';
  }

  protected function contribution_total_amount() {
    return '<p>' .
      t('This amount will be in addition to any paid events and memberships.') .
      '</p>';
    $this->fee();
  }

  protected function contribution_frequency_unit() {
    return '<p>' .
      t('Frequency of Installments. ') .
      t('Set the frequency for the installments - options are: day, week, month, year - or make this a user-select element on the webform.') .
      '</p>';
  }

  protected function contribution_installments() {
    return '<p>' .
      t('Number of Installments. ') .
      t('Create a webform element that allows the Number of Installments to be specified: for example - total amount is paid in 10 installments. For a Contribution of unspecified duration/commitment use installments = 0.') .
      '</p>';
  }

  protected function contribution_billing_address_same_as() {
    return '<p>' .
      t('Provides a checkbox on the webform which copies the values from first address of Contact 1 to Billing section.') .
      '</p>';
  }

  protected function contribution_frequency_interval() {
    return '<p>' .
      t('Interval of Installments. ') .
      t('Create a webform elements that allows the Interval of Installments to be specified: for example - every second (month).') .
      '</p>';
  }

  protected function participant_fee_amount() {
    return '<p>' .
      t('Price for this event. If multiple events or participants are registered with this field, the amount will be multiplied per-person, per-event.') .
      '</p><p>' .
      t('Note that any event prices you have configured in CiviCRM are not imported into the Webform - you will need to reconfigure them here.') .
      '</p>';
    $this->fee();
  }

  protected function fee() {
    return '<p>' .
      t('Once added to the webform, this field can be configured in a number of ways by changing its settings.') .
      '</p><strong>' .
      t('Possible Widgets:') .
      '</strong><ul>' .
      '<li>' . t('Number (default): Allow the user to enter an amount, optionally constrained by min, max, and increments.') . '</li>' .
      '<li>' . t('Hidden: Set the amount without giving the user a choice.') . '</li>' .
      '<li>' . t('Select/Radios: Allow the user to choose from one of several options.') . '</li>' .
      '<li>' . t('MultiSelect/Checkboxes: Each choice the user selects will be added together.') . '</li>' .
      '<li>' . t('Grid: Configure multiple items and quantities.') . '</li>' .
      '</ul>';
  }

  protected function participant_reg_type() {
    return '<p>' .
      t('Registering as a group will set Contact 1 as the primary registrant. Registering participants separately gives finer control over which contacts register for what events.') .
      '</p><p>' .
      t('With only one contact on the form, there is no difference between these two options.') .
      '</p>';
  }

  protected function participant_event_id() {
    return '<p>' .
      t('Events can be selected here without giving the user a choice, or this field can be added to the form ("User Select").') .
      '</p><p>' .
      t('Click the + button to choose multiple events.') .
      '</p><p>' .
      t('On the form, this field could be represented as either single or multiselect (checkboxes or radios). Note: enabling this field as a multiselect (checkboxes) should only be done if all selectable events will have the same price, role, custom data, etc.') .
      '</p><p>' .
      t('"Live Options" can be enabled to keep the field up-to-date with all your organization\'s events, or you can hand-pick the events you wish to show.') .
      '</p>';
  }

  protected function participant_status_id() {
    return '<ul><li>' .
      t('In "Automatic" mode, status will be set to "Registered" (or "Pending" if the user chooses to "Pay Later" for events with a fee). The user will be able to cancel registration by re-visiting the form and de-selecting any events they are registered for.') .
      '</li><li>' .
      t('If a status is selected here, events will be autofilled only if the participant has that status.') .
      '</li><li>' .
      t('If this field is exposed to the webform ("User Select"), events will be autofilled only if the particiant status among the enabled options.') .
      '</li></ul>';
  }

  protected function reg_options_show_remaining() {
    return '<p>' .
      t('Display a message at the top of the form for each event with a registration limit or past end date.') .
      '</p>';
  }

  protected function reg_options_validate() {
    return '<p>' .
      t('Will not allow the form to be submitted if user registers for an event that is ended or full.') .
      '</p>';
  }

  protected function reg_options_block_form() {
    return '<p>' .
      t('Hide webform if all the events for the form are full or ended.') .
      '</p>';
  }

  protected function reg_options_disable_unregister() {
    return '<p>' .
      t('If this is selected, on "User Select mode", participants will not be unregistered from unchecked events.') .
      '</p>';
  }

  protected function reg_options_allow_url_load() {
    return '<p>' .
      t('Allow events in "User Select" mode to be auto-filled from URL.') .
      '</p>'.'<br /><p>'.t('Example for "Register all":') .
      '<br /><code>' . Url::fromUri("internal:/node", ['absolute' => TRUE])->toString() . '/{node.nid}?event1={event1.event_id},{event2.event_id}</code></p><br />'.
      t('Example for "Register separately":') .
      '<br /><code>' . Url::fromUri("internal:/node", ['absolute' => TRUE])->toString() .
      '/{node.nid}?c1event1={event1.event_id},{event2.event_id}&amp;c2event1={event3.event_id}</code></p>';
  }

  /**
   * Help text for disable primary setting.
   */
  protected function reg_options_disable_primary_participant() {
    return '<p>' .
      t('If enabled, Contact 1 will not be stored as primary participant for multiple registrations.') .
      '</p>';
  }

  protected function reg_options_show_past_events() {
    return '<p>' .
      t('To also display events that have ended, choose an option for how far in the past to search.') .
      '</p>';
  }

  protected function reg_options_show_future_events() {
    return '<p>' .
      t('To also display events in the future, choose an option for how far in the future to search.') .
      '</p>';
  }

  protected function reg_options_show_public_events() {
    return '<p>' .
      t('Choose whether to display events marked as Public, Private or all.') .
      '</p>';
  }

  protected function reg_options_title_display() {
    return '<p>' .
      t('Controls how events are displayed. Date formats can be further configured in
        <a href=":link">CiviCRM Date Settings</a>',
        [
          ':link' => Url::fromUri(
              'internal:/civicrm/admin/setting/date',
              ['query' => ['reset' => 1]]
            )->toString()
        ]
      ) .
      '</p><p>' .
      t('Note: End-date will automatically be omitted if it is the same as the start-date.') .
      '</p>';
  }

  protected function membership_membership_type_id() {
    return '<p>' .
      t('Fee will be calculated automatically based on selected membership type and number of terms chosen. A contribution page must be enabled to collect fees.') .
      '</p>';
  }

  protected function membership_status_id() {
    return '<p>' .
      t('If number of terms is enabled, status can be calculated automatically based on new/renewal status and payment.') .
      '</p>';
  }

  protected function membership_num_terms() {
    return '<p>' .
      t('Membership dates will be filled automatically by selecting terms. This can be overridden by entering dates manually.') .
      '</p><p>' .
      t('Note: Number of terms is required to calculate membership fees for paid memberships.') .
      '</p><p>'.
      t('If you choose to enter dates manually, enabling membership fee field will provide the price. Otherwise the membership will be free') .
      '</p>';
  }

  protected function membership_fee_amount() {
    return '<p>' .
      t('Price for this membership per term. If this field is enabled, the default minimum membership fee from CiviCRM membership type settings will not be loaded.') .
      '</p>';
    $this->fee();
  }

  protected function relationship_relationship_type_id() {
    return '<p>' .
      t('Click the + button to select more than one option.') .
      '</p><p>' .
      t('You may set options here and/or add this element to the webform ("User Select"). Options chosen here will be applied automatically and will not appear on the form.') .
      '</p><p>' .
      t('If relationship types are added as "-User Select-", un-selected options from the webform will expire the existing relationship on the contact.') .
      '</p>';
  }

  protected function multiselect_options() {
    return '<p>' .
      t('Click the + button to select more than one option.') .
      '</p><p>' .
      t('You may set options here and/or add this element to the webform ("User Select"). Options chosen here will be applied automatically and will not appear on the form.') .
      '</p>';
  }

  protected function webform_label() {
    return '<p>' .
      t('Labels help you keep track of the role of each contact on the form. For example, you might label Contact 1 "Parent", Contact 2 "Spouse" and Contact 3 "Child".') .
      '</p><p>' .
      t("Labels do not have to be shown to the end-user. By default they will be the title of each contact's fieldset, but you may rename (or remove) fieldsets without affecting this label.") .
      '</p>';
  }

  protected function activity_target_contact_id() {
    return '<p>' .
      t('Which contacts should be tagged as part of this activity?') .
      '</p>';
    $this->contact_reference();
  }

  protected function activity_source_contact_id() {
    return '<p>' .
      t('Choose "automatic" to have this activity attributed to the current user (or contact 1 if the user is anonymous).') .
      '</p>';
    $this->contact_reference();
  }

  protected function activity_assignee_contact_id() {
    \Drupal::service('civicrm')->initialize();
    if ($this->utils->wf_crm_get_civi_setting('activity_assignee_notification')) {
      return '<p>' . t('A copy of this activity will be emailed to the assignee.') . '</p>';
    }
    else {
      return '<p>' . t('Assignee notifications are currently disabled in CiviCRM; no email will be sent to the assignee.') . '</p>';
    }
    $this->contact_reference();
  }

  protected function activity_duration() {
    return '<p>' .
      t('Total time spent on this activity (in minutes).') .
      '</p>';
  }

  protected function existing_activity_status() {
    return '<p>' .
      t('If a matching activity of the chosen type already exists for Contact 1, it will be autofilled and updated.') .
      '</p><p>' .
      t('Note: an activity can also be autofilled by passing "activity1", etc. in the url.') .
      '</p>';
  }

  protected function existing_case_status() {
    return '<p>' .
      t('If a matching case of the chosen type already exists for the client, it will be autofilled and updated.') .
      '</p><p>' .
      t('Note: a case can also be autofilled by passing "case1", etc. in the url.') .
      '</p>';
  }

  protected function duplicate_case_status() {
    return '<p>' .
      t('Choosing this option means a new case will always be created even when an existing case has been selected. If an existing case has been selected the data for this case will NOT be updated.') .
      '</p><p>' .
      t('Useful if you want to pre-fill a form with existing case data, allow the user to make updates and then create a new case with their updates.') .
      '</p><p>' .
      t('Note: Populate the existing case by selecting an option from the Update Existing Case drop-down or by passing "case1=[caseid]" in the url etc.') .
      '</p>';
  }

  protected function existing_grant_status() {
    return '<p>' .
      t('If a matching grant of the chosen type already exists for the applicant, it will be autofilled and updated.') .
      '</p><p>' .
      t('Note: a grant can also be autofilled by passing "grant1", etc. in the url.') .
      '</p>';
  }

  protected function file_on_case() {
    return '<p>' .
      t('Add this activity to either a specific case from this webform, or an already existing case based on matching criteria.') .
      '</p><p>' .
      t('These options will not open a new case; configure the "Cases" section to do so.') .
      '</p>';
  }

  protected function case_medium_id() {
    return '<p>' .
      t('Medium for activities added to cases from this webform.') .
      '</p>';
  }

  protected function contact_reference() {
    return '<p>' .
      t('This is a contact reference field. Webform gives you a great deal of flexibility about how this field is displayed:') .
      '</p><ul>' .
      '<li>' . t('First choose a contact on this webform as the target for this field (or add a new contact to the form for that purpose).') . '</li>' .
      '<li>' . t('To enable a selection of contacts, enable the "Existing Contact" field for the selected contact.') . '</li>' .
      '<li>' . t('This allows the contact to be selected on the form via autocomplete or dropdown select, or hidden and set to always be the same contact.') . '</li>' .
      '<li>' . t('In rare cases you might want to expose the list of webform contacts ("User Select").') . '</li>' .
      '<li>' . t('There are many more possibilities, see "Existing Contact" field help for more information.') . '</li>' .
      '</ul>';
  }

  protected function fieldset_relationship() {
    return '<p>' .
      t('Relationships are created from higher-number contacts to lower-number contacts.') .
      '</p><p>' .
      t("Example: to create a relationship between Contact 3 and Contact 4, go to Contact 4's tab and select Number of Relationships: 3. This will give you the option to create relationships between Contact 4 and Contacts 1, 2, and 3, respectively.") .
      '</p>';
  }

  protected function contact_creation() {
    return '<p>' .
      t('CiviCRM requires at minimum a name or email address to create a new contact.') .
      '</p><p>' .
      t("Webform contacts that do not have these fields can be used for selection of existing contacts but not creating new ones.") .
      '</p>';
  }

  protected function contact_component_widget() {
    return '<ul>
      <li>' . t('Autocomplete will suggest names of contacts as the user types. Good for large numbers of contacts.') . '</li>
      <li>' . t('A select list will show all possible contacts in a dropdown menu. Good for small lists - use filters.') . '</li>
      <li>' . t('A static element will select a contact automatically without giving the user a choice. Use in conjunction with a default value setting or a cid passed in the url.') . '</li>
      <li>' . t('A contact id element is a simple field in which the CiviCRM contact id number can be entered.') . '</li>
      </ul>';
  }

  protected function contact_component_hide_fields() {
    return '<p>' .
      t('When an existing contact is selected or prepopulated, which fields should the user not be allowed to edit?') .
      '</p><p>' .
      t("This is useful for preventing changes to existing data.") .
      '</p>';
  }

  protected function dynamic_custom() {
    return '<p>' .
      t("This will add all fields from this custom group, and automatically update the webform whenever this group's custom fields are added, edited, or deleted in CiviCRM.") .
      '</p>';
  }

  protected function multivalue_fieldset_create_mode() {
    return '<p>' .
        t("Create/ Edit Mode (Default): Pre-populate the existing entry (if any) in order to allow user modifying it. If there isn't any existing entry, any value entered into the fieldset will be created as a new entry.") .
        '<br><br>' .
        t("Create Only Mode: Any value entered into the fieldset will be created as a new entry without overwriting any existing record.") .
        '</p>';
  }

  /**
   * Get help for a custom field
   */
  protected function custom($param) {
    list( , $id) = explode('_', $param);
    if (!is_numeric($id)) {
      return;
    }
    \Drupal::service('civicrm')->initialize();
    $help = '';
    $info = $this->utils->wf_civicrm_api('custom_field', 'getsingle', ['id' => $id]);
    if (!empty($info['help_pre'])) {
      $help .= '<p>' . $info['help_pre'] . '</p>';
    }
    if (!empty($info['help_post'])) {
      $help .= '<p>' . $info['help_post'] . '</p>';
    }
    return $help;
  }

  /**
   * Get help for a fieldset
   */
  protected function fieldset($param) {
    list( , $set) = explode('_', $param);
    \Drupal::service('civicrm')->initialize();
    $help = '';
    $sets = $this->utils->wf_crm_get_fields('sets');
    if (!empty($sets[$set]['help_text'])) {
      $help .= '<p>' . $sets[$set]['help_text'] . '</p>';
    }
    return $help;
  }

  /**
   * Get help text for the field.
   * @param string $topic
   */
  public function getHelpText($topic) {
    if (method_exists($this, $topic)) {
      return $this->$topic();
    }
    elseif (strpos($topic, 'custom_') === 0) {
      return $this->custom($topic);
    }
    elseif (strpos($topic, 'fieldset_') === 0) {
      return $this->fieldset($topic);
    }
    return '';
  }

  /**
   * Set help text on the field description.
   * @param array $field
   * @param string $topic
   */
  public function addHelpDescription(&$field, $topic) {
    $field['#description'] = $this->getHelpText($topic) ?? NULL;
  }

}
