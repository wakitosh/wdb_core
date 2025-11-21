<?php

namespace Drupal\wdb_core\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Contract for evaluating subsystem-level access.
 */
interface SubsystemAccessPolicyInterface {

  /**
   * Checks whether a user may access a subsystem.
   *
   * @param string $subsystem
   *   The subsystem machine name.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to evaluate.
   * @param array $context
   *   Optional context (e.g. fallback permission string).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result with cacheability metadata applied.
   */
  public function checkAccess(string $subsystem, AccountInterface $account, array $context = []): AccessResultInterface;

  /**
   * Convenience helper to return a boolean access decision.
   *
   * @param string $subsystem
   *   The subsystem machine name.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to evaluate.
   * @param array $context
   *   Optional context array.
   *
   * @return bool
   *   TRUE when access is allowed.
   */
  public function userHasAccess(string $subsystem, AccountInterface $account, array $context = []): bool;

  /**
   * Whether the subsystem is configured for anonymous access.
   *
   * @param string $subsystem
   *   The subsystem machine name.
   *
   * @return bool
   *   TRUE when anonymous access is allowed.
   */
  public function allowsAnonymous(string $subsystem): bool;

}
