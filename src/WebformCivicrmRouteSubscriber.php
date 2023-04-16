<?php

namespace Drupal\webform_civicrm;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\webform\Controller\WebformSubmissionViewController;
use Symfony\Component\Routing\RouteCollection;

class WebformCivicrmRouteSubscriber extends RouteSubscriberBase {

  /**
   * Override title on webform submission page.
   *
   * {@inheritdoc}
   */
  public function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity.webform_submission.canonical')) {
      $route->setDefault('_title_callback', '\Drupal\webform_civicrm\Controller\WebformCivicrmSubmissionViewController::title');
    }
  }

}
