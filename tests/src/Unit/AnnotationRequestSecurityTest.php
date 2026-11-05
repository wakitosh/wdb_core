<?php

namespace Drupal\Tests\wdb_core\Unit;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\MetadataBag;
use Drupal\Core\Session\SessionConfiguration;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\wdb_core\Controller\AnnotationApiController;
use Drupal\wdb_core\Lib\HullJsPhp\HullPHP;
use Drupal\wdb_core\Service\WdbDataService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies session-token enforcement and rejects unsafe mutation payloads.
 */
class AnnotationRequestSecurityTest extends UnitTestCase {

  public function testMutationRoutesRejectMissingAndInvalidCsrfTokens(): void {
    $settings = new Settings(['hash_salt' => 'test-salt']);
    $key = $this->createMock(PrivateKey::class);
    $key->method('get')->willReturn('test-private-key');
    $generator = new CsrfTokenGenerator($key, new MetadataBag($settings));
    $configuration = new SessionConfiguration([]);
    $checker = new CsrfRequestHeaderAccessCheck($configuration, $generator);
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $token_key = defined(CsrfRequestHeaderAccessCheck::class . '::TOKEN_KEY') ? CsrfRequestHeaderAccessCheck::TOKEN_KEY : 'rest';
    $valid_token = $generator->get($token_key);
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/wdb_core.routing.yml');
    foreach (['wdb_core.annotation_create', 'wdb_core.annotation_update', 'wdb_core.annotation_delete'] as $name) {
      $definition = $routes[$name];
      $route = new Route($definition['path'], $definition['defaults'], $definition['requirements'], [], '', [], $definition['methods']);
      $this->assertTrue((bool) $checker->applies($route));
      $request = Request::create('https://example.org' . $definition['path'], $definition['methods'][0]);
      $request->cookies->set($configuration->getOptions($request)['name'], 'test-session');
      $this->assertFalse($checker->access($request, $account)->isAllowed());
      $request->headers->set('X-CSRF-Token', 'invalid');
      $this->assertFalse($checker->access($request, $account)->isAllowed());
      $request->headers->set('X-CSRF-Token', $valid_token);
      $this->assertTrue($checker->access($request, $account)->isAllowed());
    }
  }

  public static function unsafePayloads(): array {
    $base = ['bodies' => [['value' => 'label']], 'target' => ['source' => 'https://example.org/canvas', 'selector' => ['geometry' => ['points' => [[0, 0], [1, 0], [1, 1]]]]]];
    $invalid_points = $base;
    $invalid_points['target']['selector']['geometry']['points'][0][0] = '<svg onload=alert(1)>';
    return [
      ['createAnnotation', 'text/plain', json_encode($base), 415],
      ['updateAnnotation', 'text/plain', '{}', 415],
      ['createAnnotation', 'application/json', '{broken', 400],
      ['updateAnnotation', 'application/json', '{broken', 400],
      ['createAnnotation', 'application/json', 'null', 400],
      ['updateAnnotation', 'application/json', '123', 400],
      ['createAnnotation', 'application/json', json_encode($invalid_points), 400],
    ];
  }

  #[DataProvider('unsafePayloads')]
  public function testInvalidRequestsDoNotTouchStorage(string $method, string $type, string $body, int $status): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $controller = new AnnotationApiController($manager, $this->createMock(UrlGeneratorInterface::class), new HullPHP(), $this->createMock(WdbDataService::class), new RequestStack());
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => $type], $body);
    $this->assertSame($status, $controller->$method($request)->getStatusCode());
  }

}
