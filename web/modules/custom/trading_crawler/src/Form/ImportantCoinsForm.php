<?php

namespace Drupal\trading_crawler\Form;

use Drupal;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ImportantCoinsForm extends FormBase
{

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Constructs a new ImportantCoinsForm.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $node_storage
   *   The custom block storage.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager service.
   *
   */
  public function __construct(EntityStorageInterface $node_storage, PagerManagerInterface $pagerManager) {
    $this->nodeStorage = $node_storage;
    $this->pagerManager = $pagerManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $entityTypeManager = $container->get('entity_type.manager');
    $pagerManager = $container->get('pager.manager');
    return new static($entityTypeManager->getStorage('node'), $pagerManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return "important_coins_form"; 
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $session = $this->getRequest()->getSession();

    $pagerValue = (int)($this->getRequest()->get('page'));
    $allNids = $session->get('all_nids');

    $checkByField = $session->get('check_by');
    $fromValue = $session->get('from_value');
    $toValue = $session->get('to_value');
    
    $form['#attached']['library'][] = 'trading_crawler/important-coins-form-js';

    $definitions = Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'coin_github_repository');

    foreach ($definitions as $key => $field_name) {
      if ('integer' === $field_name->getType() && str_contains($key, 'field_')) {
        $values[$key] = $field_name->getLabel();
      }
    }

    $form['check_by'] = [
      '#type' => 'select',
      '#title' => $this->t('Find by field'),
      '#options' => $values,
      '#default_value' => $checkByField ? $checkByField : '',
      '#empty_option' => $this->t('- Select field -'),
    ];

    $form['from_value'] = [
      '#type' => 'number',
      '#title' => $this->t('From'),
      '#default_value' => $fromValue ? $fromValue : 0,
      '#min' => 0,
    ];

    $form['to_value'] = [
      '#type' => 'number',
      '#title' => $this->t('To'),
      '#default_value' => $toValue ? $toValue : 0,
      '#min' => 0,
    ];

    $form['actions']['#type'] = 'actions';
    
    $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Show results'),
        '#button_type' => 'primary',
        '#attributes' => [
          'class' => ['check-important-coins',],
        ]
    ];

    $header = [
      'coin_name' => $this->t('Coin Name'),
      'coin_ticker' => $this->t('Coin Ticker'),
      'coin_url' => $this->t('Coin URL'),
      'date_created' => $this->t('Date Created'),
      'set_priority' => $this->t('Set Priority'),
    ];
    $form['results_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => $this->t('There are no results.'),
      '#prefix' => '<div id="replace-form-data">',
      '#suffix' => '</div>',
    ];
  
    if (!empty($form_state->getValue('results_table'))) {
      $form['results_table'] = $form_state->getValue('results_table');
    }

    if (!empty($form_state->getValue('pager'))) {
      $form['pager'] = $form_state->getValue('pager');
    } else if (empty($form_state->getValue('results_table')) && $pagerValue !== NULL && !empty($allNids)) {
      // Build pager after choosing pager link
      $perPage = 10;
      $rows = [];
      $pagerNids = $this->pagerArray($allNids, $perPage, $pagerValue);
      $this->createRows($pagerNids, $rows);
      $form['results_table']['#rows'] = $rows;

      $form['pager'] = [
        '#type' => 'pager',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    if (empty($form_state->getValue('check_by'))) {
      $form_state->setErrorByName('check_by', $this->t('You have to select field to check!'));
    }

    if ($form_state->getValue('to_value') < 0 || $form_state->getValue('from_value') < 0) {
      $form_state->setErrorByName('to_value', $this->t('Search could not be lower than 0!'));
    }

    if ($form_state->getValue('to_value') < $form_state->getValue('from_value')) {
      $form_state->setErrorByName('to_value', $this->t('Search should be with sense!'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $session = $this->getRequest()->getSession();

    $checkByField = $form_state->getValue('check_by');
    $fromValue = $form_state->getValue('from_value');
    $toValue = $form_state->getValue('to_value');

    $session->set('check_by', $checkByField);
    $session->set('from_value', $fromValue);
    $session->set('to_value', $toValue);
    
    $query = $this->nodeStorage->getAggregateQuery();
    $query->condition('type', 'coin_github_repository')
      ->condition($checkByField, $fromValue, '>=')
      ->condition($checkByField, $toValue, '<=')
      ->sort('field_coin_repository_name', 'ASC')
      ->groupBy('field_crypto');
    
    $allNids = $query->execute();
    $session->set('all_nids', $allNids);

    $query->pager();
    $nIds = $query->execute();

    $rows = [];
    $this->createRows($nIds, $rows);

    $form['results_table']['#rows'] = $rows;
    $form_state->setValue('results_table', $form['results_table']);
    
    $form['pager'] = [
      '#type' => 'pager',
    ];
    $form_state->setValue('pager', $form['pager']);

    $form_state->setRebuild(TRUE);
  }

  /**
   * Build rows for table
   * 
   * @param array $items
   *   Array of node id - from pager.
   * @param array &$rows
   *   Empty array to populate - table rows.
   * 
   * @return array
   *   Table rows.
   */
  public function createRows(array $nIds, array &$rows): array
  {
    foreach ($nIds as $data) {
      $cryptoId = $data['field_crypto_target_id'];
      $node = $this->nodeStorage->load($cryptoId);

      if (!empty($node)) {
        $rows[$cryptoId] = [
          'coin_name' => $node->get('field_coin_name')->value, 
          'coin_ticker' => $node->get('field_coin_ticker')->value, 
          'coin_url' => $node->get('field_coin_url')->uri,
          'date_created' => $node->get('field_date_created')->value,
          'set_priority' => ($node->hasField('field_priority_1') && $node->get('field_priority_1')->value) 
                              ? $this->t('<a id="crypto-' . $cryptoId . '" class="use-ajax" href="/ajax-call/change-priority-one/' . $cryptoId . '"> Yes </a>') 
                              : $this->t('<a id="crypto-' . $cryptoId . '" class="use-ajax" href="/ajax-call/change-priority-one/' . $cryptoId . '"> No </a>'),
        ];
      }
    }

    return $rows;
  }

  /**
   * Returns pager array.
   * 
   * @param array $items
   *   Array of node id - all found by query.
   * @param int $itemsPerPage
   *   Number of rows to render in table.
   * @param int $currentPage
   *   The pager element index.
   *
   * @return array
   *   Array of node id - items for table rows.
   */
  public function pagerArray(array $items, int $itemsPerPage, int $currentPage): array
  {
    // Get total items count.
    $total = count($items);
    $pager = $this->pagerManager->createPager($total, $itemsPerPage);
    // Get the number of the current page.
    // $currentPage = $pager->getCurrentPage();

    // Split an array into chunks.
    $chunks = array_chunk($items, $itemsPerPage);

    $countChunks = count($chunks);
    if ($currentPage > $countChunks - 1) {
      $currentPage = $countChunks - 1;
    }

    // Return current group item.
    $currentPageItems = $chunks[$currentPage];
    return $currentPageItems;
  }

}