<?php

namespace Drupal\wdb_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\wdb_core\Service\WdbDataService;

/**
 * Default access policy based on allowAnonymous + route permission fallback.
 */
class DefaultSubsystemAccessPolicy implements SubsystemAccessPolicyInterface {

  /**
   * Cached subsystem group entities keyed by subsystem machine name.
   *
   * @var array<string, \Drupal\group\Entity\GroupInterface|null>
   */
  protected array $groupCache = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected WdbDataService $wdbDataService,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?GroupMembershipLoaderInterface $membershipLoader,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(string $subsystem, AccountInterface $account, array $context = []): AccessResultInterface {
    $config = $this->wdbDataService->getSubsystemConfig($subsystem);
    if (!$config) {
      return AccessResult::forbidden('Unknown subsystem.');
    }

    if ($config->get('allowAnonymous')) {
      return AccessResult::allowed()->addCacheableDependency($config);
    }

    $group = $this->loadSubsystemGroup($subsystem, $config);
    if ($group && $this->membershipLoader) {
      if ($account->isAnonymous()) {
        return AccessResult::forbidden('Login required for restricted subsystem.')
          ->addCacheableDependency($config)
          ->addCacheableDependency($group);
      }
      $membership = $this->membershipLoader->load($group, $account);
      if (!$membership) {
        return AccessResult::forbidden('User is not a member of the subsystem group.')
          ->addCacheableDependency($config)
          ->addCacheableDependency($group);
      }
    }

    $permission = $context['permission'] ?? 'access content';
    $result = AccessResult::allowedIfHasPermission($account, $permission)
      ->addCacheableDependency($config);
    if ($group) {
      $result->addCacheableDependency($group);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function userHasAccess(string $subsystem, AccountInterface $account, array $context = []): bool {
    return $this->checkAccess($subsystem, $account, $context)->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function allowsAnonymous(string $subsystem): bool {
    $config = $this->wdbDataService->getSubsystemConfig($subsystem);
    return (bool) ($config?->get('allowAnonymous'));
  }

  /**
   * Loads the Drupal Group linked to a subsystem, if configured.
   */
  protected function loadSubsystemGroup(string $subsystem, ImmutableConfig $config): ?GroupInterface {
    if (array_key_exists($subsystem, $this->groupCache)) {
      return $this->groupCache[$subsystem];
    }

    $group = NULL;
    $group_uuid = $config?->get('group_uuid');
    if (!empty($group_uuid) && $this->entityTypeManager->hasDefinition('group')) {
      $storage = $this->entityTypeManager->getStorage('group');
      $groups = $storage->loadByProperties(['uuid' => $group_uuid]);
      $group = $groups ? reset($groups) : NULL;
    }
    $this->groupCache[$subsystem] = $group instanceof GroupInterface ? $group : NULL;
    return $this->groupCache[$subsystem];
  }

}
