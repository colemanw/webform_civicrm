<?php

/**
 * @file
 * Result submissions override for Webform_CiviCRM
 * Displays real names instead of user names on CiviCRM enabled forms
 * NOTE: This template override is a temporary fix until drupal.org/node/1067486 is resolved
 * TODO: Once webform module has the necessary username theme funciton in place, this template will not be necessary
 */

if (count($table['#rows']) && !empty($node->webform_civicrm)) {
  $access = user_access('access CiviCRM');
  foreach ($table['#rows'] as &$row) {
    if (!empty($submissions[$row[0]]->civicrm['contact_id']) && !empty($submissions[$row[0]]->civicrm['display_name'])) {
      if ($access) {
        $row[2] = l($submissions[$row[0]]->civicrm['display_name'], 'civicrm/contact/view', array('query' => array('cid' => $submissions[$row[0]]->civicrm['contact_id'], 'reset' => 1)));
      }
      else {
        $row[2] = $submissions[$row[0]]->civicrm['display_name'];
      }
    }
  }
}

/**
 * Standard webform template follows
 */

drupal_add_css(drupal_get_path('module', 'webform') . '/css/webform-admin.css', 'theme', 'all', FALSE);
?>

<?php if (count($table['#rows'])): ?>
  <?php print theme('webform_results_per_page', $total_count, $pager_count); ?>
  <?php print theme('table', $table['#header'], $table['#rows']); ?>
<?php else: ?>
  <?php print t('There are no submissions for this form. <a href="!url">View this form</a>.', array('!url' => url('node/' . $node->nid))); ?>
<?php endif; ?>

<?php if ($is_submissions): ?>
  <?php print theme('links', array('webform' => array('title' => t('Go back to the form'), 'href' => 'node/' . $node->nid))); ?>
<?php endif; ?>

<?php if ($pager_count): ?>
  <?php print theme('pager', NULL, $pager_count, 0); ?>
<?php endif; ?>