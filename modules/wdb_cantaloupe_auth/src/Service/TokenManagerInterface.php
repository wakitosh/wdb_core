<?php

namespace Drupal\wdb_cantaloupe_auth\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Defines the contract for issuing and formatting IIIF access tokens.
 */
interface TokenManagerInterface {

  /**
   * Issues a short-lived token for the given IIIF resource context.
   *
   * @param string $subsystem
   *   The subsystem machine name.
   * @param string $image_identifier
   *   The IIIF image identifier.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account for which the token should be issued. Falls back to the
   *   anonymous user when omitted.
   *
   * @return string|null
   *   The opaque token string or NULL on failure.
   */
  public function issueToken(string $subsystem, string $image_identifier, ?AccountInterface $account = NULL): ?string;

  /**
   * Appends a token to the provided URL using the configured query parameter.
   *
   * @param string $url
   *   The absolute or relative IIIF URL.
   * @param string|null $token
   *   The token to append. When NULL the original URL is returned untouched.
   *
   * @return string
   *   The URL with the token query parameter appended or replaced.
   */
  public function appendTokenToUrl(string $url, ?string $token = NULL): string;

  /**
   * Returns the query parameter name that should carry issued tokens.
   *
   * @return string
   *   The parameter name.
   */
  public function getQueryParameterName(): string;

  /**
   * Validates and decodes a previously-issued token.
   *
   * @param string $token
   *   The opaque token string to validate.
   *
   * @return array|null
   *   The decoded payload when the token is valid, or NULL otherwise.
   */
  public function validateToken(string $token): ?array;

  /**
   * Returns the configured TTL for newly issued tokens, in seconds.
   *
   * @return int
   *   The effective TTL (always > 0).
   */
  public function getTtl(): int;

}
