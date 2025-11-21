<?php

namespace Drupal\wdb_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Entity\WdbLabel;
use Drupal\wdb_core\Entity\WdbSource;
use Drupal\wdb_core\Entity\WdbSignInterpretation;
use Drupal\wdb_core\Entity\WdbWordUnit;
use Drupal\wdb_core\Entity\WdbWordMap;
use Drupal\wdb_core\Lib\HullJsPhp\HullPHP;
use Drupal\wdb_cantaloupe_auth\Service\TokenManagerInterface;

/**
 * Service class for handling WDB data-related operations.
 *
 * This service provides methods to fetch and structure complex data sets
 * involving multiple WDB entities, for use in controllers and other services.
 */
class WdbDataService {
  use StringTranslationTrait;


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The HullPHP calculation service.
   *
   * @var \Drupal\wdb_core\Lib\HullJsPhp\HullPHP
   */
  protected HullPHP $hullCalculator;

  /**
   * Optional token manager for signed IIIF URLs.
   */
  protected ?TokenManagerInterface $tokenManager;

  /**
   * The account proxy for the current request.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Per-request cache of issued IIIF tokens keyed by user/subsystem/image.
   *
   * @var array<string,string|null>
   */
  protected array $iiifTokenCache = [];

  /**
   * Constructs a new WdbDataService object.
   *
   * Note: Optional parameters must appear after required ones to avoid
   * deprecation warnings in recent PHP versions.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\wdb_core\Lib\HullJsPhp\HullPHP $hull_calculator
   *   The HullPHP calculation service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user proxy for issuing user-scoped tokens.
   * @param \Drupal\wdb_cantaloupe_auth\Service\TokenManagerInterface|null $token_manager
   *   (optional) The token manager when the cantaloupe auth module is enabled.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, HullPHP $hull_calculator, AccountProxyInterface $current_user, ?TokenManagerInterface $token_manager = NULL) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('wdb_core');
    $this->hullCalculator = $hull_calculator;
    $this->currentUser = $current_user;
    $this->tokenManager = $token_manager;
  }

  /**
   * Retrieves all WdbAnnotationPage entities for a given source.
   *
   * Results are sorted by page number.
   *
   * @param \Drupal\wdb_core\Entity\WdbSource $source_entity
   *   The source entity.
   *
   * @return \Drupal\wdb_core\Entity\WdbAnnotationPage[]
   *   An array of sorted WdbAnnotationPage entities.
   */
  public function getAllPagesForSource(WdbSource $source_entity): array {
    $storage = $this->entityTypeManager->getStorage('wdb_annotation_page');

    $query = $storage->getQuery()
      ->condition('source_ref', $source_entity->id())
      ->sort('page_number', 'ASC')
      ->accessCheck(FALSE);

    $ids = $query->execute();

    return !empty($ids) ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Returns the token payload for front-end consumers.
   */
  public function getIiifAuthContext(string $subsysname, string $image_identifier): array {
    if (!$this->tokenManager) {
      return [];
    }
    $token = $this->getIiifToken($subsysname, $image_identifier);
    if (!$token) {
      return [];
    }
    $context = [
      'token' => $token,
      'param' => $this->tokenManager->getQueryParameterName(),
    ];
    $ttl = $this->getIiifTokenTtl();
    if ($ttl !== NULL) {
      $context['ttl'] = $ttl;
    }
    return $context;
  }

  /**
   * Returns the configured IIIF token TTL in seconds, when available.
   */
  public function getIiifTokenTtl(): ?int {
    if (!$this->tokenManager) {
      return NULL;
    }
    try {
      return $this->tokenManager->getTtl();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Unable to read IIIF token TTL: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Appends a signed token to a IIIF URL when available.
   */
  public function appendIiifTokenToUrl(string $url, string $subsysname, string $image_identifier): string {
    if (!$this->tokenManager || trim($url) === '' || trim($subsysname) === '' || trim($image_identifier) === '') {
      return $url;
    }
    $token = $this->getIiifToken($subsysname, $image_identifier);
    if (!$token) {
      return $url;
    }
    try {
      return $this->tokenManager->appendTokenToUrl($url, $token);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to append IIIF token for identifier @id: @message', [
        '@id' => $image_identifier,
        '@message' => $e->getMessage(),
      ]);
      return $url;
    }
  }

  /**
   * Issues (and caches) a token for the provided subsystem/image pair.
   */
  protected function getIiifToken(string $subsysname, string $image_identifier): ?string {
    if (!$this->tokenManager) {
      return NULL;
    }
    $subsysname = trim($subsysname);
    $image_identifier = trim($image_identifier);
    if ($subsysname === '' || $image_identifier === '') {
      return NULL;
    }

    $cache_key = $this->buildIiifTokenCacheKey($subsysname, $image_identifier);
    if (!array_key_exists($cache_key, $this->iiifTokenCache)) {
      try {
        $this->iiifTokenCache[$cache_key] = $this->tokenManager->issueToken($subsysname, $image_identifier, $this->currentUser);
      }
      catch (\Throwable $e) {
        $this->logger->error('Unable to issue IIIF token for identifier @id: @message', [
          '@id' => $image_identifier,
          '@message' => $e->getMessage(),
        ]);
        $this->iiifTokenCache[$cache_key] = NULL;
      }
    }
    return $this->iiifTokenCache[$cache_key] ?? NULL;
  }

  /**
   * Builds a unique cache key for tokens per user/subsystem/image combo.
   */
  protected function buildIiifTokenCacheKey(string $subsysname, string $image_identifier): string {
    $uid = (int) $this->currentUser->id();
    return strtolower($subsysname) . '::' . $image_identifier . '::' . $uid;
  }

  /**
   * Retrieves and structures all relevant data for a specific page for export.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source_identifier
   *   The identifier of the source document.
   * @param int $page
   *   The page number.
   *
   * @return array
   *   A structured data array suitable for use in Twig templates.
   */
  public function getDataForExport(string $subsysname, string $source_identifier, int $page): array {
    $data = [
      'source' => NULL,
      'page' => NULL,
      'word_units' => [],
    ];

    // 1. Load WdbSource and WdbAnnotationPage.
    $source_storage = $this->entityTypeManager->getStorage('wdb_source');
    $sources = $source_storage->loadByProperties(['source_identifier' => $source_identifier]);
    $wdb_source_entity = reset($sources);
    if (!$wdb_source_entity) {
      return $data;
    }
    $data['source'] = $wdb_source_entity;

    $page_storage = $this->entityTypeManager->getStorage('wdb_annotation_page');
    $pages = $page_storage->loadByProperties(['source_ref' => $wdb_source_entity->id(), 'page_number' => $page]);
    $wdb_annotation_page_entity = reset($pages);
    if (!$wdb_annotation_page_entity) {
      return $data;
    }
    $data['page'] = $wdb_annotation_page_entity;

    // 2. Get all WdbWordUnit entities on this page, sorted by word_sequence.
    $wu_storage = $this->entityTypeManager->getStorage('wdb_word_unit');
    $wu_ids = $wu_storage->getQuery()
      ->condition('annotation_page_refs', $wdb_annotation_page_entity->id())
      ->sort('word_sequence', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($wu_ids)) {
      return $data;
    }
    $word_units = $wu_storage->loadMultiple($wu_ids);

    // 3. For each WdbWordUnit, get its constituent
    //    WdbSignInterpretations, sorted by sign_sequence.
    $word_map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    foreach ($word_units as $wu_id => $wu_entity) {
      $map_ids = $word_map_storage->getQuery()
        ->condition('word_unit_ref', $wu_id)
        ->sort('sign_sequence', 'ASC')
        ->accessCheck(FALSE)
        ->execute();

      $sign_interpretations = [];
      if (!empty($map_ids)) {
        $maps = $word_map_storage->loadMultiple($map_ids);
        /** @var \Drupal\wdb_core\Entity\WdbWordMap[] $maps */
        foreach ($maps as $map) {
          /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
          $sign_interpretations[] = $map->get('sign_interpretation_ref')->entity;
        }
      }

      $data['word_units'][] = [
        'entity' => $wu_entity,
        'sign_interpretations' => $sign_interpretations,
      ];
    }

    return $data;
  }

  /**
   * Retrieves detailed, structured data for display in the annotation panel.
   *
   * @param \Drupal\Core\Entity\EntityInterface $label_entity
   *   The label entity that was clicked. Preferably an instance of WdbLabel.
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return array
   *   A structured data array for the annotation panel.
   */
  public function getAnnotationDetails(EntityInterface $label_entity, string $subsysname): array {
    // Accept broader type for resilience; ensure it's a WdbLabel at runtime.
    if (!$label_entity instanceof WdbLabel) {
      $this->logger->error('Invalid entity passed to getAnnotationDetails: expected WdbLabel, got @class', ['@class' => get_class($label_entity)]);
      return ['error_message' => $this->t('Invalid label entity.')];
    }
    $wdb_label_entity = $label_entity;
    // --- FIX: Robusly determine the subsystem name. ---
    // If the passed subsysname is empty, try to derive it from the entity
    // to protect against issues in the calling controller.
    if (empty($subsysname)) {
      $page_entity = $wdb_label_entity->get('annotation_page_ref')->entity;
      if ($page_entity) {
        $source_entity = $page_entity->get('source_ref')->entity;
        if ($source_entity) {
          $subsystem_term = $source_entity->get('subsystem_tags')->entity;
          if ($subsystem_term) {
            $subsysname = $subsystem_term->getName();
          }
        }
      }
    }
    // If we still don't have a subsystem name, we cannot proceed.
    if (empty($subsysname)) {
      $this->logger->error('Could not determine subsystem name when getting annotation details for label ID @id.', ['@id' => $wdb_label_entity->id()]);
      return ['error_message' => $this->t('Could not determine the subsystem for the requested annotation.')];
    }
    // --- END OF FIX ---
    // 1. Initialize the data structure to be returned.
    $subsystem_config = $this->getSubsystemConfig($subsysname);
    if (!$subsystem_config) {
      return ['error_message' => $this->t('Subsystem configuration not found for "@subsys".', ['@subsys' => $subsysname])];
    }

    $page_navigation = $subsystem_config->get('pageNavigation') ?? 'left-to-right';

    $wdb_annotation_page_entity = $wdb_label_entity->get('annotation_page_ref')->entity;
    $source_entity = $wdb_annotation_page_entity->get('source_ref')->entity;

    $output_data = [
      'title' => $this->t('Label: @label', ['@label' => $wdb_label_entity->label()]),
      'retrieved_data' => [
        'source_title' => $source_entity->label(),
        'current_label_info' => ['label_name' => $wdb_label_entity->label()],
        'sign_interpretations' => [],
        'navigation' => ['word' => NULL, 'sign' => NULL],
        'subsystem' => ['pageNavigation' => $page_navigation],
        'current_word_unit_id' => NULL,
      ],
      'error_message' => NULL,
      'iiif_base_url' => '',
    ];

    // 2. Construct the IIIF base URL.
    $iiif_base_url = $this->getIiifBaseUrlForSubsystem($subsysname);
    if ($iiif_base_url) {
      $output_data['iiif_base_url'] = $iiif_base_url;
    }

    try {
      // 3. Get all SignInterpretations corresponding to the clicked label.
      $si_storage = $this->entityTypeManager->getStorage('wdb_sign_interpretation');
      $si_ids = $si_storage->getQuery()->condition('label_ref', $wdb_label_entity->id())->accessCheck(FALSE)->execute();
      if (empty($si_ids)) {
        $output_data['error_message'] = $this->t('No sign interpretations found for this label.');
        return $output_data;
      }
      $wdb_sign_interpretations = $si_storage->loadMultiple($si_ids);
      /** @var \Drupal\wdb_core\Entity\WdbSignInterpretation[] $wdb_sign_interpretations */

      // 4. Calculate navigation data (previous/next word and sign).
      $current_si = reset($wdb_sign_interpretations);
      $current_word_unit = $this->getWordUnitForSignInterpretation($current_si);
      if ($current_word_unit) {
        $output_data['retrieved_data']['current_word_unit_id'] = $current_word_unit->get('original_word_unit_identifier')->value;
        $all_word_units_on_page = $this->getSortedWordUnitsOnPage($wdb_annotation_page_entity);
        $output_data['retrieved_data']['navigation']['word'] = $this->findWordNeighbours($current_word_unit->id(), $all_word_units_on_page);
        $all_signs_in_word = $this->getSortedSignsInWordUnit($current_word_unit);
        $output_data['retrieved_data']['navigation']['sign'] = $this->findSignNeighbours($current_si->id(), $all_signs_in_word);
      }

      // 5. Build detailed display data for each SignInterpretation.
      foreach ($wdb_sign_interpretations as $wdb_si_entity) {
        /** @var \Drupal\wdb_core\Entity\WdbSignInterpretation $wdb_si_entity */
        $output_data['retrieved_data']['sign_interpretations'][] = $this->buildSignInterpretationData($wdb_si_entity, $subsysname);
      }

    }
    catch (\Exception $e) {
      $this->logger->error('WdbDataService::getAnnotationDetails failed: @message', ['@message' => $e->getMessage()]);
      $output_data['error_message'] = $this->t('An error occurred while retrieving detailed annotation information.');
    }

    return $output_data;
  }

  /**
   * Builds data for a single SignInterpretation.
   *
   * @param \Drupal\wdb_core\Entity\WdbSignInterpretation $si_entity
   *   The Sign Interpretation entity.
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return array
   *   A structured data array.
   */
  private function buildSignInterpretationData(WdbSignInterpretation $si_entity, string $subsysname): array {
    $si_data_item = [
      'entity' => $si_entity,
      'sign_info' => NULL,
      'thumbnail_data' => NULL,
      'associated_word_units' => [],
    ];
    $label = $si_entity->get('label_ref')->entity;

    $sf = $si_entity->get('sign_function_ref')->entity;
    if ($sf) {
      $sign = $sf->get('sign_ref')->entity;
      $sign_code = $sign ? $sign->label() : 'N/A';
      $si_data_item['sign_info'] = [
        'sign_code' => $sign_code,
        'function_name' => $sf->get('function_name')->value ?? '',
        'annotation_uri' => $label ? $label->get('annotation_uri')->value : NULL,
        'search_url' => Url::fromRoute(
          'wdb_core.search_form',
          ['subsysname' => strtolower($subsysname)],
          [
            'query' => ['sign' => $sign_code],
            'absolute' => TRUE,
          ]
        )->toString(),
      ];
    }
    $label = $si_entity->get('label_ref')->entity;
    $iiif_base_url = $this->getIiifBaseUrlForSubsystem($subsysname);
    if ($label && !$label->get('polygon_points')->isEmpty() && !empty($iiif_base_url)) {
      $points = array_map(fn($item) => $item['value'], $label->get('polygon_points')->getValue());
      $bbox = $this->calculateBoundingBoxArray($points);
      $page_entity = $label->get('annotation_page_ref')->entity;
      $subsys_config = $this->getSubsystemConfig($subsysname);
      if (!$subsys_config) {
        return $si_data_item;
      }
      $image_identifier = $page_entity->getImageIdentifier();
      if (empty($image_identifier)) {
        return $si_data_item;
      }

      $target_w = 125;
      $target_h = 125;
      $upscale_prefix = ($target_w > $bbox['w'] || $target_h > $bbox['h']) ? '^' : '';
      $size_param = $upscale_prefix . '!' . $target_w . ',' . $target_h;
      $thumbnail_url = $iiif_base_url . '/' . rawurlencode($image_identifier) . '/' . "{$bbox['x']},{$bbox['y']},{$bbox['w']},{$bbox['h']}" . '/' . $size_param . '/0/default.jpg';
      $thumbnail_url = $this->appendIiifTokenToUrl($thumbnail_url, $subsysname, $image_identifier);

      $si_data_item['thumbnail_data'] = [
        'image_identifier' => $image_identifier,
        'region_xywh' => "{$bbox['x']},{$bbox['y']},{$bbox['w']},{$bbox['h']}",
        'region_w' => $bbox['w'],
        'region_h' => $bbox['h'],
        'polygon_points' => implode(' ', array_map(fn($p_str) => round(explode(',', $p_str)[0] - $bbox['x']) . ',' . round(explode(',', $p_str)[1] - $bbox['y']), $points)),
        'thumbnail_url' => $thumbnail_url,
      ];
    }

    $word_map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $map_ids = $word_map_storage->getQuery()
      ->condition('sign_interpretation_ref', $si_entity->id())
      ->accessCheck(FALSE)
      ->execute();
    if (!empty($map_ids)) {
      $maps = $word_map_storage->loadMultiple($map_ids);
      /** @var \Drupal\wdb_core\Entity\WdbWordMap[] $maps */
      foreach ($maps as $map) {
        /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
        $wu_entity = $map->get('word_unit_ref')->entity;
        if ($wu_entity instanceof WdbWordUnit) {
          $si_data_item['associated_word_units'][] = $this->buildWordUnitData($wu_entity, $subsysname);
        }
      }
    }

    return $si_data_item;
  }

  /**
   * Builds a structured array of all data related to a single WordUnit.
   *
   * @param \Drupal\wdb_core\Entity\WdbWordUnit $wu_entity
   *   The Word Unit entity.
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return array
   *   A structured data array.
   */
  private function buildWordUnitData(WdbWordUnit $wu_entity, string $subsysname): array {
    $wu_data_item = [
      'entity' => $wu_entity,
      'word_info' => NULL,
      'meaning_info' => NULL,
      'grammatical_categories' => [],
      'constituent_signs' => [],
      'thumbnail_data' => NULL,
      // --- FIX: Add a placeholder for the new URL ---
      'realized_form_url' => '',
    ];

    // --- FIX: Generate the realized_form_url here in PHP ---
    $realized_form_value = $wu_entity->get('realized_form')->value;
    if (!empty($realized_form_value)) {
      $wu_data_item['realized_form_url'] = Url::fromRoute(
        'wdb_core.search_form',
        ['subsysname' => strtolower($subsysname)],
        ['query' => ['realized_form' => $realized_form_value], 'absolute' => TRUE]
      )->toString();
    }
    // --- END OF FIX ---
    $wdb_wmn_entity = $wu_entity->get('word_meaning_ref')->entity;
    if ($wdb_wmn_entity) {
      $wu_data_item['meaning_info'] = [
        'explanation' => $wdb_wmn_entity->get('explanation')->value ?? '',
        'meaning_identifier' => $wdb_wmn_entity->get('meaning_identifier')->value,
      ];
      $wdb_w_entity = $wdb_wmn_entity->get('word_ref')->entity;
      if ($wdb_w_entity) {
        $basic_form_value = $wdb_w_entity->get('basic_form')->value;
        $wu_data_item['word_info'] = [
          'basic_form' => $basic_form_value,
          'word_code' => $wdb_w_entity->get('word_code')->value,
          'search_url' => Url::fromRoute(
            'wdb_core.search_form',
            ['subsysname' => strtolower($subsysname)],
            ['query' => ['basic_form' => $basic_form_value], 'absolute' => TRUE]
          )->toString(),
        ];
        $lc_term = $wdb_w_entity->get('lexical_category_ref')->entity;
        if ($lc_term) {
          $wu_data_item['grammatical_categories']['lexical_category_name'] = $lc_term->getName();
          $wu_data_item['grammatical_categories']['lexical_category_search_url'] = Url::fromRoute(
            'wdb_core.search_form',
            ['subsysname' => strtolower($subsysname)],
            ['query' => ['lexical_category' => $lc_term->id()], 'absolute' => TRUE]
          )->toString();
        }
      }
    }

    $category_fields = [
      'verbal_form',
      'gender',
      'grammatical_number',
      'person',
      'voice',
      'aspect',
      'mood',
      'grammatical_case',
    ];
    foreach ($category_fields as $field_name) {
      if ($wu_entity->hasField($field_name . '_ref') && !$wu_entity->get($field_name . '_ref')->isEmpty()) {
        $wu_data_item['grammatical_categories'][str_replace('_ref', '', $field_name)] = $wu_entity->get($field_name . '_ref')->entity->label();
      }
    }

    // 2. Get all constituent signs and their polygons for this word unit.
    $word_map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $map_ids = $word_map_storage->getQuery()
      ->condition('word_unit_ref', $wu_entity->id())
      ->sort('sign_sequence', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    $all_polygon_points_for_word = [];
    if (!empty($map_ids)) {
      $maps = $word_map_storage->loadMultiple($map_ids);
      foreach ($maps as $map) {
        /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
        /** @var \Drupal\wdb_core\Entity\WdbSignInterpretation $si */
        $si = $map->get('sign_interpretation_ref')->entity;
        if ($si instanceof WdbSignInterpretation) {
          $sf = $si->get('sign_function_ref')->entity;
          $sign = $sf ? $sf->get('sign_ref')->entity : NULL;
          /** @var \Drupal\wdb_core\Entity\WdbLabel $label */
          $label = $si->get('label_ref')->entity;

          $sign_code_value = $sign ? $sign->label() : 'N/A';
          $polygon_points_for_sign = [];
          $sign_thumbnail_data = NULL;

          if ($label && !$label->get('polygon_points')->isEmpty()) {
            $polygon_points_for_sign = array_map(fn($item) => $item['value'], $label->get('polygon_points')->getValue());
            $all_polygon_points_for_word = array_merge($all_polygon_points_for_word, $polygon_points_for_sign);

            // Generate thumbnail data for each individual sign.
            $sign_bbox = $this->calculateBoundingBoxArray($polygon_points_for_sign);
            $page_entity = $label->get('annotation_page_ref')->entity;
            $subsys_config = $this->getSubsystemConfig($subsysname);
            if (!$subsys_config) {
              return $wu_data_item;
            }

            $image_identifier = $page_entity->getImageIdentifier();
            if (empty($image_identifier)) {
              return $wu_data_item;
            }
            $sign_thumbnail_data = [
              'image_identifier' => $image_identifier,
              'region_xywh' => "{$sign_bbox['x']},{$sign_bbox['y']},{$sign_bbox['w']},{$sign_bbox['h']}",
              'region_w' => $sign_bbox['w'],
              'region_h' => $sign_bbox['h'],
              'polygon_points' => implode(
                ' ',
                array_map(
                  fn($p_str) =>
                  round(explode(',', $p_str)[0] - $sign_bbox['x']) . ',' .
                  round(explode(',', $p_str)[1] - $sign_bbox['y']),
                  $polygon_points_for_sign
                )
              ),
            ];
          }

          // Aggregate data for the 'constituent_signs' array.
          $wu_data_item['constituent_signs'][] = [
            'sign_code' => $sign_code_value,
            'reading' => $si->get('phone')->value ?? '',
            'sequence' => $map->get('sign_sequence')->value ?? NULL,
            'annotation_uri' => $label ? ($label->get('annotation_uri')->value ?? NULL) : NULL,
            'polygon_points' => $polygon_points_for_sign,
            'search_url' => Url::fromRoute(
              'wdb_core.search_form',
              ['subsysname' => strtolower($subsysname)],
              [
                'query' => ['sign' => $sign_code_value],
                'absolute' => TRUE,
              ]
            )->toString(),
            'thumbnail_data' => $sign_thumbnail_data,
          ];
        }
      }
    }

    // 3. Calculate the bounding box for the entire word and
    //    generate thumbnail data.
    $iiif_base_url = $this->getIiifBaseUrlForSubsystem($subsysname);
    if (!empty($all_polygon_points_for_word) && !empty($iiif_base_url)) {
      $word_bbox = $this->calculateBoundingBoxArray($all_polygon_points_for_word);
      $page_refs = $wu_entity->get('annotation_page_refs');
      /** @var \Drupal\wdb_core\Entity\WdbAnnotationPage[] $page_entities */
      $page_entities = method_exists($page_refs, 'referencedEntities') ? $page_refs->referencedEntities() : [];
      $page_entity = $page_entities[0] ?? NULL;
      if ($page_entity) {
        $subsys_config = $this->getSubsystemConfig($subsysname);
        if (!$subsys_config) {
          return $wu_data_item;
        }
        $image_identifier = $page_entity->getImageIdentifier();
        if (empty($image_identifier)) {
          return $wu_data_item;
        }

        $points_for_hull = array_map(fn($p) => array_map('floatval', explode(',', $p)), $all_polygon_points_for_word);
        $concavity = $subsys_config->get('hullConcavity') ?? 20;
        $hull_points = $this->hullCalculator->calculate($points_for_hull, $concavity);
        $relative_hull_points = array_map(fn($p) => round($p[0] - $word_bbox['x']) . ',' . round($p[1] - $word_bbox['y']), $hull_points);

        $target_w = 250;
        $target_h = 125;
        $upscale_prefix = ($target_w > $word_bbox['w'] || $target_h > $word_bbox['h']) ? '^' : '';
        $size_param = $upscale_prefix . '!' . $target_w . ',' . $target_h;
        $thumbnail_url = $iiif_base_url . '/' . rawurlencode($image_identifier) . '/' . "{$word_bbox['x']},{$word_bbox['y']},{$word_bbox['w']},{$word_bbox['h']}" . '/' . $size_param . '/0/default.jpg';
        $thumbnail_url = $this->appendIiifTokenToUrl($thumbnail_url, $subsysname, $image_identifier);

        $wu_data_item['thumbnail_data'] = [
          'image_identifier' => $image_identifier,
          'region_xywh'      => "{$word_bbox['x']},{$word_bbox['y']},{$word_bbox['w']},{$word_bbox['h']}",
          'region_w'         => $word_bbox['w'],
          'region_h'         => $word_bbox['h'],
          'word_hull_points' => implode(' ', $relative_hull_points),
          'thumbnail_url' => $thumbnail_url,
        ];
      }
    }
    return $wu_data_item;
  }

  /**
   * Helper function to calculate the bounding box as an array.
   *
   * @param array $polygon_points_xy_strings
   *   An array of "X,Y" coordinate strings.
   *
   * @return array
   *   An associative array with x, y, w, h keys.
   */
  private function calculateBoundingBoxArray(array $polygon_points_xy_strings): array {
    if (empty($polygon_points_xy_strings)) {
      return ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1];
    }
    $all_x = [];
    $all_y = [];
    foreach ($polygon_points_xy_strings as $point_str) {
      $coords = explode(',', $point_str);
      if (count($coords) === 2) {
        $all_x[] = (float) $coords[0];
        $all_y[] = (float) $coords[1];
      }
    }
    if (empty($all_x)) {
      return ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1];
    }

    $min_x = min($all_x);
    $max_x = max($all_x);
    $min_y = min($all_y);
    $max_y = max($all_y);

    return [
      'x' => round($min_x),
      'y' => round($min_y),
      'w' => max(1, round($max_x - $min_x)),
      'h' => max(1, round($max_y - $min_y)),
    ];
  }

