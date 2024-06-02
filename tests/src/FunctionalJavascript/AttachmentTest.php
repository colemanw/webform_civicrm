<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;

/**
 * Tests submitting a Webform with CiviCRM: Contact with File.
 *
 * @group webform_civicrm
 */
final class AttachmentTest extends WebformCivicrmTestBase {

  protected static $filePrefix = NULL;

  /**
   * @var array
   */
  private $fileParams;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'webform',
    'webform_ui',
    'webform_civicrm',
    'file',
  ];

  private $_cg = [];
  private $_cf = [];

  protected function setUp(): void {
    parent::setUp();
    $this->cleanupFiles();
    $this->addAttachmentOnContact();
  }

  /**
   * @return string
   */
  public static function getFilePrefix() {
    if (!self::$filePrefix) {
      self::$filePrefix = "test_" . substr(md5(mt_rand()), 0, 7) . '_';
    }
    return self::$filePrefix;
  }

  protected function tearDown(): void {
    parent::tearDown();
    $this->cleanupFiles();
  }

  /**
   * Create file custom fields.
   */
  protected function createFileCustomField() {
    $this->_cg[1] = civicrm_api3('CustomGroup', 'create', [
      'title' => "Attach Files 1",
      'extends' => "Contact",
    ]);
    $this->_cg[2] = civicrm_api3('CustomGroup', 'create', [
      'title' => "Attach Files 2",
      'extends' => "Contact",
    ]);
    foreach (['File1', 'File2', 'File3'] as $f) {
      $this->_cf[$f] = civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $this->_cg[1]['id'],
        'label' => "Upload {$f} on cg1",
        'data_type' => "File",
        'html_type' => "File",
      ]);
    }
    foreach (['cg2File1', 'cg2File2', 'cg2File3'] as $f) {
      $this->_cf[$f] = civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $this->_cg[2]['id'],
        'label' => "Upload {$f} on cg2",
        'data_type' => "File",
        'html_type' => "File",
      ]);
    }
  }

  /**
   * Add 3 attachments to the contact.
   */
  protected function addAttachmentOnContact() {
    $this->createFileCustomField();
    $this->fileParams = $this->getFileParams();
    $cParams = [
      'id' => $this->rootUserCid,
    ];
    foreach ($this->fileParams as $name => $file) {
      $attachment = civicrm_api3('Attachment', 'create', $file);
      $cParams["custom_{$this->_cf[$name]['id']}"] = $attachment['id'];
    }
    civicrm_api3('Contact', 'create', $cParams);
  }


  /**
   * Check if all files are loaded on the webform.
   */
  public function testSubmitWebform() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->selectFieldOption("contact_1_number_of_cg{$this->_cg[1]['id']}", 'Yes');
    $this->getSession()->wait(1000);
    $this->htmlOutput();

    $this->getSession()->getPage()->selectFieldOption("contact_1_number_of_cg{$this->_cg[2]['id']}", 'Yes');
    $this->getSession()->wait(1000);
    $this->htmlOutput();

    // Enable custom fields.
    foreach ($this->_cf as $cf) {
      $this->getSession()->getPage()->checkField($cf['values'][$cf['id']]['label']);
      $this->assertSession()->checkboxChecked($cf['values'][$cf['id']]['label']);
    }
    $this->saveCiviCRMSettings();
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->htmlOutput();
    $this->assertPageNoErrorMessages();

    // Ensure all files are loaded on the form.
    foreach ($this->fileParams as $file) {
      $this->assertSession()->pageTextContains($file['name']);
    }

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }

  /**
   * @return array
   */
  public function getFileParams() {
    return [
      'File1' => [
        'name' => self::getFilePrefix() . 'file1.txt',
        'mime_type' => 'text/plain',
        'description' => 'My test description 1',
        'content' => 'My test content 1',
        'entity_id' => $this->rootUserCid,
        'entity_table' => "civicrm_contact",
      ],
      'File2' => [
        'name' => self::getFilePrefix() . 'file2.txt',
        'mime_type' => 'text/plain',
        'description' => 'My test description 2',
        'content' => 'My test content 2',
        'entity_id' => $this->rootUserCid,
        'entity_table' => "civicrm_contact",
      ],
      'File3' => [
        'name' => self::getFilePrefix() . 'file3.txt',
        'mime_type' => 'text/plain',
        'description' => 'My test description 3',
        'content' => 'My test content 3',
        'entity_id' => $this->rootUserCid,
        'entity_table' => "civicrm_contact",
      ],
      'cg2File1' => [
        'name' => self::getFilePrefix() . 'file1cg2.txt',
        'mime_type' => 'text/plain',
        'description' => 'CG2 - My test description 1',
        'content' => 'CG2 - My test content 1',
        'entity_id' => $this->rootUserCid,
        'entity_table' => "civicrm_contact",
      ],
      'cg2File2' => [
        'name' => self::getFilePrefix() . 'file2cg2.txt',
        'mime_type' => 'text/plain',
        'description' => 'CG2 - My test description 2',
        'content' => 'CG2 - My test content 2',
        'entity_id' => $this->rootUserCid,
        'entity_table' => "civicrm_contact",
      ],
      'cg2File3' => [
        'name' => self::getFilePrefix() . 'file3cg2.txt',
        'mime_type' => 'text/plain',
        'description' => 'CG2 - My test description 3',
        'content' => 'CG2 - My test content 3',
        'entity_id' => $this->rootUserCid,
        'entity_table' => "civicrm_contact",
      ],
    ];
  }

  /**
   * Cleanup files created during the test.
   */
  protected function cleanupFiles() {
    $config = \CRM_Core_Config::singleton();
    $files = (array) glob($config->customFileUploadDir . "/" . self::getFilePrefix() . "*");
    foreach ($files as $file) {
      unlink($file);
    }
  }

}
