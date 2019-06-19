<?php

namespace Drupal\Tests\webform_civicrm\Kernel;

class ApiReplacementTest  extends CiviCRMTestBase {

  protected function setUp() {
    parent::setUp();
    module_load_include('inc','webform_civicrm','includes/utils');
    // needed to load the wf_crm_aval function
    module_load_include('module','webform_civicrm','webform_civicrm');
  }

  /**
   *  test for wf_crm_state_abbr
   */
  public function test_CrmStateAbbr() {
    $nlId = 1152;
    $cuId = wf_crm_state_abbr('CU','id',$nlId);
    $this->assertNull($cuId,'Culemborg is not (yet) a separate province of the Netherlands');
    $glId = wf_crm_state_abbr('GL','id',$nlId);
    $this->assertNotNull($glId,'Gelderland should have a code');
    $glCode =  wf_crm_state_abbr($glId,'abbreviation',$nlId);
    $this->assertEquals('GL',$glCode);
  }
}