<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\wdb_core\Service\WdbTextGeneratorService;
use Drupal\wdb_core\Lib\HullJsPhp\HullPHP;
use Drupal\wdb_core\Service\WdbDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for handling data export and other API utilities.
 */
class ExportController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The WDB data service.
   *
   * @var \Drupal\wdb_core\Service\WdbDataService
   */
  protected WdbDataService $wdbDataService;

  /**
   * The WDB text generator service.
   *
   * @var \Drupal\wdb_core\Service\WdbTextGeneratorService
   */
  protected WdbTextGeneratorService $textGenerator;

  /**
   * The HullPHP calculation service.
   *
   * @var \Drupal\wdb_core\Lib\HullJsPhp\HullPHP
   */
  protected HullPHP $hullCalculator;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * Constructs a new ExportController object.
   *
   * @param \Drupal\wdb_core\Service\WdbDataService $wdbDataService
   *   The WDB data service.
   * @param \Drupal\wdb_core\Service\WdbTextGeneratorService $textGenerator
   *   The WDB text generator service.
   * @param \Drupal\wdb_core\Lib\HullJsPhp\HullPHP $hullCalculator
   *   The HullPHP calculation service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(WdbDataService $wdbDataService, WdbTextGeneratorService $textGenerator, HullPHP $hullCalculator, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer) {
    $this->wdbDataService = $wdbDataService;
    $this->textGenerator = $textGenerator;
    $this->hullCalculator = $hullCalculator;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wdb_core.data_service'),
      $container->get('wdb_core.text_generator'),
      $container->get('wdb_core.hull_calculator'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Generates and serves a TEI/XML file for a given page.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response object containing the XML file.
   */
  public function downloadTei(string $subsysname, string $source, int $page): Response {
    $page_data = $this->wdbDataService->getDataForExport($subsysname, $source, $page);

    $subsystem_config = $this->wdbDataService->getSubsystemConfig($subsysname);
    if (!$subsystem_config) {
      return new Response($this->t('Subsystem configuration not found for "@subsys".', ['@subsys' => $subsysname]), 404);
    }
    $template_string = $subsystem_config->get('export_templates.tei');

    $build = [
      '#type' => 'inline_template',
      '#template' => $template_string,
      '#context' => ['page_data' => $page_data],
    ];
    $output = $this->renderer->renderRoot($build);

    $response = new Response($output);
    $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $source . '_p' . $page . '.xml"');
    $response->headers->set('X-Robots-Tag', 'noindex');
    return $response;
  }

  /**
   * Generates and serves an RDF/XML file for a given page.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response object containing the RDF file.
   */
  public function downloadRdf(string $subsysname, string $source, int $page): Response {
    $page_data = $this->wdbDataService->getDataForExport($subsysname, $source, $page);

    $subsystem_config = $this->wdbDataService->getSubsystemConfig($subsysname);
    if (!$subsystem_config) {
      return new Response($this->t('Subsystem configuration not found for "@subsys".', ['@subsys' => $subsysname]), 404);
    }
    $template_string = $subsystem_config->get('export_templates.rdf');

    $build = [
      '#type' => 'inline_template',
      '#template' => $template_string,
      '#context' => ['page_data' => $page_data],
    ];
    $output = $this->renderer->renderRoot($build);

    $response = new Response($output);
    $response->headers->set('Content-Type', 'application/rdf+xml; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $source . '_p' . $page . '.rdf"');
    $response->headers->set('X-Robots-Tag', 'noindex');
    return $response;
  }

  /**
   * Generates and serves a plain text file of the full text.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response object containing the text file.
   */
  public function downloadText(string $subsysname, string $source, int $page): Response {
    $text_data = $this->textGenerator->getFullText($subsysname, $source, $page);
    $plain_text = strip_tags($text_data['html'] ?? 'No text available.');

    $response = new Response($plain_text);
    $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $source . '_p' . $page . '.txt"');
    $response->headers->set('X-Robots-Tag', 'noindex');

    return $response;
  }

  /**
   * API endpoint to calculate a concave hull from a given set of points.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the calculated hull points.
   */
  public function calculateHullApi(Request $request): JsonResponse {
    $points_json = $request->query->get('points');
    $concavity = (int) $request->query->get('concavity', 20);

    if (empty($points_json)) {
      return new JsonResponse(['error' => 'Missing "points" parameter.'], 400);
    }

    $points_strings = json_decode($points_json, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($points_strings)) {
      return new JsonResponse(['error' => 'Invalid "points" JSON format.'], 400);
    }

    // Convert the array of "X,Y" strings to the [ [x1, y1], [x2, y2], ... ]
    // format expected by HullPHP.
    $points_for_hull = [];
    foreach ($points_strings as $point_str) {
      $coords = explode(',', $point_str);
      if (count($coords) === 2) {
        $points_for_hull[] = [(float) $coords[0], (float) $coords[1]];
      }
    }

    if (empty($points_for_hull)) {
      return new JsonResponse(['error' => 'No valid points found in the "points" parameter.'], 400);
    }

    $hull = $this->hullCalculator->calculate($points_for_hull, $concavity);

    return new JsonResponse($hull);
  }

  /**
   * API endpoint to calculate a bounding box from a set of points.
   *
   * Accepts a GET parameter "points" (JSON array of "X,Y" strings).
   * Returns { x, y, w, h } where (x,y) is top-left and (w,h) width/height.
   *
   * Example:
   *   /wdb/api/bbox?points=["10,20","30,40","25,35"]
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the bounding box or an error.
   */
  public function calculateBboxApi(Request $request): JsonResponse {
    $points_json = $request->query->get('points');
    if (empty($points_json)) {
      return new JsonResponse(['error' => 'Missing "points" parameter.'], 400);
    }

    $points_strings = json_decode($points_json, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($points_strings)) {
      return new JsonResponse(['error' => 'Invalid "points" JSON format.'], 400);
    }

    $all_x = [];
    $all_y = [];
    foreach ($points_strings as $point_str) {
      $coords = explode(',', $point_str);
      if (count($coords) === 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $all_x[] = (float) $coords[0];
        $all_y[] = (float) $coords[1];
      }
    }
    if (empty($all_x)) {
      return new JsonResponse(['error' => 'No valid points found.'], 400);
    }
    $min_x = min($all_x);
    $max_x = max($all_x);
    $min_y = min($all_y);
    $max_y = max($all_y);
    $bbox = [
      'x' => round($min_x),
      'y' => round($min_y),
      'w' => max(1, round($max_x - $min_x)),
      'h' => max(1, round($max_y - $min_y)),
    ];
    return new JsonResponse($bbox);
  }

  /**
   * Gets the first annotation URI for a given Word Unit original ID.
   *
   * @param string $wdb_word_unit_original_id
   *   The original_word_unit_identifier of the Word Unit entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the annotation URI.
   */
  public function getUriFromWordUnit(string $wdb_word_unit_original_id): JsonResponse {

    // 1. Find the WdbWordUnit entity based on the provided ID.
    $wu_storage = $this->entityTypeManager->getStorage('wdb_word_unit');
    $wu_entities = $wu_storage->loadByProperties(['original_word_unit_identifier' => $wdb_word_unit_original_id]);

    if (empty($wu_entities)) {
      return new JsonResponse(['error' => 'Word Unit not found.'], 404);
    }
    $wu_entity = reset($wu_entities);

    // 2. Find the first sign of that word unit (minimum sign_sequence).
    $map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $map_ids = $map_storage->getQuery()
      ->condition('word_unit_ref', $wu_entity->id())
      ->sort('sign_sequence', 'ASC')
      ->range(0, 1)
      ->accessCheck(FALSE)->execute();

    if (empty($map_ids)) {
      return new JsonResponse(['error' => 'No signs found for this Word Unit.'], 404);
    }

    // 3. From the first sign, find the corresponding WdbLabel and return its
    // annotation_uri.
    /** @var \Drupal\wdb_core\Entity\WdbWordMap|null $first_map */
    $first_map = $map_storage->load(reset($map_ids));
    if (!$first_map) {
      return new JsonResponse(['error' => 'Word map entity could not be loaded.'], 500);
    }
    /** @var \Drupal\wdb_core\Entity\WdbSignInterpretation|null $si */
    $si = $first_map->get('sign_interpretation_ref')->entity ?? NULL;
    /** @var \Drupal\wdb_core\Entity\WdbLabel|null $label */
    $label = $si ? $si->get('label_ref')->entity : NULL;

    if ($label && $label->get('annotation_uri')->value) {
      return new JsonResponse(['annotation_uri' => $label->get('annotation_uri')->value]);
    }

    return new JsonResponse(['error' => 'No associated annotation URI found.'], 404);
  }

}
