<?php

namespace Drupal\wdb_core\Controller;

use Drupal\wdb_core\Entity\WdbLabel;
use Drupal\Core\Controller\ControllerBase;
use Drupal\wdb_core\Service\WdbDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Controller for handling AJAX requests for dialog content.
 *
 * This controller is responsible for fetching rendered HTML content to be
 * displayed in modal dialogs or dynamic panels, such as the annotation
 * details panel.
 */
class DialogContentController extends ControllerBase implements ContainerInjectionInterface {

  use WdbResourceAccessTrait;

  /**
   * The WDB data service.
   *
   * @var \Drupal\wdb_core\Service\WdbDataService
   */
  protected WdbDataService $wdbDataService;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * Constructs a new DialogContentController object.
   *
   * @param \Drupal\wdb_core\Service\WdbDataService $wdbDataService
   *   The WDB data service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(WdbDataService $wdbDataService, RendererInterface $renderer) {
    $this->wdbDataService = $wdbDataService;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wdb_core.data_service'),
      $container->get('renderer')
    );
  }

  /**
   * Gets annotation details by its URI, within a specific subsystem context.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the rendered HTML content and title.
   */
  public function getDetailsByUri(Request $request, string $subsysname): JsonResponse {
    $annotation_uri = $request->query->get('uri');
    if (empty($annotation_uri)) {
      return new JsonResponse(['error' => 'Missing uri parameter.'], 400);
    }

    /** @var \Drupal\wdb_core\Entity\WdbLabel[] $wdb_labels */
    $wdb_labels = $this->entityTypeManager()->getStorage('wdb_label')->loadByProperties(['annotation_uri' => $annotation_uri]);
    $wdb_label = !empty($wdb_labels) ? reset($wdb_labels) : NULL;

    if (!$wdb_label || !($wdb_label instanceof WdbLabel)) {
      return new JsonResponse(['error' => 'Annotation not found.'], 404);
    }
    $this->resourceAccess()->assertViewAccess($wdb_label, $subsysname);

    // Pass the loaded entity object and the subsystem name to the service.
    $data = $this->wdbDataService->getAnnotationDetails($wdb_label, $subsysname);

    // Render the data using a specific Twig template.
    $content = [
      '#theme' => 'wdb_annotation_info_content',
      '#data' => $data,
      '#subsysname' => $subsysname,
    ];

    return new JsonResponse([
      'title' => $data['title'],
      'content' => $this->renderer->renderRoot($content),
      'current_word_unit_id' => $data['retrieved_data']['current_word_unit_id'] ?? NULL,
    ]);
  }

}
