<?php

namespace Drupal\wdb_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\wdb_core\Access\WdbResourceAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the WDB search form.
 *
 * This form builds the user interface for searching WDB data. The form
 * submission is handled by JavaScript, which makes an API call to the
 * search endpoint and dynamically displays the results.
 */
class WdbSearchForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Entity repository (for contextual translations of taxonomy terms).
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * Constructs a new WdbSearchForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityRepositoryInterface $entity_repository, protected WdbResourceAccess $resourceAccess) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('wdb_core.resource_access')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wdb_core_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $subsysname = NULL) {
    $form['#attached']['library'][] = 'wdb_core/wdb_search';

    // 1. Get query parameters from the URL to set default values.
    // Only known, scalar parameters are used. The page is built from them, so
    // it must be cached per query string, and per user for the option list.
    $request = $this->getRequest();
    $known = ['subsystem', 'lexical_category', 'include_children', 'realized_form', 'realized_form_op', 'basic_form', 'basic_form_op', 'sign', 'sign_op', 'op', 'page'];
    $params = array_filter(array_intersect_key($request->query->all(), array_flip($known)), 'is_scalar');
    $form['#cache']['contexts'][] = 'url.query_args';
    $form['#cache']['contexts'][] = 'user';

    // Handle the subsystem context from the URL.
    $subsystem_tid = NULL;
    if ($subsysname) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'vid' => 'subsystem',
        'name' => $subsysname,
      ]);
      if ($term = reset($terms)) {
        $subsystem_tid = $term->id();
      }
    }

    // 2. Define form elements.
    $form['search_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Search Conditions'),
    ];
    // --- Filter conditions ---
    $form['search_fieldset']['ref_filters']['subsystem'] = [
      '#type' => 'select',
      '#title' => $this->t('Subsystem'),
      // Offer only subsystems the user may read; the names of the others
      // are not shown.
      '#options' => array_intersect_key($this->getTaxonomyTermOptions('subsystem'), ['' => TRUE] + array_flip($this->resourceAccess->readableSubsystemIds())),
    // If a subsystem is in the URL, set it as default but keep it editable.
      '#default_value' => $subsystem_tid ?? ($params['subsystem'] ?? ''),
    ];
    $form['search_fieldset']['ref_filters']['lexical_category'] = [
      '#type' => 'select',
      '#title' => $this->t('Lexical Category'),
      '#options' => $this->getTaxonomyTermOptions('lexical_category', TRUE),
      '#default_value' => $params['lexical_category'] ?? '',
    ];
    // Ensure per-interface-language cache variation for translated options.
    $form['search_fieldset']['ref_filters']['lexical_category']['#cache']['contexts'][] = 'languages:language_interface';
    $form['search_fieldset']['ref_filters']['include_children'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include child categories in search'),
      '#default_value' => $params['include_children'] ?? 1,
    ];

    // --- Text input fields ---
    $match_options = [
      'CONTAINS' => $this->t('Contains'),
      'STARTS_WITH' => $this->t('Starts with'),
      'ENDS_WITH' => $this->t('Ends with'),
    ];
    $form['search_fieldset']['text_filters']['realized_form_wrapper']['realized_form'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Realized Form'),
      '#autocomplete_route_name' => 'wdb_core.autocomplete.realized_form',
      '#default_value' => $params['realized_form'] ?? '',
    ];
    $form['search_fieldset']['text_filters']['realized_form_wrapper']['realized_form_op'] = [
      '#type' => 'select',
      '#title' => $this->t('Match'),
      '#title_display' => 'invisible',
      '#options' => $match_options,
      '#default_value' => $params['realized_form_op'] ?? 'CONTAINS',
    ];

    $form['search_fieldset']['text_filters']['basic_form_wrapper']['basic_form'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Basic Form (Lemma)'),
      '#autocomplete_route_name' => 'wdb_core.autocomplete.basic_form',
      '#default_value' => $params['basic_form'] ?? '',
    ];
    $form['search_fieldset']['text_filters']['basic_form_wrapper']['basic_form_op'] = [
      '#type' => 'select',
      '#title' => $this->t('Match'),
      '#title_display' => 'invisible',
      '#options' => $match_options,
      '#default_value' => $params['basic_form_op'] ?? 'CONTAINS',
    ];

    $form['search_fieldset']['text_filters']['sign_wrapper']['sign'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sign Code'),
      '#autocomplete_route_name' => 'wdb_core.autocomplete.sign',
      '#default_value' => $params['sign'] ?? '',
    ];
    $form['search_fieldset']['text_filters']['sign_wrapper']['sign_op'] = [
      '#type' => 'select',
      '#title' => $this->t('Match'),
      '#title_display' => 'invisible',
      '#options' => $match_options,
      '#default_value' => $params['sign_op'] ?? 'CONTAINS',
    ];

    $form['search_fieldset']['text_filters']['operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Text Search Logic'),
      '#options' => ['AND' => 'AND', 'OR' => 'OR'],
      '#default_value' => $params['op'] ?? 'AND',
    ];

    // --- 3. Action buttons area ---
    $form['actions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wdb-search-actions']],
    ];
    $form['actions_wrapper']['limit'] = [
      '#type' => 'select',
      '#title' => $this->t('Results per page'),
      '#options' => [25 => 25, 50 => 50, 100 => 100, -1 => $this->t('All')],
      '#default_value' => 50,
    ];
    $form['actions_wrapper']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];

    // --- 4. Results display area ---
    $form['results_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'wdb-search-results-container'],
    ];
    $form['pager_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'wdb-search-pager-container'],
    ];

    // 4. Pass initial parameters to JavaScript.
    if (!empty($params)) {
      $form['#attached']['drupalSettings']['wdb_core']['search']['initial_params'] = $params;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This form is submitted via JavaScript, so this method is intentionally
    // left empty. The actual search is handled by the SearchApiController.
  }

  /**
   * Helper function to get options for a taxonomy term select list.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param bool $show_depth
   *   Whether to indent options based on their depth in the hierarchy.
   *
   * @return array
   *   An array of options suitable for a select list.
   */
  private function getTaxonomyTermOptions(string $vid, bool $show_depth = FALSE): array {
    $options = ['' => $this->t('- Any -')];
    // Use taxonomy term storage loadTree (TermStorageInterface method).
    $taxonomy_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    if (!method_exists($taxonomy_storage, 'loadTree')) {
      return $options;
    }
    $tree = $taxonomy_storage->loadTree($vid);
    if (empty($tree)) {
      return $options;
    }
    $tids = [];
    foreach ($tree as $item) {
      if (isset($item->tid)) {
        $tids[] = $item->tid;
      }
    }
    $loaded = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    foreach ($tree as $item) {
      if (!isset($item->tid)) {
        continue;
      }
      $term_entity = $loaded[$item->tid] ?? NULL;
      $translated_label = NULL;
      if ($term_entity) {
        $translated = $this->entityRepository->getTranslationFromContext($term_entity);
        $translated_label = $translated->label();
      }
      $base_label = $translated_label ?? ($item->name ?? '');
      $label = $show_depth ? str_repeat('--', (int) $item->depth) . ' ' . $base_label : $base_label;
      $options[$item->tid] = $label;
    }
    return $options;
  }

}
