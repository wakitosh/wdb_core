<?php

namespace Drupal\wdb_cantaloupe_auth\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\wdb_core\Access\SubsystemAccessPolicyInterface;
use Drupal\wdb_core\Service\WdbDataService;
use Drupal\wdb_cantaloupe_auth\Service\TokenManagerInterface;
use Psr\Log\LoggerInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the Cantaloupe IIIF Server authorization endpoint.
 */
class AuthController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The WDB data service.
   *
   * @var \Drupal\wdb_core\Service\WdbDataService
   */
  protected WdbDataService $wdbDataService;

  /**
   * The subsystem access policy service.
   *
   * @var \Drupal\wdb_core\Access\SubsystemAccessPolicyInterface
   */
  protected SubsystemAccessPolicyInterface $subsystemAccessPolicy;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The token manager service.
   */
  protected ?TokenManagerInterface $tokenManager;

  /**
   * Constructs a new AuthController object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, WdbDataService $wdbDataService, SubsystemAccessPolicyInterface $subsystemAccessPolicy, Connection $database, ?TokenManagerInterface $tokenManager = NULL) {
    $this->configFactory = $config_factory;
    $this->wdbDataService = $wdbDataService;
    $this->subsystemAccessPolicy = $subsystemAccessPolicy;
    $this->database = $database;
    $this->tokenManager = $tokenManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('wdb_core.data_service'),
      $container->get('wdb_core.subsystem_access_policy'),
      $container->get('database'),
      $container->get('wdb_cantaloupe_auth.token_manager')
    );
  }

  /**
   * Authorizes a request from the Cantaloupe delegate script.
   */
  public function authorizeRequest(Request $request): JsonResponse {
    $logger = $this->getLogger('wdb_cantaloupe_auth');
    $payload = json_decode($request->getContent(), TRUE);
    $identifier = is_array($payload) ? ($payload['identifier'] ?? NULL) : NULL;
    if (!is_string($identifier) || !$this->isPlainIdentifier($identifier)) {
      return new JsonResponse(['authorized' => FALSE, 'reason' => 'Invalid identifier.']);
    }
    $parts = explode('/', $identifier);
    $subsysname = $parts[1] ?? NULL;

    if (!$subsysname) {
      $logger->warning('Subsystem not identified in identifier: @id', ['@id' => $identifier]);
      return new JsonResponse(['authorized' => FALSE, 'reason' => 'Subsystem not identified.']);
    }

    $subsystem_config = $this->wdbDataService->getSubsystemConfig($subsysname);
    if (!$subsystem_config) {
      return new JsonResponse(['authorized' => FALSE, 'reason' => 'Subsystem configuration not found.'], 404);
    }

    if ($this->subsystemAccessPolicy->allowsAnonymous($subsysname)) {
      return new JsonResponse(['authorized' => TRUE, 'reason' => 'Subsystem allows anonymous access.']);
    }

    $token_response = $this->authorizeViaToken($payload, $identifier, $subsysname, $logger);
    if ($token_response instanceof JsonResponse) {
      return $token_response;
    }

    // Build cookie map from payload cookies.
    $cookies = is_array($payload['cookies'] ?? NULL) ? $payload['cookies'] : [];
    $cookie_map = [];
    foreach (array_filter($cookies, 'is_string') as $cookie_string) {
      $pairs = preg_split('/;\s*/', $cookie_string);
      foreach ($pairs as $pair) {
        if (strpos($pair, '=') !== FALSE) {
          [$name, $value] = explode('=', $pair, 2);
          if ($name !== '' && !array_key_exists($name, $cookie_map)) {
            $cookie_map[$name] = $value;
          }
        }
      }
    }

    // Candidate session IDs: session_name() first, then any SESS*/SSESS*.
    $session_cookie_name = session_name();
    $session_ids = [];
    if (isset($cookie_map[$session_cookie_name])) {
      $session_ids[] = trim(urldecode($cookie_map[$session_cookie_name]));
    }
    foreach ($cookie_map as $name => $value) {
      if (preg_match('/^S?SESS[0-9a-f]+$/i', $name)) {
        $sid = trim(urldecode($value));
        if ($sid !== '' && !in_array($sid, $session_ids, TRUE)) {
          $session_ids[] = $sid;
        }
      }
    }

    if (empty($session_ids)) {
      return new JsonResponse(['authorized' => FALSE, 'reason' => 'No session cookie found.']);
    }

    // Resolve UID by trying each SID until one succeeds.
    $uid = 0;
    foreach ($session_ids as $sid) {
      $uid = (int) ($this->getUidFromSessionId($sid) ?? 0);
      if ($uid > 0) {
        break;
      }
    }

    if ($uid <= 0) {
      return new JsonResponse(['authorized' => FALSE, 'reason' => 'Anonymous user session.']);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if ($user instanceof UserInterface && $user->isActive() && $this->subsystemAccessPolicy->userHasAccess($subsysname, $user, ['permission' => 'view wdb gallery pages'])) {
      return new JsonResponse(['authorized' => TRUE, 'reason' => 'User has permission.']);
    }

    $logger->warning('Authorization denied for @subsys (user @uid lacks permission).', [
      '@uid' => $uid,
      '@subsys' => ($subsysname ?? 'N/A'),
    ]);
    return new JsonResponse(['authorized' => FALSE, 'reason' => 'User lacks permission.']);
  }

  /**
   * Checks that an identifier names one file path and nothing else.
   *
   * The decision below is made from the identifier as sent, but the image
   * server strips "../", "/.." and their backslash forms before opening a
   * file. Identifiers it would rewrite are refused, so the subsystem that is
   * authorized is always the one that is read.
   */
  private function isPlainIdentifier(string $identifier): bool {
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $identifier) || str_contains($identifier, '../') || str_contains($identifier, '/..')) {
      return FALSE;
    }
    foreach (explode('/', $identifier) as $segment) {
      if ($segment === '' || $segment === '.' || $segment === '..') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Attempts to authorize a request via signed token payload.
   */
  protected function authorizeViaToken(array $payload, string $identifier, string $subsysname, LoggerInterface $logger): ?JsonResponse {
    if (!$this->tokenManager) {
      return NULL;
    }

    $token = is_string($payload['token'] ?? NULL) ? trim($payload['token']) : '';
    if ($token === '') {
      return NULL;
    }

    $token_payload = $this->tokenManager->validateToken($token);
    if (!$token_payload) {
      $logger->warning('Invalid or expired IIIF token presented for identifier @id. Falling back to session lookup.', ['@id' => $identifier]);
      return NULL;
    }

    $token_subsystem = strtolower((string) ($token_payload['s'] ?? ''));
    if ($token_subsystem !== strtolower($subsysname)) {
      $logger->warning('Token subsystem mismatch for identifier @id (expected @expected, received @actual).', [
        '@id' => $identifier,
        '@expected' => $subsysname,
        '@actual' => $token_payload['s'] ?? 'n/a',
      ]);
      return NULL;
    }

    if (($token_payload['i'] ?? '') !== $identifier) {
      $logger->warning('Token identifier mismatch (expected @expected, received @actual).', [
        '@expected' => $identifier,
        '@actual' => $token_payload['i'] ?? 'n/a',
      ]);
      return NULL;
    }

    $uid = (int) ($token_payload['u'] ?? 0);
    if ($uid <= 0) {
      $logger->warning('Token missing user context for identifier @id.', ['@id' => $identifier]);
      return NULL;
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if ($user instanceof UserInterface && $user->isActive() && $this->subsystemAccessPolicy->userHasAccess($subsysname, $user, ['permission' => 'view wdb gallery pages'])) {
      return new JsonResponse(['authorized' => TRUE, 'reason' => 'Token validated.']);
    }

    $logger->warning('Authorization denied for @subsys via token (user @uid lacks permission).', [
      '@uid' => $uid,
      '@subsys' => $subsysname,
    ]);
    return new JsonResponse(['authorized' => FALSE, 'reason' => 'User lacks permission.']);
  }

  /**
   * Resolves a UID from a session ID via the {sessions} table.
   *
   * Core records the logged-in user in the uid column and stores only a hash of
   * the session ID. The session payload is deliberately not read: it can hold
   * client-supplied text, so it must never decide who the user is. Sites using
   * a non-database session handler have no such row and must rely on tokens.
   */
  private function getUidFromSessionId(string $session_id): ?int {
    try {
      $uid = $this->database->select('sessions', 's')
        ->fields('s', ['uid'])
        ->condition('sid', Crypt::hashBase64($session_id))
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      $this->getLogger('wdb_cantaloupe_auth')->warning('Could not read the sessions table: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    return $uid ? (int) $uid : NULL;
  }

}
