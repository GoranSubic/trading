<?php

namespace Drupal\trading_crawler\Form;

use Drupal;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
   * Constructs a new BookAdminEditForm.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $node_storage
   *   The custom block storage.
   */
  public function __construct(EntityStorageInterface $node_storage) {
    $this->nodeStorage = $node_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $entity_type_manager = $container->get('entity_type.manager');
    return new static($entity_type_manager->getStorage('node'));
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
      '#empty_option' => $this->t('- Select field -'),
    ];

    $form['from_value'] = [
      '#type' => 'number',
      '#title' => $this->t('From'),
      '#default_value' => 0,
      '#min' => 0,
    ];

    $form['to_value'] = [
      '#type' => 'number',
      '#title' => $this->t('To'),
      '#default_value' => 1,
      '#min' => 0,
    ];

    // $form['actions']['#type'] = 'actions';
    
    $form['findCrypto'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find'),
      '#attributes' => [
        'class' => [
          'use-ajax',
        ],
      ],
      '#ajax' => [
        'callback' => '::findSubmitFunction',
        'wrapper' => 'replace-form-data',
        'method' => 'replace',
        'effect' => 'fade',
      ],
      'progress' => [
        'type' => 'throbber',
        'message' => $this->t('Verifying entry...'),
      ],
      '#button_type' => 'primary',
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

    if ($form_state->getValue('to_value') < 1) {
      $form_state->setErrorByName('to_value', $this->t('Search should have some range!'));
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
  }

  /**
   * Populate table with results.
   * 
   * @param array $form — An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * The current state of the form.
   * 
   */
  public function findSubmitFunction(array $form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    $checkByField = $form_state->getValue('check_by');
    $fromValue = $form_state->getValue('from_value');
    $toValue = $form_state->getValue('to_value');
    
    $query = $this->nodeStorage->getAggregateQuery();
    $query->condition('type', 'coin_github_repository');
    $query->condition($checkByField, $fromValue, '>=');
    $query->condition($checkByField, $toValue, '<=');
    $query->sort('field_crypto', 'DESC');
    $query->groupBy('field_crypto');
    $nIds = $query->execute();
    
    $rows = [];
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


    $form['results_table']['#rows'] = $rows;

    // $form_state->setRebuild(TRUE);
    // $form_state->set('results_table', $form['results_table']);

    // $this->messenger()->addMessage($this->t('Coin Github Repos NIDS - by field: ' . $checkByField . ' - found: ' . count($nIds)));

    $response->addCommand(new ReplaceCommand('#replace-form-data table', ''));
	  $response->addCommand(new AppendCommand('#replace-form-data', $form['results_table']));
	   
    return $response;
  }
}