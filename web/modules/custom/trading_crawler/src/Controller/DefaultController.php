<?php

namespace Drupal\trading_crawler\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Class DefaultController.
 */
class DefaultController extends ControllerBase {

  /**
   * Trading crawler.
   *
   * @return array
   *   Return tradingCrawler info.
   */
  public function trading() {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Implement method: tradingCrawler')
    ];
  }

}
