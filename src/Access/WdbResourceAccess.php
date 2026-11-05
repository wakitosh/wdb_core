<?php

namespace Drupal\wdb_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Entity\WdbLabel;
use Drupal\wdb_core\Entity\WdbSource;
use Drupal\wdb_core\Entity\WdbWordUnit;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Applies subsystem authorization to the actual resource being requested.
 */
class WdbResourceAccess {

  public function __construct(
    protected SubsystemAccessPolicyInterface $policy,
    protected AccountProxyInterface $account,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves the owning source without trusting a client-supplied subsystem.
   */
  public function getSource(EntityInterface $entity): ?WdbSource {
    if ($entity instanceof WdbLabel) {
      $entity = $entity->get('annotation_page_ref')->entity;
    }
    if ($entity instanceof WdbAnnotationPage || $entity instanceof WdbWordUnit) {
      $entity = $entity->get('source_ref')->entity;
    }
    return $entity instanceof WdbSource ? $entity : NULL;
  }

  /**
   * Checks membership and the requested subsystem's relationship to a source.
   */
  public function checkAccess(EntityInterface $entity, ?string $subsystem = NULL, string $permission = 'view wdb gallery pages', ?AccountInterface $account = NULL): AccessResultInterface {
    $source = $this->getSource($entity);
    if (!$source) {
      return AccessResult::forbidden('Resource has no source.')->setCacheMaxAge(0);
    }
    $result = AccessResult::neutral()->addCacheableDependency($source)->cachePerUser();
    foreach ($source->get('subsystem_tags')->referencedEntities() as $term) {
      $name = $term->getName();
      if ($subsystem !== NULL && strcasecmp($name, $subsystem) !== 0) {
        continue;
      }
      $result = $result->orIf($this->policy->checkAccess($name, $account ?? $this->account, ['permission' => $permission]));
    }
    return $result;
  }

  /**
   * Requires read access, optionally within a specific subsystem.
   */
  public function assertViewAccess(EntityInterface $entity, ?string $subsystem = NULL): void {
    if (!$this->checkAccess($entity, $subsystem)->isAllowed()) {
      throw new AccessDeniedHttpException('Access to this WDB resource is denied.');
    }
  }

  /**
   * Public visibility never grants permission to mutate annotations.
   */
  public function assertOperationAccess(EntityInterface $entity, string $permission, ?string $subsystem = NULL): void {
    if (!$this->account->hasPermission($permission) || !$this->checkAccess($entity, $subsystem, $permission)->isAllowed()) {
      throw new AccessDeniedHttpException('Access to modify this WDB resource is denied.');
    }
  }

  /**
   * Access callback for the numeric source canonical route.
   */
  public function sourceRouteAccess(WdbSource $wdb_source, AccountInterface $account): AccessResultInterface {
    return $this->checkAccess($wdb_source, NULL, 'view wdb gallery pages', $account);
  }

  /**
   * Loads and authorizes a page using both its source and subsystem.
   */
  public function loadReadablePage(string $subsystem, string $source_identifier, int $page_number): WdbAnnotationPage {
    $sources = $this->entityTypeManager->getStorage('wdb_source')->loadByProperties(['source_identifier' => $source_identifier]);
    $source = reset($sources);
    if (!$source instanceof WdbSource) {
      throw new NotFoundHttpException();
    }
    $this->assertViewAccess($source, $subsystem);
    $pages = $this->entityTypeManager->getStorage('wdb_annotation_page')->loadByProperties([
      'source_ref' => $source->id(),
      'page_number' => $page_number,
    ]);
    $page = reset($pages);
    if (!$page instanceof WdbAnnotationPage) {
      throw new NotFoundHttpException();
    }
    return $page;
  }

  /**
   * Returns only readable subsystem IDs for query-level filtering and counts.
   */
  public function readableSubsystemIds(): array {
    $ids = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'subsystem']);
    foreach ($terms as $term) {
      if ($this->policy->userHasAccess($term->getName(), $this->account, ['permission' => 'view wdb gallery pages'])) {
        $ids[] = $term->id();
      }
    }
    return $ids;
  }

}
