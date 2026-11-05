<?php

namespace Drupal\Tests\wdb_core\Kernel;

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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Exercises resource authorization with actual stored entities and groups.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class WdbSecurityAccessTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'text', 'taxonomy', 'file', 'options', 'entity', 'flexible_permissions', 'group', 'wdb_core'];

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
    $this->member = User::create(['name' => 'member', 'roles' => ['editor']]);
    $this->member->save();
    $this->outsider = User::create(['name' => 'outsider', 'roles' => ['editor']]);
    $this->outsider->save();
    GroupType::create(['id' => 'security', 'label' => 'Security'])->save();
    $group = Group::create(['type' => 'security', 'label' => 'Restricted readers']);
    $group->save();
    $group->addMember($this->member);
    $this->config('wdb_core.subsystem.' . $public->id())->set('allowAnonymous', TRUE)->save();
    $this->config('wdb_core.subsystem.' . $this->privateTerm->id())->set('allowAnonymous', FALSE)->set('group_uuid', $group->uuid())->save();

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
