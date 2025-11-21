<?php

namespace Drupal\wdb_cantaloupe_auth\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\PrivateKey;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;

/**
 * Issues signed IIIF tokens that can be embedded into Image API URLs.
 */
class TokenManager implements TokenManagerInterface {

  /**
   * Cached configuration.
   */
  protected ImmutableConfig $settings;

  /**
   * Provides request timestamps.
   */
  protected TimeInterface $time;

  /**
   * The site private key service.
   */
  protected PrivateKey $privateKey;

  /**
   * Simple per-request cache for issued tokens.
   *
   * @var array<string,string|null>
   */
  protected array $requestCache = [];

  /**
   * Cached derived signing secret.
   */
  protected ?string $signingSecret = NULL;

  /**
   * Constructs a new token manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TimeInterface $time, PrivateKey $private_key) {
    $this->settings = $config_factory->get('wdb_cantaloupe_auth.settings');
    $this->time = $time;
    $this->privateKey = $private_key;
  }

  /**
   * {@inheritdoc}
   */
  public function issueToken(string $subsystem, string $image_identifier, ?AccountInterface $account = NULL): ?string {
    $subsystem = trim($subsystem);
    $image_identifier = trim($image_identifier);
    if ($subsystem === '' || $image_identifier === '') {
      return NULL;
    }

    $uid = (int) ($account ? $account->id() : 0);
    $cache_key = strtolower($subsystem) . '::' . $image_identifier . '::' . $uid;
    if (array_key_exists($cache_key, $this->requestCache)) {
      return $this->requestCache[$cache_key];
    }

    $payload = [
      's' => $subsystem,
      'i' => $image_identifier,
      'u' => $uid,
      'exp' => $this->time->getRequestTime() + $this->getTtl(),
      'ver' => 1,
      'nonce' => $this->generateNonce(),
    ];

    try {
      $json = Json::encode($payload);
      $body = $this->base64UrlEncode($json);
      $signature = $this->base64UrlEncode($this->sign($body));
      $token = $body . '.' . $signature;
      $this->requestCache[$cache_key] = $token;
      return $token;
    }
    catch (\Throwable $e) {
      $this->requestCache[$cache_key] = NULL;
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function appendTokenToUrl(string $url, ?string $token = NULL): string {
    if (empty($url) || empty($token)) {
      return $url;
    }

    $parts = @parse_url($url);
    if ($parts === FALSE) {
      return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $query);
    }
    $query[$this->getQueryParameterName()] = $token;
    $parts['query'] = UrlHelper::buildQuery($query);

    return $this->buildUrlFromParts($parts);
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryParameterName(): string {
    $param = trim((string) $this->settings->get('token_param'));
    return $param !== '' ? $param : 'wdb_token';
  }

  /**
   * {@inheritdoc}
   */
  public function validateToken(string $token): ?array {
    $token = trim($token);
    if ($token === '') {
      return NULL;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 2) {
      return NULL;
    }

    try {
      [$body_encoded, $signature_encoded] = $parts;
      $body_json = $this->base64UrlDecode($body_encoded);
      $signature = $this->base64UrlDecode($signature_encoded);
    }
    catch (\Throwable $e) {
      return NULL;
    }

    $expected_signature = $this->sign($parts[0]);
    if (!hash_equals($expected_signature, $signature)) {
      return NULL;
    }

    try {
      $payload = Json::decode($body_json);
    }
    catch (\Throwable $e) {
      return NULL;
    }

    if (!is_array($payload)) {
      return NULL;
    }

    $expires = isset($payload['exp']) ? (int) $payload['exp'] : 0;
    if ($expires <= $this->time->getRequestTime()) {
      return NULL;
    }

    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function getTtl(): int {
    $ttl = (int) $this->settings->get('token_ttl');
    return $ttl > 0 ? $ttl : 600;
  }

  /**
   * Builds a RFC 3986 compliant URL string from parse_url() parts.
   */
  protected function buildUrlFromParts(array $parts): string {
    $scheme = isset($parts['scheme']) ? $parts['scheme'] . ':' : '';

    $authority = '';
    if (isset($parts['host'])) {
      $authority = $parts['host'];
      if (isset($parts['user'])) {
        $auth = $parts['user'];
        if (isset($parts['pass'])) {
          $auth .= ':' . $parts['pass'];
        }
        $authority = $auth . '@' . $authority;
      }
      if (isset($parts['port'])) {
        $authority .= ':' . $parts['port'];
      }
      $authority = '//' . $authority;
    }

    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $authority . $path . $query . $fragment;
  }

  /**
   * Base64 URL-safe encoding helper.
   */
  protected function base64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  /**
   * Base64 URL-safe decoding helper.
   */
  protected function base64UrlDecode(string $value): string {
    $value = strtr($value, '-_', '+/');
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
      $value .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode($value, TRUE);
    if ($decoded === FALSE) {
      throw new \RuntimeException('Invalid base64url input.');
    }
    return $decoded;
  }

  /**
   * Creates a nonce for the payload to avoid replay collisions.
   */
  protected function generateNonce(): string {
    try {
      return bin2hex(random_bytes(8));
    }
    catch (\Throwable $e) {
      return bin2hex((string) microtime(TRUE));
    }
  }

  /**
   * Signs the encoded payload body with the derived site secret.
   */
  protected function sign(string $payload): string {
    return hash_hmac('sha256', $payload, $this->getSigningSecret(), TRUE);
  }

  /**
   * Returns the derived signing secret for the current site.
   */
  protected function getSigningSecret(): string {
    if ($this->signingSecret === NULL) {
      $site_key = $this->privateKey->get();
      $salt = Settings::getHashSalt();
      $this->signingSecret = hash('sha256', $site_key . ':' . $salt, TRUE);
    }
    return $this->signingSecret;
  }

}
