<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\wdb_core\Entity\WdbSource;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Service\WdbDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for displaying the OpenSeadragon/Annotorious viewer for editing.
 *
 * This controller builds the page that provides the user interface for
 * creating, updating, and deleting annotations on a specific image canvas.
 */
class AnnotationEditController extends ControllerBase implements ContainerInjectionInterface {
  use StringTranslationTrait;

  /**
   * The WDB data service.
   *
   * @var \Drupal\wdb_core\Service\WdbDataService
   */
  protected WdbDataService $wdbDataService;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a new AnnotationEditController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\wdb_core\Service\WdbDataService $wdbDataService
   *   The data service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, WdbDataService $wdbDataService, RequestStack $request_stack) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->wdbDataService = $wdbDataService;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('wdb_core.data_service'),
      $container->get('request_stack'),
    );
  }

  /**
   * Generates the title for the annotation editor page.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return string
   *   The page title.
   */
  public function getPageTitle(string $subsysname, string $source, int $page): string {
    $wdb_source_entity = $this->loadWdbSource($source, $subsysname);
    if ($wdb_source_entity) {
      return $this->t('Edit Annotations: @source_label - Page @page', [
        '@source_label' => $wdb_source_entity->label(),
        '@page' => $page,
      ]);
    }
    return $this->t('Edit Annotations');
  }

  /**
   * Builds the annotation editor page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return array
   *   A render array for the annotation editor page.
   */
  public function buildPage(Request $request, string $subsysname, string $source, int $page): array {
    $wdb_source_entity = $this->loadWdbSource($source, $subsysname);
    if (!$wdb_source_entity) {
      throw new NotFoundHttpException();
    }

    $wdb_annotation_page_entity = $this->loadWdbAnnotationPage($wdb_source_entity, $page);
    if (!$wdb_annotation_page_entity) {
      throw new NotFoundHttpException();
    }

    $subsys_config = $this->wdbDataService->getSubsystemConfig($subsysname);
    if (!$subsys_config) {
      return [
        '#markup' => $this->t('Subsystem configuration not found for "@subsys".', ['@subsys' => $subsysname]),
      ];
    }

    $iiif_base_url = $this->wdbDataService->getIiifBaseUrlForSubsystem($subsysname);
    if (!$iiif_base_url) {
      $this->getLogger('wdb_core')->error('IIIF base URL is not configured for subsystem: @subsys', ['@subsys' => $subsysname]);
      throw new NotFoundHttpException('IIIF configuration is incomplete for this subsystem.');
    }

    $image_identifier = $wdb_annotation_page_entity->getImageIdentifier();
    if (empty($image_identifier)) {
      return [
        '#markup' => $this->t('The IIIF Image Identifier for this page has not been set, and no generation pattern is configured for the subsystem. Please configure it in the <a href=":url">module settings</a>.', [
          ':url' => Url::fromRoute('wdb_core.settings_form')->toString(),
        ]),
      ];
    }

    $info_json_url = $iiif_base_url . '/' . rawurlencode($image_identifier) . '/info.json';
    $info_json_url = $this->wdbDataService->appendIiifTokenToUrl($info_json_url, $subsysname, $image_identifier);
    $iiif_auth_context = $this->wdbDataService->getIiifAuthContext($subsysname, $image_identifier);
    $page_navigation = $subsys_config->get('pageNavigation') ?? 'left-to-right';

    $manifest_base_uri = $this->getManifestUri($wdb_source_entity, $subsysname);
    $canvas_id_uri = $this->getCanvasUri($wdb_annotation_page_entity, $manifest_base_uri);

    $annotation_list_uri = Url::fromRoute(
      'wdb_core.annotation_search',
      [],
      [
        'query' => ['uri' => $canvas_id_uri],
        'absolute' => TRUE,
      ]
    )->toString();

    // Get all page information for the thumbnail pager.
    $page_list = [];
    $all_pages = $this->wdbDataService->getAllPagesForSource($wdb_source_entity);
    if ($all_pages) {
      foreach ($all_pages as $page_entity) {
        $image_identifier_for_thumb = $page_entity->getImageIdentifier();
        $page_num = $page_entity->get('page_number')->value;

        if (empty($image_identifier_for_thumb) || !is_numeric($page_num)) {
          continue;
        }

        $thumb_url = $iiif_base_url . '/' . rawurlencode($image_identifier_for_thumb) . '/full/!150,150/0/default.jpg';
        $thumb_url = $this->wdbDataService->appendIiifTokenToUrl($thumb_url, $subsysname, $image_identifier_for_thumb);

        $page_list[] = [
          'page' => $page_num,
          'label' => $page_entity->label(),
          'url' => Url::fromRoute('wdb_core.annotation_edit_page', [
            'subsysname' => $subsysname,
            'source' => $source,
            'page' => $page_num,
          ])->toString(),
          'thumbnailUrl' => $thumb_url,
        ];
      }
    }

    $module_path = $this->moduleHandler()->getModule('wdb_core')->getPath();
    $toolbar_urls = [
    // View link always available for users with access.
      'view' => Url::fromRoute('wdb_core.gallery_page', [
        'subsysname' => $subsysname,
        'source' => $source,
        'page' => $page,
      ])->toString(),
      // Export functions.
      'tei' => Url::fromRoute('wdb_core.tei_download_page', [
        'subsysname' => $subsysname,
        'source' => $source,
        'page' => $page,
      ])->toString(),
      'rdf' => Url::fromRoute('wdb_core.rdf_download_page', [
        'subsysname' => $subsysname,
        'source' => $source,
        'page' => $page,
      ])->toString(),
      'text' => Url::fromRoute('wdb_core.text_download_page', [
        'subsysname' => $subsysname,
        'source' => $source,
        'page' => $page,
      ])->toString(),
      'manifest_v3' => Url::fromRoute('wdb_core.iiif_manifest_v3', [
        'subsysname' => $subsysname,
        'source' => $source,
      ])->toString(),
    ];
    $osd_settings = [
      'prefixUrl' => '/' . $module_path . '/assets/openseadragon/images/',
      'tileSources' => $info_json_url,
      'initialCanvasID' => $canvas_id_uri,
      'annotationListUrl' => $annotation_list_uri,
      'annotationEndpoint' => [
        'create' => Url::fromRoute('wdb_core.annotation_create', [], ['absolute' => TRUE])->toString(),
        'update' => Url::fromRoute('wdb_core.annotation_update', [], ['absolute' => TRUE])->toString(),
        'destroy' => Url::fromRoute('wdb_core.annotation_delete', [], ['absolute' => TRUE])->toString(),
      ],
      'context' => [
        'subsysname' => $subsysname,
        'source' => $source,
        'page' => $page,
      ],
      'isEditable' => TRUE,
      'showNavigator' => TRUE,
      'pageList' => $page_list,
      'currentPage' => $page,
      'pageNavigation' => $page_navigation,
      'defaultZoomLevel' => 1,
      'toolbarUrls' => $toolbar_urls,
    ];
    if (!empty($iiif_auth_context)) {
      $iiif_auth_context['refreshUrl'] = Url::fromRoute(
        'wdb_cantaloupe_auth.token_refresh',
        ['wdb_annotation_page' => $wdb_annotation_page_entity->id()],
        ['absolute' => TRUE]
      )->toString();
      $osd_settings['auth'] = $iiif_auth_context;
    }

    $build = [];
    $build['#title'] = $this->getPageTitle($subsysname, $source, $page);
    $build['#attached']['library'][] = 'wdb_core/wdb_editor';
    $build['#attached']['library'][] = 'wdb_core/wdb_page_layout';
    $build['#attached']['drupalSettings']['wdb_core']['openseadragon'] = $osd_settings;

    $build['main_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'wdb-main-container',
        'class' => ['wdb-is-editing'],
      ],
      'viewer' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['id' => 'openseadragon-viewer'],
      ],
      'resizer' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['id' => 'wdb-resizer'],
      ],
      'annotation_info' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['id' => 'wdb-annotation-info-panel'],
        'toolbar' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['id' => 'wdb-panel-toolbar'],
        ],
      ],
    ];
    $build['pager_modal_container'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => 'wdb-thumbnail-pager-container',
        'class' => ['wdb-pager-modal'],
      ],
      '#weight' => 100,
    ];

    return $build;
  }

  /**
   * Helper to load WdbSource entity and verify subsystem.
   *
   * @param string $source_identifier
   *   The source identifier.
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return \Drupal\wdb_core\Entity\WdbSource|null
   *   The loaded entity or NULL.
   */
  private function loadWdbSource(string $source_identifier, string $subsysname): ?WdbSource {
    $storage = $this->entityTypeManager()->getStorage('wdb_source');
    $entities = $storage->loadByProperties(['source_identifier' => $source_identifier]);
    $candidate = reset($entities);
    if ($candidate instanceof WdbSource && !$candidate->get('subsystem_tags')->isEmpty()) {
      $target_ids = array_map(static fn($item) => $item['target_id'], $candidate->get('subsystem_tags')->getValue());
      if (!empty($target_ids)) {
        $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($target_ids);
        foreach ($terms as $term) {
          if (strtolower($term->label()) === strtolower($subsysname)) {
            return $candidate;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Helper to load a WdbAnnotationPage entity.
   *
   * @param \Drupal\wdb_core\Entity\WdbSource $wdb_source_entity
   *   The parent source entity.
   * @param int $page_number
   *   The page number.
   *
   * @return \Drupal\wdb_core\Entity\WdbAnnotationPage|null
   *   The loaded entity or NULL.
   */
  private function loadWdbAnnotationPage(WdbSource $wdb_source_entity, int $page_number): ?WdbAnnotationPage {
    $annotation_page_storage = $this->entityTypeManager()->getStorage('wdb_annotation_page');
    $annotation_pages = $annotation_page_storage->loadByProperties([
      'source_ref' => $wdb_source_entity->id(),
      'page_number' => $page_number,
    ]);
    return reset($annotation_pages) ?: NULL;
  }

  /**
   * Helper to construct the manifest URI.
   *
   * @param \Drupal\wdb_core\Entity\WdbSource $wdb_source_entity
   *   The source entity.
   * @param string $subsysname
   *   The machine name of the subsystem.
   *
   * @return string
   *   The absolute manifest URI.
   */
  private function getManifestUri(WdbSource $wdb_source_entity, string $subsysname): string {
    $request = $this->requestStack->getCurrentRequest();
    $source_identifier = $wdb_source_entity->get('source_identifier')->value;
    return $request->getSchemeAndHttpHost() . '/wdb/' . $subsysname . '/gallery/' . $source_identifier . '/manifest';
  }

  /**
   * Helper to construct the canvas URI.
   *
   * @param \Drupal\wdb_core\Entity\WdbAnnotationPage $page_entity
   *   The page entity.
   * @param string $manifest_id_uri_base
   *   The base URI of the manifest.
   *
   * @return string
   *   The absolute canvas URI.
   */
  private function getCanvasUri(WdbAnnotationPage $page_entity, string $manifest_id_uri_base): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($page_entity->hasField('canvas_identifier_fragment') && !$page_entity->get('canvas_identifier_fragment')->isEmpty()) {
      $fragment = $page_entity->get('canvas_identifier_fragment')->value;
      return $request->getSchemeAndHttpHost() . $fragment;
    }
    $page_identifier = $page_entity->get('page_number')->value;
    return rtrim($manifest_id_uri_base, '/') . '/canvas/' . $page_identifier;
  }

}
