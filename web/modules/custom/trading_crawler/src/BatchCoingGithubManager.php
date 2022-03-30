<?php

namespace Drupal\trading_crawler;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\Node;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class BatchOperationsManager
 *
 * @package Drupal\batch_operations
 */
class BatchCoingGithubManager {

  /**
   * Init operation task by retrieving all content to be updated.
   *
   * @param array $args
   * @param array $context
   */
  public static function initOperation($args, &$context) {
    // Init variables.
    $limit = $args['limit'];
    $offset = (!empty($context['sandbox']['offset'])) ?
      $context['sandbox']['offset'] : 0;

    // Define total on first call.
    if (!isset($context['sandbox']['total'])) {
      $context['sandbox']['total'] = \Drupal::database()
        ->select('node')
        ->condition('type', 'crypto_basic_info')
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    /** @var array $results */
    $results = \Drupal::database()
      ->select('node', 'n')
      ->fields('n', ['nid'])
      ->range($offset, $limit)
      ->condition('type', 'crypto_basic_info')
      ->execute()
      ->fetchAll();

    // Setup results based on retrieved objects.
    $context['results'] = array_reduce($results,
      function ($carry, $object) {
        // Map object results extracted from previous query.
        $carry[$object->nid] = $object->nid;
        return $carry;
      }, $context['results']
    );

    // Redefine offset value.
    $context['sandbox']['offset'] = $offset + $limit;

    // Set current step as unfinished until offset is greater than total.
    $context['finished'] = 0;
    if ($context['sandbox']['offset'] >= $context['sandbox']['total']) {
      $context['finished'] = 1;
    }

    // \Drupal::logger('BatchManager')->notice('2.1. Retrived ' . $context['sandbox']['offset'] . ' nodes of ' . $context['sandbox']['total'] . ' from available crypto_basic_info nodes'); 

    // Setup info message to notify about current progress.
    $context['message'] = t(
      'Retrived @consumed nodes of @total from available crypto_basic_info nodes',
      [
        '@consumed' => $context['sandbox']['offset'],
        '@total' => $context['sandbox']['total'],
      ]
    );
  }



  /**
   * Process operation to update content retrieved from init operation.
   *
   * @param array $args
   * @param array $context
   */
  public static function createProcess($args, &$context) {
    // Define total on first call.
    if (!isset($context['sandbox']['total'])) {
      $context['sandbox']['total'] = count($context['results']);
    }

    // Init limit variable.
    $limit = $args['limit'];

    $today = date("Y-m-d");

    // Walk-through all crypto_basic_info in order to create coin_github_repository nodes.
    $count = 0;
    foreach ($context['results'] as $key => $crypto) {
      /** @var \Drupal\node\Entity\Node $nodeCoin */
      $nodeCoin = Node::load($crypto);

      /**
       * Create or update node of type coin_github_repository
       */
      $coinName = $nodeCoin->field_coin_name->value;
      $url = $nodeCoin->field_coin_url->uri;

      if (empty($url)) {
        // Remove current result.
        unset($context['results'][$key]);

        \Drupal::logger('BatchManager-Error')->notice('URL 1. empty for coin: ' . $coinName); 
        continue;
      }

      try {
        $html = file_get_contents($url);
      }
      catch (\InvalidArgumentException $e) {
        // Remove current result.
        unset($context['results'][$key]);

        \Drupal::logger('BatchManager-Error')->notice('Throwable error: ' . $e->getMessage()); 
        continue;
      }


      if (empty($html) && !is_string($html)) {
        // Remove current result.
        unset($context['results'][$key]);
      
        \Drupal::logger('BatchManager-Error')->notice('Html empty for url: ' . $url); 
        continue;
      }
      $crawler = new Crawler($html);
      $developer = $crawler->filter('body #developer-tab')->extract(['data-url']);

      if (empty($developer) || empty($developer[0])) {
        // Remove current result.
        unset($context['results'][$key]);

        \Drupal::logger('BatchManager-Error')->notice('Developer array is empty for coin: ' . $coinName . ' id: ' . $crypto . ' - ' . $url); 
        continue;
      }

      $dataDeveloperUrl = 'https://www.coingecko.com' . trim($developer[0]);

      try {
        $html = file_get_contents($dataDeveloperUrl);
      }
      catch (\InvalidArgumentException $e) {
        // Remove current result.
        unset($context['results'][$key]);

        \Drupal::logger('BatchManager-Error')->notice('Throwable error: ' . $e->getMessage());        
        continue;
      }

      if (empty($html) || !is_string($html)) {
        // Remove current result.
        unset($context['results'][$key]);

        \Drupal::logger('BatchManager-Error')->notice('Html empty for DEVELOPER url: ' . $dataDeveloperUrl); 
        continue;
      }
      $crawler = new Crawler($html);
      $dataDeveloper = $crawler->filter('body div.card-block');

      foreach ($dataDeveloper as $card) {
        $cardsHtml = $card->ownerDocument->saveHTML($card);
        $cardCrawler = new Crawler($cardsHtml);
        $dataCardTitle = $cardCrawler->filter('body div.card-block span:first-of-type a')->extract(['_text', 'href']);
        $dataCard = $cardCrawler->filter('body div.card-block div.b-b div.row div div:first-of-type')->extract(['_text']);
       
        $dataStars = intval($dataCard[0]);
        $dataWatchers = intval($dataCard[1]);
        $dataForks = intval($dataCard[2]);
        $dataContributors = intval($dataCard[3]);
        $dataMergedPR = intval($dataCard[4]);
        $dataClosedTotal = $dataCard[5];
        $dataClosedTotalArr = explode("/", $dataClosedTotal);

        // Check if already exists in database.
        $query = \Drupal::entityQuery('node')
          ->condition('type', 'coin_github_repository')
          ->condition('field_date_created', $today);
        $or = $query->orConditionGroup();
        $or->condition('field_coin_repository_url', $dataCardTitle[0][1]);
        $or->condition('field_coin_repository_name', $dataCardTitle[0][0]);
        $query->condition($or)
          ->range(0,1);
        $result = $query->execute();

        $nidRepo = NULL;
        foreach ($result as $k => $v) {
          $nidRepo = $v;
        }

        $now = DrupalDateTime::createFromTimestamp(time());
        $now->setTimezone(new \DateTimeZone('CET'));

        if (!empty($nidRepo)) {
          $nodeCoinRepo = Node::load($nidRepo);

          $nodeCoinRepo->set('field_date_time_updated', $now->format('Y-m-d\TH:i:s'));
          $nodeCoinRepo->title = 'Developer ' . trim($coinName) . ' ' . trim($dataCardTitle[0][0]);
          $nodeCoinRepo->field_coin_repository_name = trim($dataCardTitle[0][0]);
          $nodeCoinRepo->field_coin_repository_url = trim($dataCardTitle[0][1]);
          $nodeCoinRepo->field_stars = $dataStars;
          $nodeCoinRepo->field_watchers = $dataWatchers;
          $nodeCoinRepo->field_forks = $dataForks;
          $nodeCoinRepo->field_contributors = $dataContributors;
          $nodeCoinRepo->field_merged_pull_requests = $dataMergedPR;
          $nodeCoinRepo->field_closed_issues = intval($dataClosedTotalArr[0]);
          $nodeCoinRepo->field_total_issues = intval($dataClosedTotalArr[1]);       
          $nodeCoinRepo->field_note = 'Created from cron.';
          $nodeCoinRepo->field_crypto = $crypto;
        } else {
          $nodeCoinRepo = Node::create([
            'type' => 'coin_github_repository',
            'title' =>  'Developer ' . trim($coinName) . ' ' . trim($dataCardTitle[0][0]),
            'field_coin_repository_name' => trim($dataCardTitle[0][0]),
            'field_coin_repository_url' => trim($dataCardTitle[0][1]),
            'field_stars' => $dataStars,
            'field_watchers' => $dataWatchers,
            'field_forks' => $dataForks,
            'field_contributors' => $dataContributors,
            'field_merged_pull_requests' => $dataMergedPR,
            'field_closed_issues' => intval($dataClosedTotalArr[0]),
            'field_total_issues' => intval($dataClosedTotalArr[1]),          
            'field_note' => 'Created from cron.',
            'field_crypto' => $crypto, // Reference to Crypto Basic Info.
          ]);
        }

        $nodeCoinRepo->save();
      }

      // Increment count at one.
      $count++;

      // Remove current result.
      unset($context['results'][$key]);
      if ($count >= $limit) {
        break;
      }
    }


    \Drupal::logger('BatchManager')->notice('2.2. Updating articles... ' . count($context['results']) . ' pending.... <pre>' . print_r($context['results'], 1) . '</pre>'); 


    // Setup message to notify how many remaining articles.
    $context['message'] = t(
      'Updating articles... @total pending...',
      ['@total' => count($context['results'])]
    );

    // Set current step as unfinished until there's not results.
    $context['finished'] = (empty($context['results']));

    // When it is completed, then setup result as total amount updated.
    if ($context['finished']) {
      $context['results'] = $context['sandbox']['total'];
    }
  }

  /**
   * Final operation to define message after executed all batch operations.
   *
   * @param bool $success
   * @param array $results
   * @param array $operations
   */
  public static function finishProcess($success, $results, $operations) {

    \Drupal::logger('BatchManager')->notice('3. Update process of ' . $results . ' articles was completed.'); 

    // Setup final message after process is done.
    $message = ($success) ?
      t('Update process of @count articles was completed.', ['@count' => $results]) :
      t('Finished with an error.');
    \Drupal::messenger()->addMessage($message);
  }
}