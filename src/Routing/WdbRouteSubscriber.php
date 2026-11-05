<?php

namespace Drupal\wdb_core\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events to alter route definitions.
 */
class WdbRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Source views use the source's subsystem policy instead of requiring
    // entity administration permission.
    if ($route = $collection->get('entity.wdb_source.canonical')) {
      $requirements = $route->getRequirements();

      unset($requirements['_entity_access']);

      // Keep the general permission and enforce the actual source's policy.
      $requirements['_permission'] = 'access content';
      $requirements['_custom_access'] = 'wdb_core.resource_access::sourceRouteAccess';

      $route->setRequirements($requirements);
    }
  }

}
