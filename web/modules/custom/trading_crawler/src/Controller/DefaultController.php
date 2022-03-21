<?php

namespace Drupal\trading_crawler\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DomCrawler\Crawler;

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

    \Drupal::logger('default_controller')->notice('In trading execution!!!'); 

    $html = file_get_contents("https://www.coingecko.com/");
    $crawler = new Crawler($html);
    $data = $crawler->filter('body table tr td:nth-of-type(3) a:first-of-type');

    $c = 0;
    $str = '<table><tbody>';
    foreach ($data->extract(['_text', 'href']) as $el) {
      $c++;
      if ($c > 10) break;

      $url = 'https://www.coingecko.com' . $el[1];
      $html = file_get_contents($url);
      $crawler = new Crawler($html);
      $developer = $crawler->filter('body #developer-tab')->extract(['data-url']);
//      var_dump($developer);die();

      $dataDeveloperUrl = 'https://www.coingecko.com' . $developer[0];
      $html = file_get_contents($dataDeveloperUrl);
      $crawler = new Crawler($html);
      $dataDeveloper = $crawler->filter('body div.card-block');
      $cardsHtml = '';
      foreach ($dataDeveloper as $card) {
        $cardsHtml .= $card->ownerDocument->saveHTML($card);
      }
//      var_dump($cardsHtml);die();

      $str .= '<tr>';
      $str .= '<td>' . $el[0] . '</td>';
      $str .= '<td>URL: <a href="' . $url . '" target="_blank">' . $url . '</a></td>';
      $str .= '<td>' . $cardsHtml . '</td>';
      $str .= '</tr>';
    }
    $str .= '</tbody></table>';

    return [
      '#type' => 'markup',
      '#markup' => $str,
    ];
  }

}
