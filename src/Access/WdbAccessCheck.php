<?php

namespace Drupal\wdb_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * Checks access for WDB gallery pages based on subsystem configuration.
 *
 * This access checker determines if a user can view a gallery page by checking
 * the 'allowAnonymous' setting in the corresponding subsystem's configuration.
 */
class WdbAccessCheck implements AccessInterface, ContainerInjectionInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The subsystem access policy.
   *
   * @var \Drupal\wdb_core\Access\SubsystemAccessPolicyInterface
   */
  protected SubsystemAccessPolicyInterface $subsystemAccessPolicy;

  /**
   * Constructs a new WdbAccessCheck object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\wdb_core\Access\SubsystemAccessPolicyInterface $subsystemAccessPolicy
   *   The subsystem access policy.
   */
  public function __construct(ConfigFactoryInterface $config_factory, SubsystemAccessPolicyInterface $subsystemAccessPolicy) {
    $this->configFactory = $config_factory;
    $this->subsystemAccessPolicy = $subsystemAccessPolicy;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('wdb_core.subsystem_access_policy')
    );
  }

  /**
   * Checks access for gallery pages.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param string $subsysname
   *   The machine name of the subsystem from the URL.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(AccountInterface $account, Route $route, string $subsysname = ''): AccessResult {
    if (empty($subsysname)) {
      // If there's no subsystem context, fall back to route permission.
      // The permission is read from the route definition.
      $permission = $route->getOption('permission') ?: 'access content';
      return AccessResult::allowedIfHasPermission($account, $permission);
    }

    $permission = $route->getOption('permission') ?: 'access content';
    return $this->subsystemAccessPolicy->checkAccess($subsysname, $account, ['permission' => $permission]);
  }

}
