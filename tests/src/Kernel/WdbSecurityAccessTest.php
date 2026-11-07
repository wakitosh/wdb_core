<?php

namespace Drupal\Tests\wdb_core\Kernel;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Entity\WdbLabel;
use Drupal\wdb_core\Entity\WdbSource;
use Drupal\wdb_core\Entity\WdbWordUnit;
use Drupal\wdb_core\Entity\WdbWord;
use Drupal\wdb_core\Entity\WdbWordMeaning;
use Drupal\wdb_core\Entity\WdbSign;
use Drupal\wdb_core\Entity\WdbSignFunction;
use Drupal\wdb_core\Entity\WdbSignInterpretation;
use Drupal\wdb_core\Routing\WdbRouteSubscriber;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Exercises resource authorization with actual stored entities and groups.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class WdbSecurityAccessTest extends KernelTestBase {

  private const PRIVATE_IMAGE = 'wdb/restricted/private-source/1.ptif';

  protected static $modules = ['system', 'user', 'field', 'text', 'taxonomy', 'file', 'options', 'entity', 'flexible_permissions', 'group', 'wdb_core', 'wdb_cantaloupe_auth'];

  private User $member;
  private User $outsider;
  private WdbSource $privateSource;
  private WdbAnnotationPage $privatePage;
  private WdbLabel $privateLabel;
  private WdbAnnotationPage $publicPage;
  private Term $privateTerm;

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'taxonomy_term', 'wdb_source', 'wdb_annotation_page', 'wdb_label', 'wdb_sign', 'wdb_sign_function', 'wdb_sign_interpretation', 'wdb_word', 'wdb_word_meaning', 'wdb_word_unit', 'wdb_word_map', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['group']);
    Vocabulary::create(['vid' => 'subsystem', 'name' => 'Subsystem'])->save();
    $public = Term::create(['vid' => 'subsystem', 'name' => 'public']);
    $public->save();
    $this->privateTerm = Term::create(['vid' => 'subsystem', 'name' => 'restricted']);
    $this->privateTerm->save();

    // Reserve UID 1 so the tested accounts do not have superuser privileges.
    User::create(['name' => 'owner'])->save();
    $role = Role::create(['id' => 'editor', 'label' => 'Editor']);
    foreach (['access content', 'view wdb gallery pages', 'access wdb annotation edit page', 'access wdb annotation api create', 'access wdb annotation api update', 'access wdb annotation api delete'] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $this->member = User::create(['name' => 'member', 'roles' => ['editor'], 'status' => 1]);
    $this->member->save();
    $this->outsider = User::create(['name' => 'outsider', 'roles' => ['editor'], 'status' => 1]);
    $this->outsider->save();
    GroupType::create(['id' => 'security', 'label' => 'Security'])->save();
    $group = Group::create(['type' => 'security', 'label' => 'Restricted readers']);
    $group->save();
    $group->addMember($this->member);
    $this->config('wdb_core.subsystem.' . $public->id())->set('allowAnonymous', TRUE)->set('iiif_server_hostname', 'iiif.example.org')->save();
    $this->config('wdb_core.subsystem.' . $this->privateTerm->id())->set('allowAnonymous', FALSE)->set('group_uuid', $group->uuid())->set('iiif_server_hostname', 'iiif.example.org')->save();

    foreach ([[$public, 'public-source'], [$this->privateTerm, 'private-source']] as [$term, $identifier]) {
      $source = WdbSource::create(['source_identifier' => $identifier, 'displayname' => $identifier, 'subsystem_tags' => [$term->id()]]);
      $source->save();
      $page = WdbAnnotationPage::create(['source_ref' => $source->id(), 'page_number' => 1, 'image_identifier' => 'wdb/' . $term->getName() . '/' . $identifier . '/1.ptif']);
      $page->save();
      $word = WdbWord::create(['basic_form' => $identifier . '-dictionary']);
      $word->save();
      $meaning = WdbWordMeaning::create(['word_ref' => $word->id(), 'meaning_identifier' => 1]);
      $meaning->save();
      WdbWordUnit::create(['source_ref' => $source->id(), 'word_meaning_ref' => $meaning->id(), 'annotation_page_refs' => [$page->id()], 'original_word_unit_identifier' => $identifier . '-word', 'realized_form' => $identifier . '-text', 'word_sequence' => 1])->save();
      $sign = WdbSign::create(['sign_code' => $identifier . '-sign']);
      $sign->save();
      $function = WdbSignFunction::create(['sign_ref' => $sign->id(), 'function_name' => 'reading']);
      $function->save();
      WdbSignInterpretation::create(['annotation_page_ref' => $page->id(), 'sign_function_ref' => $function->id()])->save();
      if ($identifier === 'private-source') {
        $this->privateSource = $source;
        $this->privatePage = $page;
      }
      else {
        $this->publicPage = $page;
      }
    }
    $this->privateLabel = WdbLabel::create([
      'annotation_page_ref' => $this->privatePage->id(),
      'label_name' => 'Private label',
      'annotation_uri' => 'https://example.org/annotation/private',
      'polygon_points' => ['0,0', '10,0', '10,10', '0,10'],
    ]);
    $this->privateLabel->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  private function assertDenied(callable $callback): void {
    try {
      $callback();
      $this->fail('Access to a restricted resource must be denied.');
    }
    catch (AccessDeniedHttpException $e) {
      $this->assertSame(403, $e->getStatusCode());
    }
  }

  public function testReadEndpointsRejectAnonymousAndNonMembers(): void {
    $api = $this->container->get('wdb_core.annotation_api_controller');
    $uri = 'https://example.org' . $this->privatePage->get('canvas_identifier_fragment')->value;
    foreach ([new AnonymousUserSession(), $this->outsider] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $this->assertDenied(fn() => $api->searchAnnotations(Request::create('/?uri=' . urlencode($uri))));
      $this->assertDenied(fn() => $api->getWordLevelAnnotations($this->privatePage));
      $this->assertDenied(fn() => $api->getIndividualAnnotation($this->privateLabel));
      $this->assertDenied(fn() => $this->container->get('wdb_core.full_text_controller')->getFullTextContent('restricted', 'private-source', 1));
      $this->assertDenied(fn() => $this->container->get('wdb_core.export_controller')->downloadTei('restricted', 'private-source', 1));
      $this->assertDenied(fn() => $this->container->get('wdb_core.export_controller')->downloadRdf('restricted', 'private-source', 1));
      $this->assertDenied(fn() => $this->container->get('wdb_core.export_controller')->downloadText('restricted', 'private-source', 1));
      $this->assertDenied(fn() => $this->container->get('wdb_core.dialog_content_controller')->getDetailsByUri(Request::create('/?uri=' . urlencode($this->privateLabel->get('annotation_uri')->value)), 'restricted'));
      $this->assertDenied(fn() => $this->container->get('wdb_core.source_view_controller')->view('private-source'));
      $this->assertDenied(fn() => $this->container->get('wdb_core.gallery_controller')->viewPage('restricted', 'private-source', 1));
      $this->assertDenied(fn() => $this->container->get('wdb_core.gallery_controller')->buildSourcePage('restricted', 'private-source'));
      $this->assertDenied(fn() => $this->container->get('wdb_core.annotation_edit_controller')->buildPage(Request::create('/'), 'restricted', 'private-source', 1));
    }
    $this->container->get('current_user')->setAccount($this->member);
    $response = $api->searchAnnotations(Request::create('/?uri=' . urlencode($uri)));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('Private label', $response->getContent());
  }

  public function testPublicReadsAndSubsystemSpoofing(): void {
    $text = $this->container->get('wdb_core.full_text_controller');
    $this->assertSame(200, $text->getFullTextContent('public', 'public-source', 1)->getStatusCode());
    $this->container->get('current_user')->setAccount($this->member);
    $this->assertDenied(fn() => $text->getFullTextContent('public', 'private-source', 1));
    $this->assertDenied(fn() => $this->container->get('wdb_core.export_controller')->downloadText('public', 'private-source', 1));
  }

  public function testManifestRequiresResourceAccess(): void {
    // Answer the controller's server-side info.json lookups without a network.
    $info = '{"width":100,"height":200}';
    $this->container->set('http_client_factory', new ClientFactory(HandlerStack::create(new MockHandler([
      new Response(200, [], $info),
      new Response(200, [], $info),
    ]))));
    $this->container->get('router.builder')->rebuild();
    $manifests = $this->container->get('wdb_core.iiif_v3_manifest_controller');

    foreach ([new AnonymousUserSession(), $this->outsider] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $this->assertDenied(fn() => $manifests->buildManifest('restricted', 'private-source'));
    }

    $this->container->get('current_user')->setAccount($this->member);
    $this->assertDenied(fn() => $manifests->buildManifest('public', 'private-source'));
    $response = $manifests->buildManifest('restricted', 'private-source');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('private-source', $response->getContent());

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->assertSame(200, $manifests->buildManifest('public', 'public-source')->getStatusCode());
  }

  public function testManifestFailuresDoNotLogTokens(): void {
    $this->container->set('http_client_factory', new ClientFactory(HandlerStack::create(new MockHandler([new Response(403)]))));
    $this->container->get('router.builder')->rebuild();
    $logger = new class() extends AbstractLogger {

      public array $lines = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = strtr((string) $message, array_filter($context, 'is_scalar'));
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
    $this->container->get('current_user')->setAccount($this->member);

    $this->container->get('wdb_core.iiif_v3_manifest_controller')->buildManifest('restricted', 'private-source');

    $failures = array_filter($logger->lines, fn($line) => str_contains($line, 'Failed to fetch info.json'));
    $this->assertCount(1, $failures);
    $this->assertStringContainsString('info.json', reset($failures));
    $this->assertStringNotContainsString('wdb_token', reset($failures));
  }

  public function testCollectionRouteFollowsSubsystemPolicy(): void {
    $access = $this->container->get('wdb_core.resource_access');
    foreach ([new AnonymousUserSession(), $this->outsider] as $account) {
      $this->assertFalse($access->subsystemRouteAccess('restricted', $account)->isAllowed());
      $this->assertTrue($access->subsystemRouteAccess('public', $account)->isAllowed());
    }
    $this->assertTrue($access->subsystemRouteAccess('restricted', $this->member)->isAllowed());
    $this->assertFalse($access->subsystemRouteAccess('no-such-subsystem', $this->member)->isAllowed());

    // The page is a site-built view, so its path identifies it, not its ID.
    $collection = new RouteCollection();
    $collection->add('view.site_built.page_1', new Route('/wdb/{arg_0}/collection', [], ['_access' => 'TRUE', '_format' => 'html']));
    $collection->add('other', new Route('/wdb/{arg_0}/other', [], ['_access' => 'TRUE']));
    (new WdbRouteSubscriber())->onAlterRoutes(new RouteBuildEvent($collection));

    $requirements = $collection->get('view.site_built.page_1')->getRequirements();
    $this->assertArrayNotHasKey('_access', $requirements);
    $this->assertSame('wdb_core.resource_access::subsystemRouteAccess', $requirements['_custom_access']);
    $this->assertSame('html', $requirements['_format']);
    $this->assertSame('TRUE', $collection->get('other')->getRequirement('_access'));
  }

  public function testGeometryApisStayPublicAndBoundTheirInput(): void {
    $this->container->get('router.builder')->rebuild();
    $routes = $this->container->get('router.route_provider');
    $this->assertSame('TRUE', $routes->getRouteByName('wdb_core.hull_api')->getRequirement('_access'));
    $this->assertSame('TRUE', $routes->getRouteByName('wdb_core.bbox_api')->getRequirement('_access'));

    $export = $this->container->get('wdb_core.export_controller');
    $call = fn(string $method, array $points) => $export->$method(Request::create('/', 'GET', ['points' => json_encode($points), 'concavity' => 0]));
    $at_limit = array_map(fn($i) => $i . ',' . ($i * $i % 997), range(1, 500));

    foreach (['calculateHullApi', 'calculateBboxApi'] as $method) {
      $this->assertSame(200, $call($method, ['10,20', '30,40', '25,35'])->getStatusCode());
      $this->assertSame(200, $call($method, $at_limit)->getStatusCode());
      $this->assertSame(400, $call($method, array_merge($at_limit, ['501,1']))->getStatusCode());
      $this->assertSame(400, $call($method, [['1', '2'], 7, NULL])->getStatusCode());
      $this->assertSame(400, $call($method, ['1e999,1', '1,1e999', '1000001,1'])->getStatusCode());
    }

    // Unusable entries are skipped rather than read as coordinates of zero.
    $bbox = json_decode($call('calculateBboxApi', ['10,20', '30,40', 'x,y', '1e999,1', [1, 2]])->getContent(), TRUE);
    $this->assertSame(['x' => 10, 'y' => 20, 'w' => 20, 'h' => 20], array_map('intval', $bbox));
    $hull = json_decode($call('calculateHullApi', ['x,y', '10,20', '30,40', '25,35'])->getContent(), TRUE);
    $this->assertNotContains([0, 0], $hull);
  }

  public function testImageAuthorizationTrustsOnlyTheSessionUidColumn(): void {
    $this->createSessionsTable();
    $this->addSession('member-session', (int) $this->member->id());
    $this->addSession('outsider-session', (int) $this->outsider->id());
    // The payload is opaque data; it must never identify the user.
    $this->addSession('anonymous-session', 0, 'uid|i:' . $this->member->id() . ';');

    $this->assertTrue($this->authorizeImage(['cookies' => ['SSESS1234=member-session']])['authorized']);
    $this->assertFalse($this->authorizeImage(['cookies' => ['SSESS1234=outsider-session']])['authorized']);
    $this->assertFalse($this->authorizeImage(['cookies' => ['SSESS1234=anonymous-session']])['authorized']);
    $this->assertFalse($this->authorizeImage(['cookies' => ['SSESS1234=unknown-session']])['authorized']);
    // The stored value is a hash of the session ID, not a credential.
    $this->assertFalse($this->authorizeImage(['cookies' => ['SSESS1234=' . Crypt::hashBase64('member-session')]])['authorized']);
  }

  public function testImageAuthorizationRejectsBlockedUsers(): void {
    $this->createSessionsTable();
    $this->addSession('member-session', (int) $this->member->id());
    $token = $this->container->get('wdb_cantaloupe_auth.token_manager')->issueToken('restricted', self::PRIVATE_IMAGE, $this->member);

    $this->assertTrue($this->authorizeImage(['cookies' => ['SSESS1234=member-session']])['authorized']);
    $this->assertTrue($this->authorizeImage(['token' => $token])['authorized']);

    $this->member->block()->save();
    $this->assertFalse($this->authorizeImage(['cookies' => ['SSESS1234=member-session']])['authorized']);
    $this->assertFalse($this->authorizeImage(['token' => $token])['authorized']);
  }

  /**
   * Posts a delegate-style request for the private image.
   */
  private function authorizeImage(array $payload): array {
    $body = json_encode($payload + ['identifier' => self::PRIVATE_IMAGE]);
    $request = Request::create('/wdb/api/cantaloupe_auth', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    $response = $this->container->get('wdb_cantaloupe_auth.auth_controller')->authorizeRequest($request);
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Creates the table core otherwise creates on the first session write.
   */
  private function createSessionsTable(): void {
    $this->container->get('database')->schema()->createTable('sessions', [
      'fields' => [
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'sid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE],
        'hostname' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'timestamp' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'session' => ['type' => 'blob', 'size' => 'big'],
      ],
      'primary key' => ['sid'],
    ]);
  }

  /**
   * Stores a session the way core does, keyed by the hashed session ID.
   */
  private function addSession(string $sid, int $uid, ?string $payload = NULL): void {
    $this->container->get('database')->insert('sessions')->fields([
      'uid' => $uid,
      'sid' => Crypt::hashBase64($sid),
      'session' => $payload ?? '_sf2_attributes|' . serialize(['uid' => (string) $uid]),
    ])->execute();
  }

  public function testMutationRejectsNonMembersAndPermitsMember(): void {
    $api = $this->container->get('wdb_core.annotation_api_controller');
    $uri = 'https://example.org' . $this->privatePage->get('canvas_identifier_fragment')->value;
    $payload = ['id' => 'https://example.org/annotation/private', 'bodies' => [['value' => 'Updated label']], 'target' => ['source' => $uri, 'selector' => ['geometry' => ['points' => [[1, 1], [20, 1], [20, 20]]]]]];
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    $this->container->get('current_user')->setAccount($this->outsider);
    $this->assertDenied(fn() => $api->createAnnotation($request));
    $this->assertDenied(fn() => $api->updateAnnotation($request));
    $this->assertDenied(fn() => $api->deleteAnnotation(Request::create('/?uri=' . urlencode($payload['id']), 'DELETE')));
    $this->assertSame('Private label', WdbLabel::load($this->privateLabel->id())->label());
    $this->container->get('current_user')->setAccount($this->member);
    $this->assertSame(200, $api->updateAnnotation($request)->getStatusCode());
    $this->assertSame('Updated label', WdbLabel::load($this->privateLabel->id())->label());
    $this->assertSame(204, $api->deleteAnnotation(Request::create('/?uri=' . urlencode($payload['id']), 'DELETE'))->getStatusCode());
    $this->assertNull(WdbLabel::load($this->privateLabel->id()));
  }

  public function testPublicVisibilityDoesNotGrantWritePermission(): void {
    $access = $this->container->get('wdb_core.resource_access');
    $this->assertDenied(fn() => $access->assertOperationAccess($this->publicPage, 'access wdb annotation api create'));
    $this->container->get('current_user')->setAccount($this->member);
    $access->assertOperationAccess($this->publicPage, 'access wdb annotation api create');
    $this->assertTrue(TRUE);
  }

  public function testSearchCountsAndAutocompleteExcludeRestrictedData(): void {
    $search = $this->container->get('wdb_core.search_api_controller');
    $autocomplete = $this->container->get('wdb_core.autocomplete_controller');
    foreach ([new AnonymousUserSession(), $this->outsider, $this->member] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $response = json_decode($search->search(Request::create('/?limit=0'))->getContent(), TRUE);
      $this->assertSame($account === $this->member ? 2 : 1, (int) $response['total']);
      $this->assertSame(1, $response['limit']);
      foreach (['realized_form', 'basic_form', 'sign'] as $field) {
        $request = Request::create('/?q=private-source');
        $request->attributes->set('_route', 'wdb_core.autocomplete.' . $field);
        $matches = json_decode($autocomplete->handleAutocomplete($request)->getContent(), TRUE);
        $this->assertCount($account === $this->member ? 1 : 0, $matches);
      }
    }
  }

  public function testMissingGroupFailsClosedAndMembershipIsNotCached(): void {
    $this->container->get('current_user')->setAccount($this->outsider);
    $this->config('wdb_core.subsystem.' . $this->privateTerm->id())->set('group_uuid', '00000000-0000-4000-8000-000000000000')->save();
    $this->assertFalse($this->container->get('wdb_core.resource_access')->checkAccess($this->privateSource)->isAllowed());
    $this->container->get('current_user')->setAccount($this->member);
    $result = $this->container->get('wdb_core.subsystem_access_policy')->checkAccess('restricted', $this->member);
    $this->assertFalse($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
    $this->assertContains('user', $result->getCacheContexts());
  }

  public function testBuiltRoutesEnforceSessionCsrf(): void {
    $this->container->get('router.builder')->rebuild();
    $configuration = $this->container->get('session_configuration');
    $token_key = defined(\Drupal\Core\Access\CsrfRequestHeaderAccessCheck::class . '::TOKEN_KEY') ? \Drupal\Core\Access\CsrfRequestHeaderAccessCheck::TOKEN_KEY : 'rest';
    $token = $this->container->get('csrf_token')->get($token_key);
    foreach (['wdb_core.annotation_create', 'wdb_core.annotation_update', 'wdb_core.annotation_delete'] as $name) {
      $route = $this->container->get('router.route_provider')->getRouteByName($name);
      $request = Request::create('https://example.org' . $route->getPath(), $route->getMethods()[0]);
      $request->attributes->set('_route', $name);
      $request->attributes->set('_route_object', $route);
      $request->cookies->set($configuration->getOptions($request)['name'], 'test-session');
      $manager = $this->container->get('access_manager');
      $this->assertFalse($manager->checkRequest($request, $this->member, TRUE)->isAllowed());
      $request->headers->set('X-CSRF-Token', 'invalid');
      $this->assertFalse($manager->checkRequest($request, $this->member, TRUE)->isAllowed());
      $request->headers->set('X-CSRF-Token', $token);
      $this->assertTrue($manager->checkRequest($request, $this->member, TRUE)->isAllowed());
    }
  }

  public function testMemberCanCreateAnnotations(): void {
    $this->container->get('router.builder')->rebuild();
    $this->container->get('current_user')->setAccount($this->member);
    $payload = [
      'bodies' => [['value' => 'Created label']],
      'target' => [
        'source' => 'https://example.org' . $this->privatePage->get('canvas_identifier_fragment')->value,
        'selector' => ['geometry' => ['points' => [[1, 1], [20, 1], [20, 20]]]],
      ],
    ];
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    $response = $this->container->get('wdb_core.annotation_api_controller')->createAnnotation($request);
    $this->assertSame(201, $response->getStatusCode());
    $labels = $this->container->get('entity_type.manager')->getStorage('wdb_label')->loadByProperties(['label_name' => 'Created label']);
    $this->assertCount(1, $labels);
    $this->assertSame((int) $this->privatePage->id(), (int) reset($labels)->get('annotation_page_ref')->target_id);
  }

  public function testNumericCanonicalRouteUsesResourcePolicy(): void {
    $this->container->get('router.builder')->rebuild();
    $manager = $this->container->get('access_manager');
    $parameters = ['wdb_source' => $this->privateSource->id()];
    $this->assertFalse($manager->checkNamedRoute('entity.wdb_source.canonical', $parameters, $this->outsider, TRUE)->isAllowed());
    $this->assertTrue($manager->checkNamedRoute('entity.wdb_source.canonical', $parameters, $this->member, TRUE)->isAllowed());
  }

  public function testProtectedPageResponsesAreNotShared(): void {
    $this->container->get('router.builder')->rebuild();
    $this->container->get('current_user')->setAccount($this->member);
    $builds = [
      $this->container->get('wdb_core.source_view_controller')->view('private-source'),
      $this->container->get('wdb_core.gallery_controller')->buildSourcePage('restricted', 'private-source'),
      $this->container->get('wdb_core.gallery_controller')->viewPage('restricted', 'private-source', 1),
      $this->container->get('wdb_core.annotation_edit_controller')->buildPage(Request::create('/'), 'restricted', 'private-source', 1),
    ];
    foreach ($builds as $build) {
      $this->assertSame(0, $build['#cache']['max-age']);
      $this->assertContains('user', $build['#cache']['contexts']);
    }
  }

}
