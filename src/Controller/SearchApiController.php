<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\Core\Url;
use Drupal\wdb_core\Entity\WdbWordUnit;
use Drupal\wdb_core\Lib\HullJsPhp\HullPHP;
use Drupal\wdb_core\Service\WdbDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the WDB Search API.
 *
 * Provides an API endpoint to search for word units based on various criteria.
 */
class SearchApiController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The HullPHP calculation service.
   *
   * @var \Drupal\wdb_core\Lib\HullJsPhp\HullPHP
   */
  protected HullPHP $hullCalculator;

  /**
   * The WDB data service.
   *
   * @var \Drupal\wdb_core\Service\WdbDataService
   */
  protected WdbDataService $wdbDataService;

  /**
   * Constructs a new SearchApiController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\wdb_core\Lib\HullJsPhp\HullPHP $hull_calculator
   *   The HullPHP calculation service.
   * @param \Drupal\wdb_core\Service\WdbDataService $wdbDataService
   *   The WDB data service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    HullPHP $hull_calculator,
    WdbDataService $wdbDataService,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->hullCalculator = $hull_calculator;
    $this->wdbDataService = $wdbDataService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('wdb_core.hull_calculator'),
      $container->get('wdb_core.data_service')
    );
  }

  /**
   * Searches for WDB entities based on query parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the search results.
   */
  public function search(Request $request): JsonResponse {
    $wu_storage = $this->entityTypeManager->getStorage('wdb_word_unit');
    $query = $wu_storage->getQuery();
    $valid_operators = ['CONTAINS', 'STARTS_WITH', 'ENDS_WITH'];

    // --- Build search conditions ---
    $condition_group = $query->andConditionGroup();
    $text_condition_group = ($request->query->get('op') === 'OR') ? $query->orConditionGroup() : $query->andConditionGroup();

    if ($value = $request->query->get('realized_form')) {
      $op = $request->query->get('realized_form_op', 'CONTAINS');
      $text_condition_group->condition('realized_form', $value, in_array($op, $valid_operators) ? $op : 'CONTAINS');
    }
    if ($value = $request->query->get('basic_form')) {
      $op = $request->query->get('basic_form_op', 'CONTAINS');
      $word_ids = $this->entityTypeManager->getStorage('wdb_word')->getQuery()->condition('basic_form', $value, $op)->accessCheck(FALSE)->execute();
      if (!empty($word_ids)) {
        $meaning_ids = $this->entityTypeManager->getStorage('wdb_word_meaning')->getQuery()->condition('word_ref', $word_ids, 'IN')->accessCheck(FALSE)->execute();
        if (!empty($meaning_ids)) {
          $text_condition_group->condition('word_meaning_ref', $meaning_ids, 'IN');
        }
        else {
          return new JsonResponse(['results' => [], 'total' => 0]);
        }
      }
      else {
        return new JsonResponse(['results' => [], 'total' => 0]);
      }
    }
    if ($value = $request->query->get('sign')) {
      $op = $request->query->get('sign_op', 'CONTAINS');
      $sign_ids = $this->entityTypeManager->getStorage('wdb_sign')->getQuery()->condition('sign_code', $value, $op)->accessCheck(FALSE)->execute();
      if (!empty($sign_ids)) {
        $sf_ids = $this->entityTypeManager->getStorage('wdb_sign_function')->getQuery()
          ->condition('sign_ref', $sign_ids, 'IN')->accessCheck(FALSE)->execute();
        if (!empty($sf_ids)) {
          $si_ids = $this->entityTypeManager->getStorage('wdb_sign_interpretation')->getQuery()
            ->condition('sign_function_ref', $sf_ids, 'IN')->accessCheck(FALSE)->execute();
          if (!empty($si_ids)) {
            $maps = $this->entityTypeManager->getStorage('wdb_word_map')->loadByProperties(['sign_interpretation_ref' => $si_ids]);
            $wu_ids_from_sign = array_unique(array_map(fn($map) => $map->get('word_unit_ref')->target_id, $maps));
            if (!empty($wu_ids_from_sign)) {
              $text_condition_group->condition('id', $wu_ids_from_sign, 'IN');
            }
            else {
              return new JsonResponse(['results' => [], 'total' => 0]);
            }
          }
          else {
            return new JsonResponse(['results' => [], 'total' => 0]);
          }
        }
        else {
          return new JsonResponse(['results' => [], 'total' => 0]);
        }
      }
      else {
        return new JsonResponse(['results' => [], 'total' => 0]);
      }
    }
    if ($text_condition_group->count() > 0) {
      $condition_group->condition($text_condition_group);
    }

    // Filter conditions.
    $lexical_category = $request->query->get('lexical_category');
    if ($lexical_category) {
      $term_ids_to_search = [$lexical_category];
      if ($request->query->get('include_children') === '1') {
        $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
        if ($term_storage instanceof TermStorageInterface) {
          /** @var array<int, object> $descendants */
          $descendants = $term_storage->loadTree('lexical_category', $lexical_category, NULL, FALSE);
          foreach ($descendants as $descendant) {
            if (isset($descendant->tid)) {
              $term_ids_to_search[] = $descendant->tid;
            }
          }
        }
      }
      $word_ids_by_cat = $this->entityTypeManager->getStorage('wdb_word')->getQuery()->condition('lexical_category_ref', $term_ids_to_search, 'IN')->accessCheck(FALSE)->execute();
      if (!empty($word_ids_by_cat)) {
        $meaning_ids_by_cat = $this->entityTypeManager->getStorage('wdb_word_meaning')->getQuery()->condition('word_ref', $word_ids_by_cat, 'IN')->accessCheck(FALSE)->execute();
        if (!empty($meaning_ids_by_cat)) {
          $condition_group->condition('word_meaning_ref', $meaning_ids_by_cat, 'IN');
        }
        else {
          return new JsonResponse(['results' => [], 'total' => 0]);
        }
      }
      else {
        return new JsonResponse(['results' => [], 'total' => 0]);
      }
    }
    $subsystem = $request->query->get('subsystem');
    if ($subsystem) {
      $source_ids = $this->entityTypeManager->getStorage('wdb_source')->getQuery()->condition('subsystem_tags', $subsystem)->accessCheck(FALSE)->execute();
      if (!empty($source_ids)) {
        $condition_group->condition('source_ref', $source_ids, 'IN');
      }
      else {
        return new JsonResponse(['results' => [], 'total' => 0]);
      }
    }

    if ($condition_group->count() > 0) {
      $query->condition($condition_group);
    }

    // Clone the query to get the total count before applying pagination.
    $count_query = clone $query;
    $total = $count_query->count()->accessCheck(FALSE)->execute();

    // Apply paging and sorting.
    $page = max(0, (int) $request->query->get('page', 0));
    $limit = (int) $request->query->get('limit', 50);

    // Set sort order to Source > Word Sequence.
    $query->sort('source_ref', 'ASC');
    $query->sort('word_sequence', 'ASC');

    if ($limit > 0) {
      $query->range($page * $limit, $limit);
    }
    $query->accessCheck(TRUE);

    $wu_ids = $query->execute();
    $results_array = [];
    if (!empty($wu_ids)) {
      $word_units = $this->entityTypeManager->getStorage('wdb_word_unit')->loadMultiple($wu_ids);
      foreach ($word_units as $wu) {
        /** @var \Drupal\wdb_core\Entity\WdbWordUnit $wu */
        /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $source */
        $source = $wu->get('source_ref')->entity;
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface|null $annotation_pages */
        $annotation_pages = $wu->get('annotation_page_refs');
        /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $page_entity */
        $page_entity = ($annotation_pages instanceof EntityReferenceFieldItemListInterface) ? ($annotation_pages->referencedEntities()[0] ?? NULL) : NULL;
        $first_sign_label_uri = $this->getFirstAnnotationUriForWordUnit($wu);

        /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $subsys_tag */
        $subsys_tag = $source ? $source->get('subsystem_tags')->entity : NULL;
        $subsysname = $subsys_tag ? $subsys_tag->get('name')->value : NULL;

        if ($source && $page_entity && $first_sign_label_uri && $subsysname) {

          /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $word_entity */
          $word_entity = $wu->get('word_meaning_ref')->entity->get('word_ref')->entity;
          /** @var \Drupal\taxonomy\Entity\Term|null $lexical_category_term */
          $lexical_category_term = $word_entity ? $word_entity->get('lexical_category_ref')->entity : NULL;

          $results_array[] = [
            'realized_form' => $wu->get('realized_form')->value,
            'basic_form' => $word_entity ? $word_entity->label() : '',
            'lexical_category' => $lexical_category_term ? $lexical_category_term->getName() : '',
            'source' => $source->label(),
            'page' => $page_entity->get('page_number')->value,
            'link' => Url::fromRoute('wdb_core.gallery_page', [
              'subsysname' => $subsysname,
              'source' => $source->get('source_identifier')->value,
              'page' => $page_entity->get('page_number')->value,
            ], ['query' => ['highlight_annotation' => $first_sign_label_uri]])->toString(TRUE)->getGeneratedUrl(),
            'thumbnail_data' => $this->getWordThumbnailData($wu, $subsysname),
            'constituent_signs' => $this->getConstituentSignsData($wu),
          ];
        }
      }
    }
    return new JsonResponse([
      'results' => $results_array,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
    ]);
  }

  /**
   * Gets data for the constituent signs of a word unit.
   *
   * @param \Drupal\wdb_core\Entity\WdbWordUnit $word_unit
   *   The word unit entity.
   *
   * @return array
   *   An array of constituent sign data.
   */
  private function getConstituentSignsData(WdbWordUnit $word_unit): array {
    $signs_data = [];
    $word_map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $map_ids = $word_map_storage->getQuery()->condition('word_unit_ref', $word_unit->id())->sort('sign_sequence', 'ASC')->accessCheck(FALSE)->execute();

    if (!empty($map_ids)) {
      $maps = $word_map_storage->loadMultiple($map_ids);
      foreach ($maps as $map) {
        /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
        /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $si */
        $si = $map->get('sign_interpretation_ref')->entity;
        if ($si) {
          /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $sf */
          $sf = $si->get('sign_function_ref')->entity;
          /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $sign */
          $sign = $sf ? $sf->get('sign_ref')->entity : NULL;
          /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $label */
          $label = $si->get('label_ref')->entity;
          $signs_data[] = [
            'sign_code' => $sign ? $sign->label() : 'N/A',
            'phone' => $si->get('phone')->value,
            'polygon_points' => $label ? array_map(fn($item) => $item['value'], $label->get('polygon_points')->getValue()) : [],
          ];
        }
      }
    }
    return $signs_data;
  }

  /**
   * Gets thumbnail data for an entire word unit.
   *
   * @param \Drupal\wdb_core\Entity\WdbWordUnit $word_unit
   *   The word unit entity.
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return array|null
   *   An array of thumbnail data, or NULL.
   */
  private function getWordThumbnailData(WdbWordUnit $word_unit, string $subsysname): ?array {
    $word_map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $map_ids = $word_map_storage->getQuery()->condition('word_unit_ref', $word_unit->id())->accessCheck(FALSE)->execute();

    $all_polygon_points = [];
    if (!empty($map_ids)) {
      $maps = $word_map_storage->loadMultiple($map_ids);
      foreach ($maps as $map) {
        /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
        /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $si */
        $si = $map->get('sign_interpretation_ref')->entity;
        if ($si) {
          /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $label */
          $label = $si->get('label_ref')->entity;
          if ($label && !$label->get('polygon_points')->isEmpty()) {
            $points = array_map(fn($item) => $item['value'], $label->get('polygon_points')->getValue());
            $all_polygon_points = array_merge($all_polygon_points, $points);
          }
        }
      }
    }
    if (empty($all_polygon_points)) {
      return NULL;
    }

    $iiif_base_url = $this->wdbDataService->getIiifBaseUrlForSubsystem($subsysname);
    if (!$iiif_base_url) {
      return NULL;
    }

    $word_bbox = $this->calculateBoundingBoxArray($all_polygon_points);
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface|null $annotation_pages */
    $annotation_pages = $word_unit->get('annotation_page_refs');
    /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $page_entity */
    $page_entity = ($annotation_pages instanceof EntityReferenceFieldItemListInterface) ? ($annotation_pages->referencedEntities()[0] ?? NULL) : NULL;
    if ($page_entity) {
      // Custom method defined on the page entity class.
      $image_identifier = method_exists($page_entity, 'getImageIdentifier') ? $page_entity->getImageIdentifier() : NULL;

      if (empty($image_identifier)) {
        return NULL;
      }

      $target_w = 250;
      $target_h = 250;
      $upscale_prefix = ($target_w > $word_bbox['w'] || $target_h > $word_bbox['h']) ? '^' : '';
      $size_param = $upscale_prefix . '!' . $target_w . ',' . $target_h;
      $thumbnail_url = sprintf(
        '%s/%s/%s/%s/0/default.jpg',
        $iiif_base_url,
        rawurlencode($image_identifier),
        "{$word_bbox['x']},{$word_bbox['y']},{$word_bbox['w']},{$word_bbox['h']}",
        $size_param
      );
      $thumbnail_url = $this->wdbDataService->appendIiifTokenToUrl($thumbnail_url, $subsysname, $image_identifier);

      return [
        'image_url' => $thumbnail_url,
        'region_w' => $word_bbox['w'],
        'region_h' => $word_bbox['h'],
        'region_x' => $word_bbox['x'],
        'region_y' => $word_bbox['y'],
      ];
    }
    return NULL;
  }

  /**
   * Gets the annotation URI of the first sign in a word unit.
   *
   * @param \Drupal\wdb_core\Entity\WdbWordUnit $word_unit
   *   The word unit entity.
   *
   * @return string|null
   *   The annotation URI, or NULL.
   */
  private function getFirstAnnotationUriForWordUnit(WdbWordUnit $word_unit): ?string {
    $map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $map_ids = $map_storage->getQuery()->condition('word_unit_ref', $word_unit->id())->sort('sign_sequence', 'ASC')->range(0, 1)->accessCheck(FALSE)->execute();
    if ($map_ids) {
      $map = $map_storage->load(reset($map_ids));
      /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
      /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $si */
      $si = $map->get('sign_interpretation_ref')->entity;
      /** @var \Drupal\Core\Entity\FieldableEntityInterface|null $label */
      $label = $si ? $si->get('label_ref')->entity : NULL;
      return ($label && !$label->get('annotation_uri')->isEmpty()) ? $label->get('annotation_uri')->value : NULL;
    }
    return NULL;
  }

  /**
   * Calculates the bounding box from an array of polygon point strings.
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

}
