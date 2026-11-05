<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\wdb_core\Service\WdbTextGeneratorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Controller for providing full text content via an API endpoint.
 */
class FullTextController extends ControllerBase implements ContainerInjectionInterface {

  use WdbResourceAccessTrait;

  /**
   * The WDB text generator service.
   *
   * @var \Drupal\wdb_core\Service\WdbTextGeneratorService
   */
  protected WdbTextGeneratorService $textGenerator;

  /**
   * Constructs a new FullTextController object.
   *
   * @param \Drupal\wdb_core\Service\WdbTextGeneratorService $textGenerator
   *   The WDB text generator service.
   */
  public function __construct(WdbTextGeneratorService $textGenerator) {
    $this->textGenerator = $textGenerator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wdb_core.text_generator')
    );
  }

  /**
   * Gets the full text content for a specific page.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the rendered HTML of the full text.
   */
  public function getFullTextContent(string $subsysname, string $source, int $page): JsonResponse {
    $this->resourceAccess()->loadReadablePage($subsysname, $source, $page);
    $result = $this->textGenerator->getFullText($subsysname, $source, $page);
    return new JsonResponse($result);
  }

}
