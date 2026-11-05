<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Entity\WdbLabel;
use Drupal\wdb_core\Lib\HullJsPhp\HullPHP;
use Drupal\wdb_core\Service\WdbDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for WDB Annotation API endpoints.
 *
 * Provides RESTful endpoints for creating, reading, updating, and deleting
 * annotations, compatible with clients like Annotorious.
 */
class AnnotationApiController extends ControllerBase implements ContainerInjectionInterface {

  use WdbResourceAccessTrait;

  /**
   * The URL generator service.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected UrlGeneratorInterface $urlGenerator;

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a new AnnotationApiController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL generator.
   * @param \Drupal\wdb_core\Lib\HullJsPhp\HullPHP $hull_calculator
   *   The hull calculator.
   * @param \Drupal\wdb_core\Service\WdbDataService $wdbDataService
   *   The data service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, UrlGeneratorInterface $url_generator, HullPHP $hull_calculator, WdbDataService $wdbDataService, RequestStack $request_stack) {
    $this->entityTypeManager = $entity_type_manager;
    $this->urlGenerator = $url_generator;
    $this->hullCalculator = $hull_calculator;
    $this->wdbDataService = $wdbDataService;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('url_generator'),
      $container->get('wdb_core.hull_calculator'),
      $container->get('wdb_core.data_service'),
      $container->get('request_stack'),
    );
  }

  /**
   * Searches for annotations based on a Canvas URI.
   *
   * This is the primary endpoint for Annotorious to load annotations.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing an array of annotations.
   */
  public function searchAnnotations(Request $request): JsonResponse {
    $canvas_uri_param = $request->query->get('uri');
    if (empty($canvas_uri_param)) {
      return new JsonResponse(['error' => 'Missing "uri" query parameter.'], 400);
    }

    $wdb_annotation_page_entity = $this->loadAnnotationPageFromCanvasUri($canvas_uri_param);
    if (!$wdb_annotation_page_entity) {
      return new JsonResponse(['error' => 'Annotation page not found.'], 404);
    }
    $this->resourceAccess()->assertViewAccess($wdb_annotation_page_entity);

    $wdb_labels = $this->entityTypeManager->getStorage('wdb_label')->loadByProperties([
      'annotation_page_ref' => $wdb_annotation_page_entity->id(),
    ]);

    $annotations_array = [];
    if (!empty($wdb_labels)) {
      foreach ($wdb_labels as $wdb_label) {
        /** @var \Drupal\wdb_core\Entity\WdbLabel $wdb_label */
        $annotation = $this->buildAnnotationV3($wdb_label);
        if ($annotation) {
          $annotations_array[] = $annotation;
        }
      }
    }

    return new JsonResponse($annotations_array);
  }

