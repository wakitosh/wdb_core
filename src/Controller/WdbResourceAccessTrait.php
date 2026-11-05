<?php

namespace Drupal\wdb_core\Controller;

use Drupal\wdb_core\Access\WdbResourceAccess;

/**
 * Shares resource authorization across controller entry points.
 */
trait WdbResourceAccessTrait {

  protected function resourceAccess(): WdbResourceAccess {
    return \Drupal::service('wdb_core.resource_access');
  }

}
