<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM and a single contact.
 *
 * @group webform_civicrm
 */
final class ContactSubmissionTest extends WebformCivicrmTestBase {

  /**
   * Test submitting a contact.
   *
   * @dataProvider dataContactValues
   */
  public function testSubmitWebform($contact_type, array $contact_values) {
    $this->assertArrayHasKey('contact', $contact_values, 'Test data must contain contact');
    $this->assertArrayHasKey('first_name', $contact_values['contact'], 'Test contact data must contain first_name');
    $this->assertArrayHasKey('last_name', $contact_values['contact'], 'Test contact data must contain last_name');

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->assertSession()->waitForText('Enable CiviCRM Processing');
    $this->assertSession()->waitForField('nid');
    $this->htmlOutput();
    $this->getSession()->getPage()->checkField('nid');
    $this->getSession()->getPage()->selectFieldOption('1_contact_type', strtolower($contact_type));
    $this->assertSession()->assertWaitOnAjaxRequest();

    // @see wf_crm_location_fields().
    /*$configurable_contact_field_groups = [
      'address' => 'city',
      'phone' => 'phone',
      'email' => 'email',
      'website' => 'url',
      'im' => 'name',
    ];
    foreach ($configurable_contact_field_groups as $field_group => $field_value_key) {
      if (isset($contact_values[$field_group])) {
        $this->assertTrue(is_array($contact_values[$field_group]));
        $this->assertTrue(isset($contact_values[$field_group][0]));
        $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_' . $field_group, count($contact_values[$field_group][0]));
        $this->assertSession()->assertWaitOnAjaxRequest();
        $this->htmlOutput();
        $this->assertSession()->checkboxChecked("civicrm_1_contact_1_{$field_group}_{$field_value_key}");
      }
    }*/
    $configurable_contact_field_groups = [
      'address',
      'phone',
      'email',
      'website',
    ];
    foreach ($configurable_contact_field_groups as $field_group) {
      if (isset($contact_values[$field_group])) {
        $this->assertTrue(is_array($contact_values[$field_group]));
        $this->assertTrue(isset($contact_values[$field_group][0]));
        // @ToDo - Let's just enable 1 for now (to support more location types this integer will need to change
        $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_' . $field_group, 1);
        $this->assertSession()->assertWaitOnAjaxRequest();
        $this->htmlOutput();
        foreach ($contact_values[$field_group] as $field => $field_value_key) {
          foreach (array_keys($field_value_key) as $civi_field) {
             // echo sprintf('<pre>%s</pre>', print_r("civicrm_1_contact_1_{$field_group}_{$civi_field}",true));
             $this->assertSession()->checkboxChecked("civicrm_1_contact_1_{$field_group}_{$civi_field}");
          }
        }
      }
    }

    $this->getSession()->getPage()->pressButton('Save Settings');
    $this->assertSession()->pageTextContains('Saved CiviCRM settings');

    $this->drupalLogout();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    foreach ($contact_values as $entity_type => $field_values) {
      foreach ($field_values as $field_name => $field_value) {
        if (is_array($field_value)) {
          foreach ($field_value as $key => $value) {
            $selector = "civicrm_1_contact_1_{$entity_type}_{$key}";
            $this->getSession()->getPage()->fillField($selector, $value);
          }
        }
        else {
          $selector = "civicrm_1_contact_1_{$entity_type}_{$field_name}";
          $this->getSession()->getPage()->fillField($selector, $field_value);
        }
      }
    }
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $contact_result = wf_civicrm_api('contact', 'get', [
      'sequential' => 1,
      'first_name' => $contact_values['contact']['first_name'],
      'last_name' => $contact_values['contact']['last_name'],
    ]);
    $result_debug = var_export($contact_result, TRUE);

    $this->assertArrayHasKey('count', $contact_result, $result_debug);
    $this->assertEquals(1, $contact_result['count'], $result_debug);
    $contact = $contact_result['values'][0];
    $this->assertEquals($contact_type, $contact['contact_type']);

    foreach ($contact_values['contact'] as $field_name => $field_value) {
      $this->assertEquals($field_value, $contact[$field_name], $result_debug);
    }
    // ToDo: isn't email it's own api query? Perhaps this is Primary?
    if (isset($contact_values['email'])) {
      $this->assertEquals($contact_values['email'][0]['email'], $contact['email']);
    }

    $api_address_result = wf_civicrm_api('address', 'get', [
      'sequential' => 1,
      'contact_id' => $contact['contact_id'],
      ]);
    $address = reset($api_address_result['values']);
    $this->assertEquals('Calgary', $address['city']);
    $this->assertEquals('T3H 4Y4', $address['postal_code']);

    /*foreach ($configurable_contact_field_groups as $field_group => $field_value_key) {
      if (isset($contact_values[$field_group])) {
        $api_result = wf_civicrm_api($field_group, 'get', [
          'sequential' => 1,
          'contact_id' => $contact['contact_id'],
        ]);
        $this->assertEquals(count($contact_values[$field_group]), $api_result['count']);
        foreach ($api_result['values'] as $key => $result_entity) {
          $this->assertEquals($contact_values[$field_group][$key][$field_value_key], $result_entity[$field_value_key]);
        }
      }
    }*/
  }

  /**
   * Data for the test.
   *
   * Each test returns the Contact type and array of contact values.
   *
   * It is setup that there is one contact, but there may be multiple values
   * for email, website, etc.
   *
   * @todo determine what "type" each email could be.
   *
   * contact_values:
   *  contact:
   *    first_name: foo
   *    last_name: bar
   *    nickname: baz
   *  email:
   *    - email: foo@example.com
   *      type: main
   *  website:
   *    - url: https://example.com
   *
   * @return \Generator
   *   The test data.
   */
  public function dataContactValues() {
   /* yield [
      'Individual',
      [
        'contact' => [
          'first_name' => 'Frederick',
          'last_name' => 'Pabst',
        ]
    ]];
    yield [
      'Individual',
      [
        'contact' => [
          'first_name' => 'Frederick',
          'last_name' => 'Pabst',
        ],
        'email' => [
          [
            'email' => 'fred@example.com',
          ]
        ],
    ]];
    yield [
      'Individual',
      [
        'contact' => [
          'first_name' => 'Frederick',
          'last_name' => 'Pabst',
        ],
        'website' => [
          [
            'url' => 'https://example.com',
          ]
        ],
    ]];
    yield [
      'Individual',
      [
        'contact' => [
          'first_name' => 'Frederick',
          'last_name' => 'Pabst',
        ],
        'phone' => [
          [
            'phone' => '555-555-5555',
          ]
        ],
    ]];*/
    yield [
      'Individual',
      [
        'contact' => [
          'first_name' => 'Frederick',
          'last_name' => 'Pabst',
        ],
        'email' => [
          [
            'email' => 'fred@example.com',
          ]
        ],
        'website' => [
          [
            'url' => 'https://example.com',
          ]
        ],
        'phone' => [
          [
            'phone' => '555-555-5555',
          ]
        ],
        'address' => [
          [
            'city' => 'Calgary',
            'postal_code' => 'T3H 4Y4',
          ]
        ],
    ]];
  }

}