  /**
   * Creates a new annotation (WdbLabel entity) from a JSON payload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the newly created annotation.
   */
  public function createAnnotation(Request $request): JsonResponse {
    if ($request->getContentTypeFormat() !== 'json') {
      return new JsonResponse(['error' => 'Content-Type must be application/json.'], 415);
    }
    $payload = json_decode($request->getContent(), TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid JSON payload.'], 400);
    }

    // Extract data from the Annotorious v3 native format.
    $body_object = $payload['bodies'][0] ?? NULL;
    $label_name = $body_object['value'] ?? NULL;
    $canvas_uri = $payload['target']['source'] ?? NULL;
    $points = $payload['target']['selector']['geometry']['points'] ?? NULL;

    if (!is_string($label_name) || trim($label_name) === '' || !is_string($canvas_uri) || !$this->validPoints($points)) {
      return new JsonResponse(['error' => 'Missing required fields in payload.'], 400);
    }

    $wdb_annotation_page = $this->loadAnnotationPageFromCanvasUri($canvas_uri);
    if (!$wdb_annotation_page) {
      return new JsonResponse(['error' => 'Annotation page not found.'], 404);
    }
    $this->resourceAccess()->assertOperationAccess($wdb_annotation_page, 'access wdb annotation api create');

    try {
      $polygon_points_array = array_map(fn($p) => "{$p[0]},{$p[1]}", $points);
      $points_for_storage = array_map(fn($p) => ['value' => $p], $polygon_points_array);
      $center_coords = $this->calculatePolygonCenter($polygon_points_array);

      /** @var \Drupal\wdb_core\Entity\WdbLabel $wdb_label */
      $wdb_label = $this->entityTypeManager->getStorage('wdb_label')->create([
        'label_name' => strip_tags($label_name),
        'annotation_page_ref' => $wdb_annotation_page->id(),
        'polygon_points' => $points_for_storage,
        'label_center_x' => $center_coords['x'] ?? NULL,
        'label_center_y' => $center_coords['y'] ?? NULL,
        'langcode' => $wdb_annotation_page->language()->getId(),
      ]);
      $wdb_label->save();

      // Set the canonical URI of the new entity as its annotation URI.
      $annotation_uri = Url::fromRoute('entity.wdb_label.canonical', ['wdb_label' => $wdb_label->id()], ['absolute' => TRUE])->toString();
      $wdb_label->set('annotation_uri', $annotation_uri)->save();
      return new JsonResponse($this->buildAnnotationV3($wdb_label), 201);
    }
    catch (\Exception $e) {
      $this->getLogger('wdb_core')->error('Failed to create WdbLabel: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Server error while creating annotation.'], 500);
    }
  }

  /**
   * Updates an existing annotation (WdbLabel entity).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the updated annotation.
   */
  public function updateAnnotation(Request $request): JsonResponse {
    if ($request->getContentTypeFormat() !== 'json') {
      return new JsonResponse(['error' => 'Content-Type must be application/json.'], 415);
    }
    $payload = json_decode($request->getContent(), TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid JSON payload.'], 400);
    }
    $annotation_uri = $payload['id'] ?? NULL;

    if (empty($annotation_uri) || !is_string($annotation_uri)) {
      return new JsonResponse(['error' => 'Missing or invalid "id" in payload.'], 400);
    }

    $labels = $this->entityTypeManager->getStorage('wdb_label')->loadByProperties(['annotation_uri' => $annotation_uri]);
    /** @var \Drupal\wdb_core\Entity\WdbLabel|false $wdb_label */
    $wdb_label = reset($labels);
    if (!$wdb_label) {
      return new JsonResponse(['error' => 'Annotation not found for update.'], 404);
    }
    $this->resourceAccess()->assertOperationAccess($wdb_label, 'access wdb annotation api update');

    $body_object = $payload['bodies'][0] ?? NULL;
    $label_name = $body_object['value'] ?? NULL;
    $points = $payload['target']['selector']['geometry']['points'] ?? NULL;

    if (!is_string($label_name) || trim($label_name) === '' || !$this->validPoints($points)) {
      return new JsonResponse(['error' => 'Missing body or selector in payload for update.'], 400);
    }

    try {
      $polygon_points_array = array_map(fn($p) => "{$p[0]},{$p[1]}", $points);
      $points_for_storage = array_map(fn($p) => ['value' => $p], $polygon_points_array);
      $center_coords = $this->calculatePolygonCenter($polygon_points_array);
      $wdb_label->set('label_name', strip_tags($label_name));
      $wdb_label->set('polygon_points', $points_for_storage);
      $wdb_label->set('label_center_x', $center_coords['x'] ?? NULL);
      $wdb_label->set('label_center_y', $center_coords['y'] ?? NULL);
      $wdb_label->save();

      return new JsonResponse($this->buildAnnotationV3($wdb_label), 200);
    }
    catch (\Exception $e) {
      $this->getLogger('wdb_core')->error('Failed to update WdbLabel @id: @message', [
        '@id' => $wdb_label ? $wdb_label->id() : 'N/A',
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Server error while updating annotation.'], 500);
    }
  }

  /**
   * Deletes an existing annotation (WdbLabel entity).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A 204 No Content response on success.
   */
  public function deleteAnnotation(Request $request): JsonResponse {
    $annotation_uri = $request->query->get('uri');
    if (empty($annotation_uri)) {
      return new JsonResponse(['error' => 'Missing "uri" query parameter.'], 400);
    }

    $labels = $this->entityTypeManager->getStorage('wdb_label')->loadByProperties(['annotation_uri' => $annotation_uri]);
    $wdb_label = reset($labels);

    if ($wdb_label) {
      $this->resourceAccess()->assertOperationAccess($wdb_label, 'access wdb annotation api delete');
      try {
        $wdb_label->delete();
      }
      catch (\Exception $e) {
        return new JsonResponse(['error' => 'Server error while deleting annotation.'], 500);
      }
    }
    return new JsonResponse(NULL, 204);
  }

  /**
   * Generates a word-level annotation list for a given page.
   *
   * @param \Drupal\wdb_core\Entity\WdbAnnotationPage $wdb_annotation_page
   *   The annotation page entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing a IIIF AnnotationPage.
   */
  public function getWordLevelAnnotations(WdbAnnotationPage $wdb_annotation_page): JsonResponse {
    $this->resourceAccess()->assertViewAccess($wdb_annotation_page);
    $annotations = [];
    $wu_storage = $this->entityTypeManager->getStorage('wdb_word_unit');
    $map_storage = $this->entityTypeManager->getStorage('wdb_word_map');

    // Pre-fetch the manifest URI for this page.
    $source_entity = $wdb_annotation_page->get('source_ref')->entity;
    $subsysname = $source_entity->get('subsystem_tags')->entity->getName();
    $manifest_uri = Url::fromRoute('wdb_core.iiif_manifest_v3', [
      'subsysname' => strtolower($subsysname),
      'source' => $source_entity->get('source_identifier')->value,
    ], ['absolute' => TRUE])->toString();

    // 1. Get all WordUnit entities on this page.
    $wu_ids = $wu_storage->getQuery()
      ->condition('annotation_page_refs', $wdb_annotation_page->id())
      ->sort('word_sequence', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($wu_ids)) {
      $word_units = $wu_storage->loadMultiple($wu_ids);
      foreach ($word_units as $wu) {
        /** @var \Drupal\wdb_core\Entity\WdbWordUnit $wu */

        // 2. For each WordUnit, get the polygons of all its constituent signs.
        $map_ids = $map_storage->getQuery()->condition('word_unit_ref', $wu->id())->accessCheck(FALSE)->execute();
        $all_points = [];
        foreach ($map_storage->loadMultiple($map_ids) as $map) {
          /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
          $si = $map->get('sign_interpretation_ref')->entity;
          if ($si && $si->get('label_ref')->entity) {
            $label = $si->get('label_ref')->entity;
            if (!$label->get('polygon_points')->isEmpty()) {
              $points = array_map(fn($item) => $item['value'], $label->get('polygon_points')->getValue());
              $all_points = array_merge($all_points, $points);
            }
          }
        }

        if (empty($all_points)) {
          continue;
        }

        // 3. Calculate the concave hull polygon for the entire word.
        $points_for_hull = array_map(fn($p) => array_map('floatval', explode(',', $p)), $all_points);
        $subsys_config = $this->wdbDataService->getSubsystemConfig(strtolower($subsysname));
        if (!$subsys_config) {
          return new JsonResponse(['error' => $this->t('Subsystem configuration not found for "@subsys".', ['@subsys' => $subsysname])], 500);
        }
        $concavity = $subsys_config->get('hullConcavity') ?? 20;

        $hull_points = $this->hullCalculator->calculate($points_for_hull, $concavity);
        $path_d_string = $this->formatPointsToSvgPathD($hull_points);

        $word_meaning = $wu->get('word_meaning_ref')->entity;
        $word = $word_meaning ? $word_meaning->get('word_ref')->entity : NULL;

        // Assemble the parts of the HTML body for the annotation.
        $html_body_parts = [];

        $realized_form_value = $wu->get('realized_form')->value;
        $realized_form_search_url = Url::fromRoute(
          'wdb_core.search_form',
          ['subsysname' => strtolower($subsysname)],
          [
            'query' => ['realized_form' => $realized_form_value],
            'absolute' => TRUE,
          ]
        )->toString();

        $html_body_parts[] = "<strong>Realized Form:</strong> <a href=\"{$realized_form_search_url}\" target=\"_blank\">{$realized_form_value}</a>";

        if ($word) {
          $basic_form_value = $word->get('basic_form')->value;
          $basic_form_search_url = Url::fromRoute(
            'wdb_core.search_form',
            ['subsysname' => strtolower($subsysname)],
            [
              'query' => ['basic_form' => $basic_form_value],
              'absolute' => TRUE,
            ]
          )->toString();
          $html_body_parts[] = "<strong>Basic Form:</strong> <a href=\"{$basic_form_search_url}\" target=\"_blank\">{$basic_form_value}</a>";
        }

        $lexical_category_term = $word ? $word->get('lexical_category_ref')->entity : NULL;
        if ($lexical_category_term) {
          $lc_value = $lexical_category_term->getName();
          $lc_search_url = Url::fromRoute(
            'wdb_core.search_form',
            ['subsysname' => strtolower($subsysname)],
            [
              'query' => ['lexical_category' => $lexical_category_term->id()],
              'absolute' => TRUE,
            ]
          )->toString();
          $html_body_parts[] = "<strong>Lexical Category:</strong> <a href=\"{$lc_search_url}\" target=\"_blank\">{$lc_value}</a>";
        }

        $map_ids_for_signs = $map_storage->getQuery()->condition('word_unit_ref', $wu->id())->sort('sign_sequence', 'ASC')->accessCheck(FALSE)->execute();
        if (!empty($map_ids_for_signs)) {
          $maps_for_signs = $map_storage->loadMultiple($map_ids_for_signs);
          $sign_links = [];
          foreach ($maps_for_signs as $map) {
            /** @var \Drupal\wdb_core\Entity\WdbWordMap $map */
            $si = $map->get('sign_interpretation_ref')->entity;
            if ($si) {
              $sf = $si->get('sign_function_ref')->entity;
              $sign = $sf ? $sf->get('sign_ref')->entity : NULL;
              if ($sign) {
                $sign_code = $sign->get('sign_code')->value;
                $phone = $si->get('phone')->value;
                $display_text = $phone ? "{$sign_code} [{$phone}]" : $sign_code;
                $sign_search_url = Url::fromRoute(
                  'wdb_core.search_form',
                  ['subsysname' => strtolower($subsysname)],
                  [
                    'query' => ['sign' => $sign_code],
                    'absolute' => TRUE,
                  ]
                )->toString();
                $sign_links[] = "<a href=\"{$sign_search_url}\" target=\"_blank\">{$display_text}</a>";
              }
            }
          }
          if (!empty($sign_links)) {
            $html_body_parts[] = "<strong>Constituent Signs:</strong> " . implode(' ', $sign_links);
          }
        }

        // Create a single TextualBody with the combined HTML.
        $body = [
          'type' => 'TextualBody',
          'value' => implode('<br>', $html_body_parts),
          'format' => 'text/html',
          'purpose' => 'commenting',
        ];

        // Create the rich annotation for the word.
        $annotations[] = [
          'id' => Url::fromRoute('entity.wdb_word_unit.canonical', ['wdb_word_unit' => $wu->id()], ['absolute' => TRUE])->toString(),
          'type' => 'Annotation',
          'motivation' => 'commenting',
          'body' => $body,
          'target' => [
            'type' => 'SpecificResource',
            'source' => [
              'id' => $wdb_annotation_page->getCanvasUri(),
              'type' => 'Canvas',
              'partOf' => [['id' => $manifest_uri, 'type' => 'Manifest']],
            ],
            'selector' => [
              'type' => 'SvgSelector',
              'value' => "<svg><path d=\"{$path_d_string}\"></path></svg>",
              'conformsTo' => 'http://www.w3.org/TR/SVG/',
            ],
          ],
        ];
      }
    }

    // 5. Return all annotations wrapped in an AnnotationPage.
    $response_data = [
      '@context' => 'http://iiif.io/api/presentation/3/context.json',
      'id' => Url::fromRoute('wdb_core.word_annotation_list_v3', ['wdb_annotation_page' => $wdb_annotation_page->id()], ['absolute' => TRUE])->toString(),
      'type' => 'AnnotationPage',
      'items' => $annotations,
    ];

    return new JsonResponse($response_data);
  }

  /**
   * Helper methods.
   */

  /**
   * Builds an Annotorious v3 native compliant JSON object.
   *
   * @param \Drupal\wdb_core\Entity\WdbLabel $wdb_label_entity
   *   The label entity.
   *
   * @return array|null
   *   The annotation array or NULL if no points.
   */
  private function buildAnnotationV3(WdbLabel $wdb_label_entity): ?array {
    $polygon_points_strings = array_map(fn($item) => $item['value'], $wdb_label_entity->get('polygon_points')->getValue());
    if (empty($polygon_points_strings)) {
      return NULL;
    }

    // 1. Convert points to [ [x1, y1], [x2, y2], ... ] format.
    $points = array_map(fn($p_str) => array_map('floatval', explode(',', $p_str)), $polygon_points_strings);

    // 2. Calculate the bounding box.
    $bounds = $this->calculateBounds($points);

    return [
      'id' => $wdb_label_entity->get('annotation_uri')->value,
      'type' => 'Annotation',
      'bodies' => [
        [
          'id' => $wdb_label_entity->uuid(),
          'purpose' => 'commenting',
          'value' => $wdb_label_entity->label(),
          'created' => gmdate('Y-m-d\TH:i:s\Z', $wdb_label_entity->get('created')->value),
          'modified' => gmdate('Y-m-d\TH:i:s\Z', $wdb_label_entity->get('changed')->value),
        ],
      ],
      'target' => [
        'selector' => [
          'type' => 'POLYGON',
          'geometry' => [
            'bounds' => $bounds,
            'points' => $points,
          ],
        ],
      ],
    ];
  }

  /**
   * Helper method to calculate bounding box from numeric points array.
   *
   * @param array $points
   *   An array of [x, y] coordinate pairs.
   *
   * @return array
   *   An associative array with minX, minY, maxX, maxY keys.
   */
  private function calculateBounds(array $points): array {
    if (empty($points)) {
      return ['minX' => 0, 'minY' => 0, 'maxX' => 0, 'maxY' => 0];
    }
    $all_x = array_column($points, 0);
    $all_y = array_column($points, 1);

    return [
      'minX' => min($all_x),
      'minY' => min($all_y),
      'maxX' => max($all_x),
      'maxY' => max($all_y),
    ];
  }

  /**
   * Calculates the center of a polygon's bounding box.
   *
   * @param array $polygon_points_xy_strings
   *   An array of "X,Y" coordinate strings.
   *
   * @return array
   *   An associative array with x and y keys.
   */
  private function calculatePolygonCenter(array $polygon_points_xy_strings): array {
    if (empty($polygon_points_xy_strings)) {
      return ['x' => 0, 'y' => 0];
    }
    $all_x = [];
    $all_y = [];
    foreach ($polygon_points_xy_strings as $point_str) {
      $coords = explode(',', $point_str);
      if (count($coords) === 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $all_x[] = (float) $coords[0];
        $all_y[] = (float) $coords[1];
      }
    }
    if (empty($all_x)) {
      return ['x' => 0, 'y' => 0];
    }

    $min_x = min($all_x);
    $max_x = max($all_x);
    $min_y = min($all_y);
    $max_y = max($all_y);

    return [
      'x' => round($min_x + (($max_x - $min_x) / 2)),
      'y' => round($min_y + (($max_y - $min_y) / 2)),
    ];
  }

  /**
   * Loads an annotation page entity from a given Canvas URI.
   *
   * @param string $canvas_uri
   *   The full Canvas URI.
   *
   * @return \Drupal\wdb_core\Entity\WdbAnnotationPage|null
   *   The loaded entity, or NULL if not found.
   */
  private function loadAnnotationPageFromCanvasUri(string $canvas_uri): ?WdbAnnotationPage {
    $path = parse_url($canvas_uri, PHP_URL_PATH);
    if (!$path) {
      return NULL;
    }
    $pages = $this->entityTypeManager()->getStorage('wdb_annotation_page')->loadByProperties(['canvas_identifier_fragment' => $path]);
    return reset($pages) ?: NULL;
  }

  /**
   * Gets the absolute Canvas URI for a page entity.
   *
   * @param \Drupal\wdb_core\Entity\WdbAnnotationPage $page
   *   The page entity.
   *
   * @return string
   *   The absolute Canvas URI.
   */
  private function getCanvasUri(WdbAnnotationPage $page): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request->getSchemeAndHttpHost() . $page->get('canvas_identifier_fragment')->value;
  }

  /**
   * Formats an array of points into an SVG path "d" attribute string.
   *
   * @param array $points
   *   An array of [x, y] coordinate pairs.
   *
   * @return string
   *   The SVG path data string.
   */
  private function formatPointsToSvgPathD(array $points): string {
    if (empty($points)) {
      return '';
    }
    $path_parts = [];
    // The first point uses the "M" (Move to) command.
    $first_point = array_shift($points);
    $path_parts[] = sprintf('M%s,%s', $first_point[0], $first_point[1]);

    // The remaining points use the "L" (Line to) command.
    foreach ($points as $point) {
      $path_parts[] = sprintf('L%s,%s', $point[0], $point[1]);
    }

    // Close the path.
    $path_parts[] = 'Z';

    return implode(' ', $path_parts);
  }

  /**
   * Gets a single annotation in W3C JSON-LD format.
   *
   * @param \Drupal\wdb_core\Entity\WdbLabel $wdb_label
   *   The label entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the annotation.
   */
  public function getIndividualAnnotation(WdbLabel $wdb_label): JsonResponse {
    $this->resourceAccess()->assertViewAccess($wdb_label);
    return new JsonResponse($this->buildAnnotationJsonLd($wdb_label));
  }

  /**
   * Accepts bounded polygons with finite numeric coordinates only.
   */
  private function validPoints($points): bool {
    if (!is_array($points) || count($points) < 3 || count($points) > 10000) {
      return FALSE;
    }
    foreach ($points as $point) {
      if (!is_array($point) || count($point) !== 2 || !isset($point[0], $point[1])) {
        return FALSE;
      }
      foreach ($point as $coordinate) {
        if (!is_numeric($coordinate) || !is_finite((float) $coordinate)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Builds a W3C Web Annotation compliant JSON-LD object.
   *
   * @param \Drupal\wdb_core\Entity\WdbLabel $wdb_label
   *   The label entity.
   *
   * @return array
   *   The annotation array.
   */
  private function buildAnnotationJsonLd(WdbLabel $wdb_label): array {
    $polygon_points_array = array_map(fn($item) => $item['value'], $wdb_label->get('polygon_points')->getValue());
    $svg_string = '<svg><polygon points="' . implode(' ', $polygon_points_array) . '"></polygon></svg>';
    $wdb_annotation_page = $wdb_label->get('annotation_page_ref')->entity;

    return [
      '@context' => 'http://www.w3.org/ns/anno.jsonld',
      'id' => $wdb_label->get('annotation_uri')->value,
      'type' => 'Annotation',
      'body' => [
        ['type' => 'TextualBody', 'purpose' => 'commenting', 'value' => $wdb_label->label()],
      ],
      'target' => [
        'source' => $wdb_annotation_page ? $this->getCanvasUri($wdb_annotation_page) : '',
        'selector' => ['type' => 'SvgSelector', 'value' => $svg_string],
      ],
      'created' => gmdate('Y-m-d\TH:i:s\Z', $wdb_label->get('created')->value),
      'modified' => gmdate('Y-m-d\TH:i:s\Z', $wdb_label->get('changed')->value),
    ];
  }

  // End of controller.
}
