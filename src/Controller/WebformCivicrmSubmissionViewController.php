<?php

namespace Drupal\webform_civicrm\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\webform\Controller\WebformSubmissionViewController;
use Drupal\Core\Link;

class WebformCivicrmSubmissionViewController extends WebformSubmissionViewController {

  /**
   * @inheritdoc
   */
  public function title(EntityInterface $webform_submission, $duplicate = FALSE) {
    $title = parent::title($webform_submission, $duplicate);
    if (!empty($webform_submission->getData()['civicrm'])) {
      $data = $webform_submission->getData()['civicrm'];
      if (!empty($data['contact'][1]['display_name'])) {
        return $this->t('@title by @name', ['@title' => $title, '@name' => $data['contact'][1]['display_name']]);
      }
    }
    return $title;
  }

}