  /**
   * Gets all word units on a page, sorted by sequence.
   *
   * @param \Drupal\wdb_core\Entity\WdbAnnotationPage $page_entity
   *   The page entity.
   *
   * @return \Drupal\wdb_core\Entity\WdbWordUnit[]
   *   An array of word unit entities.
   */
  private function getSortedWordUnitsOnPage(WdbAnnotationPage $page_entity): array {
    $storage = $this->entityTypeManager->getStorage('wdb_word_unit');
    $query = $storage->getQuery()
      ->condition('annotation_page_refs', $page_entity->id())
      ->sort('word_sequence', 'ASC')
      ->accessCheck(FALSE);
    $ids = $query->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Gets all sign interpretations in a word unit, sorted by sequence.
   *
   * @param \Drupal\wdb_core\Entity\WdbWordUnit $wu_entity
   *   The word unit entity.
   *
   * @return \Drupal\wdb_core\Entity\WdbSignInterpretation[]
   *   An array of sign interpretation entities.
   */
  private function getSortedSignsInWordUnit(WdbWordUnit $wu_entity): array {
    $map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $query = $map_storage->getQuery()
      ->condition('word_unit_ref', $wu_entity->id())
      ->sort('sign_sequence', 'ASC')
      ->accessCheck(FALSE);
    $map_ids = $query->execute();
    if (empty($map_ids)) {
      return [];
    }
    $maps = $map_storage->loadMultiple($map_ids);
    /** @var \Drupal\wdb_core\Entity\WdbWordMap[] $maps */
    $sign_interpretations = [];
    foreach ($maps as $map) {
      /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
      $si = $map->get('sign_interpretation_ref')->entity;
      if ($si) {
        $sign_interpretations[$si->id()] = $si;
      }
    }
    return $sign_interpretations;
  }

  /**
   * Finds the previous and next word units in a sorted list.
   *
   * @param int $current_id
   *   The ID of the current word unit.
   * @param \Drupal\wdb_core\Entity\WdbWordUnit[] $sorted_word_units
   *   A sorted array of word units on the page.
   *
   * @return array
   *   An array with 'prev' and 'next' keys.
   */
  private function findWordNeighbours(int $current_id, array $sorted_word_units): array {
    $ids = array_keys($sorted_word_units);
    $current_index = array_search($current_id, $ids);
    $neighbours = ['prev' => NULL, 'next' => NULL];

    if ($current_index !== FALSE) {
      if ($current_index > 0) {
        $neighbours['prev'] = $this->getTargetDataForWordUnit($sorted_word_units[$ids[$current_index - 1]]);
      }
      if ($current_index < count($ids) - 1) {
        $neighbours['next'] = $this->getTargetDataForWordUnit($sorted_word_units[$ids[$current_index + 1]]);
      }
    }
    return $neighbours;
  }

  /**
   * Finds the previous and next sign interpretations in a sorted list.
   *
   * @param int $current_id
   *   The ID of the current sign interpretation.
   * @param \Drupal\wdb_core\Entity\WdbSignInterpretation[] $sorted_signs
   *   A sorted array of sign interpretations in the word.
   *
   * @return array
   *   An array with 'prev' and 'next' keys.
   */
  private function findSignNeighbours(int $current_id, array $sorted_signs): array {
    $ids = array_keys($sorted_signs);
    $current_index = array_search($current_id, $ids);
    $neighbours = ['prev' => NULL, 'next' => NULL];

    if ($current_index !== FALSE) {
      if ($current_index > 0) {
        $label = $sorted_signs[$ids[$current_index - 1]]->get('label_ref')->entity;
        $neighbours['prev'] = $label ? ['annotation_uri' => $label->get('annotation_uri')->value] : NULL;
      }
      if ($current_index < count($ids) - 1) {
        $label = $sorted_signs[$ids[$current_index + 1]]->get('label_ref')->entity;
        $neighbours['next'] = $label ? ['annotation_uri' => $label->get('annotation_uri')->value] : NULL;
      }
    }
    return $neighbours;
  }

  /**
   * Gets target data for word navigation (URI and points of the first sign).
   *
   * @param \Drupal\wdb_core\Entity\WdbWordUnit $wu_entity
   *   The word unit entity.
   *
   * @return array|null
   *   An array with annotation URI and points, or NULL.
   */
  private function getTargetDataForWordUnit(WdbWordUnit $wu_entity): ?array {
    $sorted_signs = $this->getSortedSignsInWordUnit($wu_entity);
    if (empty($sorted_signs)) {
      return NULL;
    }
    $first_sign = reset($sorted_signs);
    $label = $first_sign->get('label_ref')->entity;
    if (!$label) {
      return NULL;
    }

    $all_points = [];
    foreach ($sorted_signs as $si) {
      $sign_label = $si->get('label_ref')->entity;
      if ($sign_label && !$sign_label->get('polygon_points')->isEmpty()) {
        $points = array_map(fn($item) => $item['value'], $sign_label->get('polygon_points')->getValue());
        $all_points[] = $points;
      }
    }

    return [
      'annotation_uri' => $label->get('annotation_uri')->value,
      'points'         => $all_points,
    ];
  }

  /**
   * Gets the parent Word Unit for a given Sign Interpretation.
   *
   * @param \Drupal\wdb_core\Entity\WdbSignInterpretation $si_entity
   *   The sign interpretation entity.
   *
   * @return \Drupal\wdb_core\Entity\WdbWordUnit|null
   *   The parent word unit entity, or NULL.
   */
  private function getWordUnitForSignInterpretation(WdbSignInterpretation $si_entity): ?WdbWordUnit {
    $map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $maps = $map_storage->loadByProperties(['sign_interpretation_ref' => $si_entity->id()]);
    /** @var \Drupal\wdb_core\Entity\WdbWordMap|false $map */
    $map = reset($maps);
    return ($map && $map instanceof WdbWordMap) ? $map->get('word_unit_ref')->entity : NULL;
  }

  /**
   * Retrieves the configuration object for a given subsystem.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return \Drupal\Core\Config\ImmutableConfig|null
   *   The corresponding configuration object, or NULL if not found.
   */
  public function getSubsystemConfig(string $subsysname): ?ImmutableConfig {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Find the taxonomy term by its name.
    $terms = $term_storage->loadByProperties(['vid' => 'subsystem', 'name' => $subsysname]);

    if (empty($terms)) {
      $this->logger->warning('Subsystem config not found for machine name: @name', ['@name' => $subsysname]);
      return NULL;
    }

    $term = reset($terms);
    $config_name = 'wdb_core.subsystem.' . $term->id();

    return $this->configFactory->get($config_name);
  }

  /**
   * Constructs the IIIF base URL for a given subsystem.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return string|null
   *   The constructed base URL, or NULL if configuration is missing.
   */
  public function getIiifBaseUrlForSubsystem(string $subsysname): ?string {
    $subsystem_config = $this->getSubsystemConfig($subsysname);
    if (!$subsystem_config) {
      return NULL;
    }

    $scheme = $subsystem_config->get('iiif_server_scheme');
    $hostname = $subsystem_config->get('iiif_server_hostname');
    $prefix = $subsystem_config->get('iiif_server_prefix');

    if ($hostname) {
      // The prefix can be empty.
      $prefix = $prefix ? '/' . trim($prefix, '/') : '';
      return ($scheme ?? 'https') . '://' . $hostname . $prefix;
    }

    return NULL;
  }

}
