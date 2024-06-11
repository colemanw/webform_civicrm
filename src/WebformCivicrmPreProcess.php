<?php

namespace Drupal\webform_civicrm;

/**
 * @file
 * Front-end form pre-processor.
 */

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_civicrm\Plugin\WebformElement\CivicrmContact;
use Drupal\webform_civicrm\WebformCivicrmBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\webform\Utility\WebformHtmlHelper;
use Drupal\webform\Utility\WebformXss;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WebformCivicrmPreProcess extends WebformCivicrmBase implements WebformCivicrmPreProcessInterface {

  private $form;
  private $form_state;
  private $info = [];
  private $all_fields;
  private $all_sets;

  /**
   * @var \Drupal\webform_civicrm\UtilsInterface
   */
  protected $utils;

  public function __construct(UtilsInterface $utils) {
    $this->utils = $utils;
  }

  /**
   * Initialize form variables.
   *
   * @param array $form
   * @param FormStateInterface $form_state
   * @param WebformHandlerInterface $handler
   * @param WebformSubmissionInterface $webform_submission
   */
  function initialize(array &$form, FormStateInterface $form_state, WebformHandlerInterface $handler, WebformSubmissionInterface $webform_submission) {
    $this->form = &$form;
    $this->form_state = $form_state;
    $this->node = $handler->getWebform();
    $this->settings = $handler->getConfiguration()['settings'];
    $this->data = $this->settings['data'];
    $this->ent['contact'] = [];
    $this->all_fields = $this->utils->wf_crm_get_fields();
    $this->all_sets = $this->utils->wf_crm_get_fields('sets');
    $this->enabled = $this->utils->wf_crm_enabled_fields($handler->getWebform());
    $this->line_items = $form_state->get(['civicrm', 'line_items']) ?: [];
    $this->editingSubmission = $webform_submission->id();
    // If editing an existing submission, load entity data
    if ($this->editingSubmission) {
      $query = \Drupal::database()->select('webform_civicrm_submissions', 'wcs')
        ->fields('wcs', ['civicrm_data'])
        ->condition('sid', $this->editingSubmission, '=');
      $content = $query->execute()->fetchAssoc();
      if (!empty($content['civicrm_data'])) {
        $this->ent = unserialize($content['civicrm_data']);
      }
    }
    return $this;
  }

  /**
   * Alter front-end of webforms: Called by hook_form_alter() when rendering a civicrm-enabled webform
   * Add custom prefix.
   * Display messages.
   * Block users who should not have access.
   * Set webform default values.
   */
  public function alterForm() {
    // Add css & js
    $this->addResources();
    // Add validation handler
    // $this->form['#validate'][] = 'wf_crm_validate';
    // If this form is already in-process, IDs will be stored
    if (!empty($this->form_state->get('civicrm'))) {
      $this->ent = $this->form_state->get(['civicrm', 'ent']);
    }
    $submitted_contacts = [];
    // Keep track of cids across multipage forms
    if (!empty($this->form_state->getValue('submitted')) && $this->form_state->get(['webform','page_count']) > 1) {
      foreach ($this->enabled as $k => $v) {
        // @TODO review the usage of the existing element.
        if (substr($k, -8) == 'existing' && $this->form_state->getValue(['submitted', $v])) {
          list(, $c) = explode('_', $k);
          $val = $this->form_state->getValue(['submitted', $v]);
          $cid_data["cid$c"] = $this->ent['contact'][$c]['id'] = (int) (is_array($val) ? $val[0] : $val);
          $submitted_contacts[$c] = TRUE;
        }
      }
      if (!empty($cid_data)) {
        $this->form['#attributes']['data-civicrm-ids'] = Json::encode($cid_data);
      }
    }
    $this->form['#attributes']['data-form-defaults'] = Json::encode($this->getWebformDefaults());
    // Early return if the form (or page) was already submitted
    $triggering_element = $this->form_state->getTriggeringElement();

    // When user uploads a file using a managed_file element, avoid making any change to $this->form.
    if ($this->form_state->hasFileElement()
      && is_array($triggering_element['#submit'])
      && in_array('file_managed_file_submit', $triggering_element['#submit'], TRUE)) {
      return;
    }

    if ($triggering_element && $triggering_element['#id'] == 'edit-wizard-prev'
      || (empty($this->form_state->isRebuilding()) && !empty($this->form_state->getValues()) && empty($this->form['#submission']->is_draft))
      // When resuming from a draft
      || (!empty($this->form_state->getFormObject()->getEntity()->isDraft()) && empty($this->form_state->getUserInput()))
    ) {
      $this->fillForm($this->form, $this->form_state->getValues());
      return;
    }
    // If this is an edit op, use the original IDs and return
    if (isset($this->form['#submission']->sid) && $this->form['#submission']->is_draft != '1') {
      if (isset($this->form['#submission']->civicrm)) {
        $this->form_state['civicrm']['ent'] = $this->form['#submission']->civicrm;
        foreach ($this->form_state['civicrm']['ent']['contact'] as $c => $contact) {
          $this->info['contact'][$c]['contact'][1]['existing'] = wf_crm_aval($contact, 'id', 0);
        }
      }
      $this->fillForm($this->form);
      return;
    }

    // Search for existing contacts
    $counts_count = count($this->data['contact']);
    for ($c = 1; $c <= $counts_count; ++$c) {
      $this->ent['contact'][$c] = wf_crm_aval($this->ent, "contact:$c", []);
      $existing_component = $this->node->getElement("civicrm_{$c}_contact_1_contact_existing");
      // Search for contact if the user hasn't already chosen one
      if ($existing_component && empty($submitted_contacts[$c])) {
        $this->findContact($existing_component);
      }
      // Fill cid with '0' if unknown
      $this->ent['contact'][$c] += ['id' => 0];
    }
    // Search for other existing entities
    if (empty($this->form_state->get('civicrm'))) {
      if (!empty($this->data['case']['number_of_case'])) {
        $this->findExistingCases();
      }
      if (!empty($this->data['activity']['number_of_activity'])) {
        $this->findExistingActivities();
      }
      if (isset($this->data['grant']['number_of_grant'])) {
        $this->findExistingGrants();
      }
    }
    // Form alterations for unknown contacts
    if (empty($this->ent['contact'][1]['id'])) {
      if ($this->settings['prefix_unknown']) {
        $this->form['#prefix'] = wf_crm_aval($this->form, '#prefix', '') . '<div class="webform-civicrm-prefix contact-unknown">' . nl2br($this->settings['prefix_unknown']) . '</div>';
      }
      if ($this->settings['block_unknown_users']) {
        $this->form['submitted']['#access'] = $this->form['actions']['#access'] = FALSE;
        throw new AccessDeniedHttpException();
      }
    }
    if (!empty($this->data['participant_reg_type'])) {
      $this->populateEvents();
    }
    // Form alterations for known contacts
    foreach ($this->ent['contact'] as $c => $contact) {
      if (!empty($contact['id'])) {
        // Retrieve contact data
        $this->info['contact'][$c] = $this->loadContact($c);
        $this->info['contact'][$c]['contact'][1]['existing'] = $contact['id'];
        // Retrieve participant data
        if ($this->events && ($c == 1 || $this->data['participant_reg_type'] == 'separate')) {
          $this->loadParticipants($c);
        }
        // Membership
        if (!empty($this->data['membership'][$c]['number_of_membership'])) {
          $this->loadMemberships($c, $contact['id']);
        }
        if ($c == 1 && !empty($this->data['billing']['number_number_of_billing'])) {
          $this->info['contribution'][1]['contribution'][1] = $this->loadBillingAddress($contact['id']);
        }
      }
      // Load events from url if enabled, this will override loadParticipants
      if (!empty($this->data['reg_options']['allow_url_load'])) {
        $this->loadURLEvents($c);
      }
    }
    // Prefill other existing entities
    foreach (['case', 'activity', 'grant'] as $entity) {
      if (!empty($this->ent[$entity])) {
        $this->populateExistingEntity($entity);
      }
    }
    if (!empty($this->ent['contact'][1]['id'])) {
      if ($this->settings['prefix_known']) {
        $this->form['#prefix'] = wf_crm_aval($this->form, '#prefix', '') . '<div class="webform-civicrm-prefix contact-known">' . nl2br($this->replaceTokens($this->settings['prefix_known'], $this->info['contact'][1]['contact'][1])) . '</div>';
      }
      if ($this->settings['message']) {
        $this->showNotYouMessage($this->settings['message'], $this->info['contact'][1]['contact'][1]);
      }
    }
    // Store ids
    $this->form_state->set(['civicrm', 'ent'], $this->ent);
    // Set default values and other attributes for CiviCRM form elements
    // Passing $submitted helps avoid overwriting values that have been entered on a multi-step form
    $this->fillForm($this->form, $this->form_state->getValues());

    $enable_contribution = wf_crm_aval($this->data, 'contribution:1:contribution:1:enable_contribution');
    if ($enable_contribution && $this->form_state->get('current_page') === 'contribution_pagebreak' && empty($this->form['payment_section'])) {
      $this->form['payment_section'] = [
        'line_items' => $this->displayLineItems(),
        'billing_payment_block' => [
          '#markup' => Markup::create('<div class="crm-container crm-public" id="billing-payment-block"></div>'),
        ],
      ];

      // Copy first address values from Contact 1 to Billing Address.
      $form_data = $this->form_state->getValues();
      if (!empty($form_data) && !empty($this->data['contact'][1]['address'][1])) {
        $billing_fields = ['country_id', 'first_name', 'last_name', 'street_address', 'city', 'postal_code', 'state_province_id'];
        $billing_values = [];
        foreach ($billing_fields as $value) {
          $addressKey = 'civicrm_1_contact_1_address_' . $value;
          $contactKey = 'civicrm_1_contact_1_contact_' . $value;
          if (empty($this->node->getElement($addressKey)) && empty($this->node->getElement($contactKey))) {
            continue;
          }
          $billing_values[$value] = $form_data[$addressKey] ?? $form_data[$contactKey] ?? NULL;
        }
        $this->form['#attached']['drupalSettings']['webform_civicrm']['billing_values'] = $billing_values;
      }
    }
  }

  /**
   * Add necessary js & css to the form
   */
  private function addResources() {
    $this->form['#attached']['library'][] = 'webform_civicrm/forms';

    $default_country = $this->utils->wf_crm_get_civi_setting('defaultContactCountry', 1228);
    $billingAddress = wf_crm_aval($this->data, "billing:number_number_of_billing", FALSE);
    // Variables to push to the client-side
    $js_vars = [];
    // JS Cache eliminates the need for most ajax state/province callbacks
    foreach ($this->data['contact'] as $c) {
      if (!empty($c['number_of_address']) || !empty($billingAddress)) {
        $js_vars += [
          'defaultCountry' => $default_country,
          'defaultStates' => $this->utils->wf_crm_get_states($default_country),
          'noCountry' => t('- First Choose a Country -'),
          'callbackPath' => Url::fromUserInput('/webform-civicrm/js', ['alias' => TRUE])->toString(),
        ];
        break;
      }
    }
    // Preprocess contribution page
    if (!empty($this->data['contribution'])) {
      $this->addPaymentJs();
      $this->form['#attached']['library'][] = 'webform_civicrm/payment';
      $currency = wf_crm_aval($this->data, "contribution:1:currency");
      $contributionCallbackQuery = ['currency' => $currency, 'snippet' => 4, 'is_drupal_webform' => 1];
      $contributionCallbackUrl = 'base://civicrm/payment/form';
      $js_vars['processor_id_key'] = 'processor_id';
      if (!empty($this->data['contribution'][1]['contribution'][1]['is_test'])) {
        // RM: This is needed in order for CiviCRM to know that this is a 'test' (i.e. 'preview' action in CiviCRM) transaction - otherwise, CiviCRM defaults to 'live' and returns the payment form with public key for the live payment processor!
        $contributionCallbackQuery['action'] = \CRM_Core_Action::description(\CRM_Core_Action::PREVIEW);
      }

      $js_vars['contributionCallback'] = Url::fromUri($contributionCallbackUrl, ['query' => $contributionCallbackQuery, 'alias' => TRUE])->toString();
      // Add payment processor - note we have to search in 2 places because $this->loadMultipageData hasn't been run. Maybe it should be?
      $fid = 'civicrm_1_contribution_1_contribution_payment_processor_id';
      if (!empty($this->enabled[$fid])) {
        $js_vars['paymentProcessor'] = $this->getData($fid);
        // @todo Why does it matter if its enabled or not? Nothing is submitted.
        // $js_vars['paymentProcessor'] = wf_crm_aval($this->form_state, 'storage:submitted:' . $this->enabled[$fid]);
      }
      else {
        $js_vars['paymentProcessor'] = $this->getData($fid);
      }
    }

    $this->form['#attached']['drupalSettings']['webform_civicrm'] = $js_vars;
  }

  /**
   * Check if events are open to registration and take appropriate action
   */
  private function populateEvents() {
    $reg = NestedArray::getValue($this->data, ['reg_options']) ?: [];
    // Fetch events set in back-end
    $this->data += ['participant' => []];
    foreach ($this->data['participant'] as $e => $par) {
      if (empty($par['participant'])) {
        continue;
      }
      foreach ($par['participant'] as $n => $p) {
        if (empty($p['event_id'])) {
          continue;
        }
        // Handle multi-valued event selection
        foreach ((array) $p['event_id'] as $eid) {
          if ($eid = (int) $eid) {
            $this->events[$eid]['ended'] = TRUE;
            $this->events[$eid]['title'] = t('this event');
            $this->events[$eid]['count'] = (NestedArray::getValue($this->events, [$eid, 'count']) ?: 0) + 1;
            $status_fid = "civicrm_{$e}_participant_{$n}_participant_status_id";
            $this->events[$eid]['form'][] = [
              'contact' => $e,
              'num' => $n,
              'eid' => NULL,
              'status_id' => (array) $this->getData($status_fid, array_keys($this->getExposedOptions($status_fid))),
            ];
          }
        }
      }
    }
    // Add events exposed to the form
    foreach ($this->enabled as $field => $fid) {
      if (strpos($field, 'participant_event_id')) {
        foreach ($this->getExposedOptions($field) as $p => $label) {
          list($eid) = explode('-', $p);
          $this->events[$eid]['ended'] = TRUE;
          $this->events[$eid]['title'] = $label;
          list(, $e, , $n) = explode('_', $field);
          $status_fid = "civicrm_{$e}_participant_{$n}_participant_status_id";
          $this->events[$eid]['form'][] = [
            'contact' => $e,
            'num' => $n,
            'eid' => $p,
            'status_id' => (array) $this->getData($status_fid, array_keys($this->getExposedOptions($status_fid))),
          ];
        }
      }
    }
    if ($this->events && (!empty($reg['show_remaining']) || !empty($reg['block_form']))) {
      $this->loadEvents();
      foreach ($this->events as $eid => $event) {
        if ($event['ended']) {
          if (!empty($reg['show_remaining'])) {
            $this->setMessage(t('Sorry, %event has ended.', ['%event' => $event['title']]), 'warning');
          }
        }
        elseif (!empty($event['is_full'])) {
          if (!empty($reg['show_remaining'])) {
            $this->setMessage('<em>' . Html::escape($event['title']) . '</em>: ' . Html::escape($event['event_full_text']), 'warning');
          }
        }
        else {
          $reg['block_form'] = FALSE;
          if (!empty($event['max_participants']) && ($reg['show_remaining'] == 'always' || (int) $reg['show_remaining'] >= $event['available_places'])) {
            $this->setMessage(\Drupal::translation()->formatPlural($event['available_places'],
              '%event has 1 remaining space.',
              '%event has @count remaining spaces.',
              ['%event' => $event['title']]));
          }
        }
      }
      if ($reg['block_form']) {
        $this->form['submitted']['#access'] = $this->form['actions']['#access'] = FALSE;
        return;
      }
    }
  }

  /**
   * Load participant data for a contact
   * @param int $c
   */
  private function loadParticipants($c) {
    $status_types = $this->utils->wf_crm_apivalues('participant_status_type', 'get');
    $participants = $this->utils->wf_crm_apivalues('participant', 'get', [
      'contact_id' => $this->ent['contact'][$c]['id'],
      'event_id' => ['IN' => array_keys($this->events)],
    ]);
    foreach ($participants as $row) {
      $par = [];
      // v3 participant api returns some non-standard keys with participant_ prepended
      foreach (['id', 'event_id', 'role_id', 'status_id', 'campaign_id'] as $sel) {
        $par['participant'][1][$sel] = $row[$sel] = $row[$sel] ?? $row['participant_' . $sel] ?? NULL;
      }
      $par += $this->getCustomData($row['id'], 'Participant');
      $status = $status_types[$row['status_id']];
      foreach ($this->events[$row['event_id']]['form'] as $event) {
        if ($event['contact'] == $c) {
          // If status has been set by admin or exposed to the form, use it as a filter
          if (in_array($status['id'], $event['status_id']) ||
            // If status is "Automatic" (empty) then make sure the participant is registered
            (empty($event['status_id']) && $status['class'] != 'Negative')
          ) {
            $n = $event['contact'];
            $i = $event['num'];
            // Support multi-valued form elements as best we can
            $event_ids = wf_crm_aval($this->info, "participant:$n:participant:$i:event_id", []);
            if ($event['eid']) {
              $event_ids[] = $event['eid'];
            }
            foreach ($par as $k => $v) {
              $this->info['participant'][$n][$k][$i] = $v[1];
            }
            $this->info['participant'][$n]['participant'][$i]['event_id'] = $event_ids;
          }
        }
      }
    }
  }

  /**
   * Load event data for the url
   * @param int $c
   */
  private function loadURLEvents($c) {
    $n = $this->data['participant_reg_type'] == 'separate' ? $c : 1;
    $p = wf_crm_aval($this->data, "participant:$n:participant");
    if ($p) {
      $urlParam = '';
      foreach ($p as $e => $value) {
        $event_ids = [];
        // Get the available event list from the component
        $fid = "civicrm_{$c}_participant_{$e}_participant_event_id";
        $eids = [];
        foreach ($this->getExposedOptions($fid) as $eid => $title) {
          $id = explode('-', $eid);
          $eids[$id[0]] = $eid;
        }
        if ($this->data['participant_reg_type'] == 'all') {
          $urlParam = "event$e";
        }
        else {
          $urlParam = "c{$c}event{$e}";
        }
        foreach (explode(',', wf_crm_aval($_GET, $urlParam, '')) as $url_param_value) {
          if (isset($eids[$url_param_value])) {
            $event_ids[] = $eids[$url_param_value];
          }
        }
        $this->info['participant'][$c]['participant'][$e]['event_id'] = $event_ids;
      }
    }
  }

  /**
   * Load existing membership information and display a message to members.
   * @param int $c
   * @param int $cid
   */
  private function loadMemberships($c, $cid) {
    $today = date('Y-m-d');
    foreach ($this->findMemberships($cid) as $num => $membership) {
      // Only show 1 expired membership, and only if there are no active ones
      if (!$membership['is_active'] && $num) {
        break;
      }
      $type = $membership['membership_type_id'];
      $msg = t('@type membership for @contact has a status of "@status".', [
        '@type' => $this->getMembershipTypeField($type, 'name'),
        '@contact' => $this->info['contact'][$c]['contact'][1]['display_name'],
        '@status' => $membership['status'],
      ]);
      if (!empty($membership['end_date'])) {
        $end = ['@date' => \CRM_Utils_Date::customFormat($membership['end_date'])];
        $msg .= ' ' . ($membership['end_date'] > $today ? t('Expires @date.', $end) : t('Expired @date.', $end));
      }
      $this->setMessage($msg);
      for ($n = 1; $n <= $this->data['membership'][$c]['number_of_membership']; ++$n) {
        $fid = "civicrm_{$c}_membership_{$n}_membership_membership_type_id";
        if (empty($info['membership'][$c]['membership'][$n]) && ($this->getData($fid) == $type ||
            array_key_exists($type, $this->getExposedOptions($fid)))
        ) {
          $this->info['membership'][$c]['membership'][$n] = $membership;
          break;
        }
      }
    }
  }

  /**
   * Recursively walk through form array and set properties of CiviCRM fields
   *
   * @param array $elements (reference)
   *   FAPI form array
   * @param array $submitted
   *   Existing submission (optional)
   */
  private function fillForm(&$elements, $submitted = []) {
    foreach ($elements as $eid => &$element) {
      if ($eid[0] == '#' || !is_array($element)) {
        continue;
      }
      // Recurse through nested elements
      $this->fillForm($element, $submitted);
      if (empty($element['#type']) || $element['#type'] == 'fieldset') {
        continue;
      }
      if (!empty($element['#webform']) && $pieces = $this->utils->wf_crm_explode_key($eid)) {
        list( , $c, $ent, $n, $table, $name) = $pieces;
        if ($field = wf_crm_aval($this->all_fields, $table . '_' . $name)) {
          $element['#attributes']['class'][] = 'civicrm-enabled';
          if ($element['#type'] == 'webform_radios_other') {
            $element['other']['#attributes']['class'][] = 'civicrm-enabled';
          }
          $dt = NULL;
          if (!empty($field['data_type'])) {
            $dt = $element['#civicrm_data_type'] = $field['data_type'];
          }
          $element['#attributes']['data-civicrm-field-key'] = $eid;
          // For contribution line-items
          if ($table == 'contribution' && in_array($name, ['line_total', 'total_amount'])) {
            $element['#attributes']['class'][] = 'contribution-line-item';
          }
          // Provide live options from the Civi DB
          if (!empty($element['#civicrm_live_options']) && isset($element['#options'])) {
            $params = [
              'extra' => wf_crm_aval($field, 'extra', []) + wf_crm_aval($element, '#extra', []),
              'form_key' => $element['#webform_key'],
            ];
            $new = $this->utils->wf_crm_field_options($params, 'live_options', $this->data);
            $old = $element['#options'];
            $resave = FALSE;
            // If an item doesn't exist, we add it. If it's changed, we update it.
            // But we don't subtract items that have been removed in civi - this prevents
            // breaking the display of old submissions.
            foreach ($new as $k => $v) {
              if (!isset($old[$k]) || $old[$k] != $v) {
                $old[$k] = $v;
                $resave = TRUE;
              }
            }
            if ($resave) {
              $element['#extra']['extra']['items'] = $this->utils->wf_crm_array2str($old);
              //webform_component_update($component);
            }
            $element['#options'] = $new;
          }
          // If the user has already entered a value for this field, don't change it
          if (isset($this->info[$ent][$c][$table][$n][$name])
            && !(isset($element['#form_key']) && isset($submitted[$element['#form_key']]))) {
            $val = $this->info[$ent][$c][$table][$n][$name];
            if (($element['#type'] == 'checkboxes' || !empty($element['#multiple'])) && !is_array($val)) {
              $val = $this->utils->wf_crm_explode_multivalue_str($val);
            }
            if ($element['#type'] != 'checkboxes' && $element['#type'] != 'date'
              && empty($element['#multiple']) && is_array($val)) {
              // If there's more than one value for a non-multi field, pick the most appropriate
              if (!empty($element['#options']) && !empty(array_filter($val))) {
                foreach ($element['#options'] as $k => $v) {
                  if (in_array($k, $val)) {
                    $val = $k;
                    break;
                  }
                }
              }
              else {
                $val = array_pop($val);
              }
            }
            if ($element['#type'] == 'autocomplete' && is_string($val) && strlen($val)) {
              $options = $this->utils->wf_crm_field_options($element, '', $this->data);
              $val = wf_crm_aval($options, $val);
            }
            //Ensure value from webform default is loaded when the field is null in civicrm.
            if (!empty($element['#options']) && isset($val)) {
              if (!is_array($val) && !isset($element['#options'][$val])) {
                $val = NULL;
              }
              if ((is_null($val) || (is_array($val) && empty(array_filter($val)))) && !empty($this->form['#attributes']['data-form-defaults'])) {
                $formDefaults = Json::decode($this->form['#attributes']['data-form-defaults']);
                $key = str_replace('_', '-', $element['#form_key']);
                if (isset($formDefaults[$key])) {
                  $val = $formDefaults[$key];
                }
              }
            }
            // Contact image & custom file fields
            if ($dt == 'File') {
              $fileInfo = $this->getFileInfo($name, $val, $ent, $n);

              if ($fileInfo && in_array($element['#type'], ['file', 'managed_file'])) {
                $this->form['#attached']['drupalSettings']['webform_civicrm']['fileFields'][] = [
                  'eid' => $eid,
                  'fileInfo' => $fileInfo
                ];
                // Unset required attribute on the file if its loaded from civicrm.
                if (!empty($val)) {
                  $element['#required'] = FALSE;
                  unset($element['#states']['required']);
                }
              }
            }
            // Set value for "secure value" elements
            elseif ($element['#type'] == 'value') {
              $element['#value'] = $val;
            }
            elseif ($element['#type'] == 'datetime' || $element['#type'] == 'datelist') {
              if (!empty($val)) {
                $element['#default_value'] = DrupalDateTime::createFromTimestamp(strtotime($val));
              }
            }
            elseif ($element['#type'] == 'date') {
              // Must pass date only
              $element['#default_value'] = substr($val, 0, 10);
            }
            // Set default value
            else {
              $element['#default_value'] = $val;
            }
          }
          if (in_array($name, ['state_province_id', 'county_id', 'billing_address_state_province_id', 'billing_address_county_id'])) {
            $element['#attributes']['data-val'] = $element['#default_value'] ?? NULL;
          }
          if ($name == 'existing') {
            CivicrmContact::wf_crm_fill_contact_value($this->node, $element, $this->ent);
          }
        }
      }
    }
  }

  /**
   * Format line-items to appear on front-end of webform
   *
   * @return array
   *   The render array for line items table.
   */
  private function displayLineItems() {
    $rows = [];
    $total = 0;
    // Support hidden contribution field
    $fid = 'civicrm_1_contribution_1_contribution_total_amount';
    if (!$this->line_items && isset($this->enabled[$fid])) {
      $elements = $this->node->getElementsDecodedAndFlattened();
      $field = $elements[$this->enabled[$fid]];
      if ($field['#type'] === 'hidden') {
        $this->line_items[] = [
          'line_total' => $field['value'],
          'qty' => 1,
          'element' => 'civicrm_1_contribution_1',
          'label' => !empty($field['name']) ? $field['name'] : t('Contribution Amount'),
        ];
      }
    }

    $taxRates = \CRM_Core_PseudoConstant::getTaxRates();
    foreach ($this->line_items as $item) {
      $total += $item['line_total'];
      // Sales Tax integration
      if (!empty($item['financial_type_id'])) {
        $itemTaxRate = isset($taxRates[$item['financial_type_id']]) ? $taxRates[$item['financial_type_id']] : NULL;
      }
      else {
        $itemTaxRate = $this->tax_rate;
      }

      if ($itemTaxRate !== NULL) {
        // Change the line item label to display the tax rate it contains
        if (($itemTaxRate !== 0) && (\Civi::settings()->get('tax_display_settings') !== 'Do_not_show')) {
          $item['label'] .= ' (' . t('includes @rate @tax', ['@rate' => (float) $itemTaxRate . '%', '@tax' => \Civi::settings()->get('tax_term')]) . ')';
        }

        // Add calculation for financial type that contains tax
        $item['tax_amount'] = ($itemTaxRate / 100) * $item['line_total'];
        $total += $item['tax_amount'];
        $label = $item['label'] . ($item['qty'] > 1 ? " ({$item['qty']})" : '');
        $rows[] = [
          'data' => [$label, \CRM_Utils_Money::format($item['line_total'] + $item['tax_amount'])],
          'class' => [$item['element'], 'line-item'],
          'data-amount' => $item['line_total'] + $item['tax_amount'],
          'data-tax' => (float) $itemTaxRate,
        ];
      }
      else {
        $label = $item['label'] . ($item['qty'] > 1 ? " ({$item['qty']})" : '');
        $rows[] = [
          'data' => [$label, \CRM_Utils_Money::format($item['line_total'])],
          'class' => [$item['element'], 'line-item'],
          'data-amount' => $item['line_total'],
        ];
      }
    }
    $rows[] = [
      'data' => [t('Total'), \CRM_Utils_Money::format($total)],
      'id' => 'wf-crm-billing-total',
      'data-amount' => $total,
    ];

    return [
      '#type' => 'table',
      '#sticky' => FALSE,
      '#caption' => t('Payment Information'),
      '#header' => [],
      '#rows' => $rows,
      '#attributes' => ['id' => 'wf-crm-billing-items'],
    ];
  }

  /**
   * Find case ids based on url input or "existing case" settings
   */
  private function findExistingCases() {
    // Support legacy url param
    if (empty($_GET["case1"]) && !empty($_GET["caseid"])) {
      $_GET["case1"] = $_GET["caseid"];
    }
    for ($n = 1; $n <= $this->data['case']['number_of_case']; ++$n) {
      if (!empty($this->data['case'][$n]['case'][1]['client_id'])) {
        $clients = [];
        foreach ((array)$this->data['case'][$n]['case'][1]['client_id'] as $c) {
          if (!empty($this->ent['contact'][$c]['id'])) {
            $clients[] = $this->ent['contact'][$c]['id'];
          }
        }
        if ($clients) {
          // Populate via url argument
          if (isset($_GET["case$n"]) && $this->utils->wf_crm_is_positive($_GET["case$n"])) {
            $id = $_GET["case$n"];
            $item = $this->utils->wf_civicrm_api('case', 'getsingle', ['id' => $id]);
            if (array_intersect((array)wf_crm_aval($item, 'client_id'), $clients)) {
              $this->ent['case'][$n] = ['id' => $id];
            }
          }
          // Populate via search
          elseif (!empty($this->data['case'][$n]['existing_case_status'])) {
            $item = $this->findCaseForContact($clients, [
              'case_type_id' => wf_crm_aval($this->data['case'][$n], 'case:1:case_type_id'),
              'status_id' => $this->data['case'][$n]['existing_case_status']
            ]);
            if ($item) {
              $this->ent['case'][$n] = ['id' => $item['id']];
            }
          }
        }
      }
    }
  }

  /**
   * Find activity ids based on url input or "existing activity" settings
   */
  private function findExistingActivities() {
    $request = \Drupal::request();
    // Support legacy url param
    if (empty($request->query->get('activity1')) && !empty($request->query->get('aid'))) {
      $request->query->set('activity1', $request->query->get('aid'));
    }
    for ($n = 1; $n <= $this->data['activity']['number_of_activity']; ++$n) {
      if (!empty($this->data['activity'][$n]['activity'][1]['target_contact_id'])) {
        $targets = [];
        foreach ($this->data['activity'][$n]['activity'][1]['target_contact_id'] as $c) {
          if (!empty($this->ent['contact'][$c]['id'])) {
            $targets[] = $this->ent['contact'][$c]['id'];
          }
        }
        if ($targets) {
          if ($this->utils->wf_crm_is_positive($request->query->get("activity$n"))) {
            $id = $request->query->get("activity$n");
            $item = $this->utils->wf_civicrm_api('activity', 'getsingle', ['id' => $id, 'return' => ['target_contact_id']]);
            if (array_intersect($targets, $item['target_contact_id'])) {
              $this->ent['activity'][$n] = ['id' => $id];
            }
          }
          elseif (!empty($this->data['activity'][$n]['existing_activity_status'])) {
            // If the activity type hasn't been set, bail.
            if (empty($this->data['activity'][$n]['activity'][1]['activity_type_id'])) {
              $logger = \Drupal::logger('webform_civicrm');
              $logger->error("Activity type to update hasn't been set, so won't try to update activity. location = %1, webform activity number : %2", ['%1' => $request->getRequestUri(), '%2' => $n]);
              continue;
            }
            // The api doesn't accept an array of target contacts so we'll do it as a loop
            // If targets has more than one entry, the below could result in the wrong activity getting updated.
            foreach ($targets as $cid) {
              $params = [
                'sequential' => 1,
                'target_contact_id' => $cid,
                'status_id' => ['IN' => (array)$this->data['activity'][$n]['existing_activity_status']],
                'is_deleted' => '0',
                'is_current_revision' => '1',
                'options' => ['limit' => 1],
              ];
              $params['activity_type_id'] = $this->data['activity'][$n]['activity'][1]['activity_type_id'];
              $items = $this->utils->wf_crm_apivalues('activity', 'get', $params);
              if (isset($items[0]['id'])) {
                $this->ent['activity'][$n] = ['id' => $items[0]['id']];
                break;
              }
            }
          }
        }
      }
    }
  }

  /**
   * Find grant ids based on url input or "existing grant" settings
   */
  private function findExistingGrants() {
    for ($n = 1; $n <= $this->data['grant']['number_of_grant']; ++$n) {
      if (!empty($this->data['grant'][$n]['grant'][1]['contact_id'])) {
        $cid = $this->ent['contact'][$this->data['grant'][$n]['grant'][1]['contact_id']]['id'];
        if ($cid) {
          if (isset($_GET["grant$n"]) && $this->utils->wf_crm_is_positive($_GET["grant$n"])) {
            $id = $_GET["grant$n"];
            $item = $this->utils->wf_civicrm_api('grant', 'getsingle', ['id' => $id]);
            if ($cid == $item['contact_id']) {
              $this->ent['grant'][$n] = ['id' => $id];
            }
          }
          elseif (!empty($this->data['grant'][$n]['existing_grant_status'])) {
            $params = [
              'sequential' => 1,
              'contact_id' => $cid,
              'status_id' => ['IN' => (array)$this->data['grant'][$n]['existing_grant_status']],
              'options' => ['limit' => 1],
            ];
            if (!empty($this->data['grant'][$n]['grant'][1]['grant_type_id'])) {
              $params['grant_type_id'] = $this->data['grant'][$n]['grant'][1]['grant_type_id'];
            }
            $items = $this->utils->wf_crm_apivalues('grant', 'get', $params);
            if (isset($items[0]['id'])) {
              $this->ent['grant'][$n] = ['id' => $items[0]['id']];
            }
          }
        }
      }
    }
  }

  /**
   * Populate existing entity data
   * @param string $type entity type (activity, case, grant)
   */
  private function populateExistingEntity($type) {
    $items = [];
    foreach ($this->ent[$type] as $key => $item) {
      if (!empty($item['id'])) {
        $items[$key] = $item['id'];
      }
    }
    if ($items) {
      $values = $this->utils->wf_crm_apivalues($type, 'get', ['id' => ['IN' => array_values($items)]]);
      foreach ($items as $n => $id) {
        if (isset($values[$id])) {
          // Load core + custom data
          $this->info[$type][$n] = [$type => [1 => $values[$id]]] + $this->getCustomData($id, $type);
          // Load file attachments
          if (!empty($this->all_sets["{$type}upload"])) {
            foreach ($this->getAttachments($type, $id) as $f => $file) {
              $this->info[$type][$n]["{$type}upload"][1]["file_$f"] = $file['file_id'];
            }
          }
          // Load tags
          $tags = NULL;
          foreach (array_keys($this->enabled) as $fid) {
            if (strpos($fid, "civicrm_{$n}_{$type}_1_{$type}_tag") === 0) {
              $tags = $tags ?? $this->utils->wf_crm_apivalues('EntityTag', 'get', ['entity_id' => $id, 'entity_table' => "civicrm_" . $type, 'sequential' => 1], 'tag_id');
              $this->info[$type][$n][$type][1][str_replace("civicrm_{$n}_{$type}_1_{$type}_", '', $fid)] = $tags;
            }
          }
        }
      }
    }
  }

  /**
   * Wrapper for \Drupal::messenger()
   * Ensures we only set the message on the first page of the node display
   * @param $message
   * @param string $type
   */
  function setMessage($message, $type='status') {
    if (empty($_POST)) {
      \Drupal::messenger()->addStatus(WebformHtmlHelper::toHtmlMarkup($message, WebformXss::getHtmlTagList()));
    }
  }

  /**
   * Displays the admin-defined message with "not you?" link to known contacts
   *
   * @param string $message
   *   Raw message with tokens
   * @param array $contact
   *   CiviCRM contact array
   */
  private function showNotYouMessage($message, $contact) {
    $request = \Drupal::request();
    $message = $this->replaceTokens($message, $contact);
    preg_match_all('#\{([^}]+)\}#', $message, $matches);
    if (!empty($matches[0])) {
      $q = $request->query->all();
      unset($q['cs'], $q['cid'], $q['cid1']);
      if (empty($request->query->get('cid')) && empty($request->query->get('cid1'))) {
        $q['cid1'] = 0;
      }
      foreach ($matches[0] as $pos => $match) {
        $link = Link::createFromRoute($matches[1][$pos], '<current>', [], [
          'query' => $q
        ])->toString();
        $message = str_replace($match, $link, $message);
      }
    }
    $this->setMessage(Markup::create($message));
  }

  /**
   * Token replacement for form messages
   *
   * @param $str
   *   Raw message with tokens
   * @param $contact
   *   CiviCRM contact array
   * @return mixed
   */
  private function replaceTokens($str, $contact) {
    $tokens = $this->utils->wf_crm_get_fields('tokens');
    $values = [];
    foreach ($tokens as $k => &$t) {
      if (empty($contact[$k])) {
        $contact[$k] = '';
      }
      $value = $contact[$k];
      if (is_array($value)) {
        $value = implode(', ', $value);
      }
      $values[] = implode(' &amp; ', $this->utils->wf_crm_explode_multivalue_str($value));
      $t = "[$t]";
    }
    return str_ireplace($tokens, $values, $str);
  }

}
