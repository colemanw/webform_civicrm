<?php

namespace Drupal\Tests\webform_civicrm\Kernel;

use CRM_Core_DAO;

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

  function old_wf_crm_get_matching_rules($contact_type) {
    static $rules;
    $contact_type = ucfirst($contact_type);
    if (!$rules) {
      $rules = array_fill_keys(array('Individual', 'Organization', 'Household'), array());
      $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_dedupe_rule_group');
      while ($dao->fetch()) {
        $rules[$dao->contact_type][$dao->id] = $dao->title;
      }
    }
    return $rules[$contact_type];
  }

  public function test_CrmGetMatchingRules(){
    
    $types = ['Individual', 'Organization', 'Household'];
    foreach($types as $type) {
      $oldRules = $this->old_wf_crm_get_matching_rules($type);
      $newRules = wf_crm_get_matching_rules($type);
      $this->assertSame($oldRules, $newRules);
    }
    
   


  }
}