<?php

namespace Drupal\wdb_cantaloupe_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\wdb_core\Access\SubsystemAccessPolicyInterface;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Service\WdbDataService;
use Drupal\wdb_cantaloupe_auth\Service\TokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Issues fresh IIIF tokens for long-lived viewer sessions.
 */
class TokenController extends ControllerBase {

  /**
   * Access policy evaluator for subsystems.
   */
  protected SubsystemAccessPolicyInterface $subsystemAccessPolicy;

  /**
   * Data service for issuing tokens and resolving metadata.
   */
  protected WdbDataService $wdbDataService;

  /**
   * Optional token manager service (only present when submodule enabled).
   */
  protected ?TokenManagerInterface $tokenManager;

  /**
   * Constructs the controller.
   */
  public function __construct(SubsystemAccessPolicyInterface $subsystemAccessPolicy, WdbDataService $wdbDataService, ?TokenManagerInterface $tokenManager = NULL) {
    $this->subsystemAccessPolicy = $subsystemAccessPolicy;
    $this->wdbDataService = $wdbDataService;
    $this->tokenManager = $tokenManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wdb_core.subsystem_access_policy'),
      $container->get('wdb_core.data_service'),
      $container->get('wdb_cantaloupe_auth.token_manager'),
    );
  }

  /**
   * Returns a refreshed token payload for an annotation page.
   */
  public function refresh(WdbAnnotationPage $wdb_annotation_page): JsonResponse {
    $source = $wdb_annotation_page->get('source_ref')->entity;
    if (!$source) {
      throw new NotFoundHttpException('Source not found for annotation page.');
    }
    $subsystem_term = $source->get('subsystem_tags')->entity;
    $subsysname = $subsystem_term ? $subsystem_term->getName() : NULL;
    if (!$subsysname) {
      throw new NotFoundHttpException('Subsystem not associated with annotation page.');
    }

    if (!$this->subsystemAccessPolicy->userHasAccess($subsysname, $this->currentUser(), ['permission' => 'view wdb gallery pages'])) {
      throw new AccessDeniedHttpException();
    }

    $image_identifier = $wdb_annotation_page->getImageIdentifier();
    if (empty($image_identifier)) {
      throw new NotFoundHttpException('Annotation page is missing an IIIF image identifier.');
    }

    $context = $this->wdbDataService->getIiifAuthContext($subsysname, $image_identifier);
    if (empty($context)) {
      throw new NotFoundHttpException('Token service unavailable.');
    }

    if ($this->tokenManager) {
      $context['ttl'] = $this->tokenManager->getTtl();
    }

    return new JsonResponse($context);
  }

}
