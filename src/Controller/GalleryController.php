<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\wdb_core\Entity\WdbSource;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Service\WdbDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for displaying the image gallery with an OpenSeadragon viewer.
 */
class GalleryController extends ControllerBase implements ContainerInjectionInterface {
  use StringTranslationTrait;


  /**
   * The URL generator service.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected UrlGeneratorInterface $urlGenerator;

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
   * Constructs a new GalleryController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL generator service.
   * @param \Drupal\wdb_core\Service\WdbDataService $wdbDataService
   *   The WDB data service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    UrlGeneratorInterface $url_generator,
    WdbDataService $wdbDataService,
    RequestStack $request_stack,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->urlGenerator = $url_generator;
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
      $container->get('url_generator'),
      $container->get('wdb_core.data_service'),
      $container->get('request_stack'),
    );
  }

  /**
   * Generates the title for the gallery page.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The page title.
   */
  public function getPageTitle(string $subsysname, string $source, int $page) {
    $wdb_source_entity = $this->loadWdbSource($source, $subsysname);
    if ($wdb_source_entity) {
      return $this->t('@source_label - Page @page', [
        '@source_label' => $wdb_source_entity->label(),
        '@page' => $page,
      ]);
    }
    return $this->t('Document Viewer');
  }

  /**
   * Displays the OpenSeadragon viewer for a specific page.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   * @param int $page
   *   The page number.
   *
   * @return array
   *   A render array for the gallery page.
   */
  public function viewPage(string $subsysname, string $source, int $page = 1): array {
    // 1. Load the required entities.
    /** @var \Drupal\wdb_core\Entity\WdbSource $wdb_source_entity */
    $wdb_source_entity = $this->loadWdbSource($source, $subsysname);
    if (!$wdb_source_entity) {
      throw new NotFoundHttpException();
    }
    /** @var \Drupal\wdb_core\Entity\WdbAnnotationPage $wdb_annotation_page_entity */
    $wdb_annotation_page_entity = $this->loadWdbAnnotationPage($wdb_source_entity, $page);
    if (!$wdb_annotation_page_entity) {
      throw new NotFoundHttpException();
    }
    $label_count = $this->entityTypeManager()->getStorage('wdb_label')->getQuery()
      ->condition('annotation_page_ref', $wdb_annotation_page_entity->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $has_annotations = $label_count > 0;

    // 2. Generate the necessary URIs.
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

    // Image identifier already validated above when loading annotation page.
    $image_identifier = $wdb_annotation_page_entity->getImageIdentifier();

    $info_json_url = $iiif_base_url . '/' . rawurlencode($image_identifier) . '/info.json';
    $info_json_url = $this->wdbDataService->appendIiifTokenToUrl($info_json_url, $subsysname, $image_identifier);
    $iiif_auth_context = $this->wdbDataService->getIiifAuthContext($subsysname, $image_identifier);
    $page_navigation = $subsys_config->get('pageNavigation') ?? 'left-to-right';

    $manifest_base_uri = $this->getManifestUri($wdb_source_entity, $subsysname);
    $canvas_id_uri = $this->getCanvasUri($wdb_annotation_page_entity, $manifest_base_uri);
    $annotation_list_uri = '';
    try {
      $annotation_list_uri = $this->urlGenerator->generateFromRoute(
        'wdb_core.annotation_search', [], ['query' => ['uri' => $canvas_id_uri], 'absolute' => TRUE]
      );
    }
    catch (\Exception $e) {
      $this->getLogger('wdb_core')->error(
        'Failed to generate annotation list URI for canvas @uri: @message',
        [
          '@uri' => $canvas_id_uri,
          '@message' => $e->getMessage(),
        ]
      );
    }

    // 2. Get all page information for the thumbnail pager.
    $page_list = [];
    $all_pages = $this->getAllPagesForSource($wdb_source_entity);
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
          'page' => $page_entity->get('page_number')->value,
          'label' => $page_entity->label(),
          'url' => Url::fromRoute('wdb_core.gallery_page', [
            'subsysname' => $subsysname,
            'source' => $source,
            'page' => $page_entity->get('page_number')->value,
          ])->toString(),
          'thumbnailUrl' => $thumb_url,
        ];
      }
    }

    // 3. Pass initialization settings to JavaScript via drupalSettings.
    $module_path = $this->moduleHandler()->getModule('wdb_core')->getPath();
    $toolbar_urls = [
      'tei' => Url::fromRoute(
        'wdb_core.tei_download_page',
        [
          'subsysname' => $subsysname,
          'source' => $source,
          'page' => $page,
        ]
      )->toString(),
      'rdf' => Url::fromRoute(
        'wdb_core.rdf_download_page',
        [
          'subsysname' => $subsysname,
          'source' => $source,
          'page' => $page,
        ]
      )->toString(),
      'text' => Url::fromRoute(
        'wdb_core.text_download_page',
        [
          'subsysname' => $subsysname,
          'source' => $source,
          'page' => $page,
        ]
      )->toString(),
      'manifest_v3' => Url::fromRoute(
        'wdb_core.iiif_manifest_v3',
        [
          'subsysname' => $subsysname,
          'source' => $source,
        ]
      )->toString(),
    ];
    // Check permission for the edit page link.
    if ($this->currentUser()->hasPermission('access wdb annotation edit page')) {
      $toolbar_urls['edit'] = Url::fromRoute(
        'wdb_core.annotation_edit_page',
        [
          'subsysname' => $subsysname,
          'source' => $source,
          'page' => $page,
        ]
      )->toString();
    }
    $osd_settings = [
      'prefixUrl' => '/' . $module_path . '/assets/openseadragon/images/',
      'tileSources' => $info_json_url,
      'annotationListUrl' => $annotation_list_uri,
      'showNavigator' => TRUE,
      'context' => [
        'subsysname' => $subsysname,
        'source' => $source,
        'page' => $page,
      ],
      'isEditable' => FALSE,
      'pageList' => $page_list,
      'currentPage' => $page,
      'pageNavigation' => $page_navigation,
      'defaultZoomLevel' => 0,
      'toolbarUrls' => $toolbar_urls,
      'subsystemConfig' => [
          // Use default (20) only when config value is NULL.
        'hullConcavity' => (int) ($subsys_config->get('hullConcavity') ?? 20),
      ],
      'hasAnnotations' => $has_annotations,
    ];
    if (!empty($iiif_auth_context)) {
      $iiif_auth_context['refreshUrl'] = Url::fromRoute(
        'wdb_cantaloupe_auth.token_refresh',
        ['wdb_annotation_page' => $wdb_annotation_page_entity->id()],
        ['absolute' => TRUE]
      )->toString();
      $osd_settings['auth'] = $iiif_auth_context;
    }

    // 4. Build the render array.
    $build = [];
    $build['#title'] = $this->getPageTitle($subsysname, $source, $page);
    $build['#attached']['library'][] = 'wdb_core/wdb_viewer';
    $build['#attached']['library'][] = 'wdb_core/wdb_page_layout';
    $build['#attached']['library'][] = 'wdb_core/wdb_annotation_panel';
    $build['#attached']['drupalSettings']['wdb_core']['openseadragon'] = $osd_settings;

    $build['main_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'wdb-main-container'],
      'viewer' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['id' => 'openseadragon-viewer']],
      'resizer' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['id' => 'wdb-resizer']],
      'annotation_info' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'wdb-annotation-info-panel'],
        'toolbar' => [
          '#type' => 'container',
          '#attributes' => ['id' => 'wdb-panel-toolbar'],
        ],
        'details' => [
          '#type' => 'container',
          '#attributes' => ['id' => 'wdb-annotation-details-area'],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#attributes' => [
              'id' => 'wdb-annotation-panel-title',
            ],
            '#markup' => $this->t('Annotation Details'),
          ],
          'content' => [
            '#type' => 'container',
            '#attributes' => [
              'id' => 'wdb-annotation-panel-content',
            ],
            '#markup' => '<p>' . $this->t('No annotation available for this page.') . '</p>',
          ],
        ],

        'resizer_h' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['id' => 'wdb-resizer-horizontal']],
        'full_text' => [
          '#type' => 'container',
          '#attributes' => ['id' => 'wdb-full-text-area'],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#attributes' => [
              'id' => 'wdb-full-text-title',
            ],
            '#markup' => $this->t('Full Text'),
          ],
          'content' => [
            '#type' => 'container',
            '#attributes' => ['id' => 'wdb-full-text-content'],
            '#markup' => '<p>' . $this->t('Loading full text...') . '</p>',
          ],
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
   * Builds the source information page when no page number is specified.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   *
   * @return array
   *   A render array for the source information page.
   */
  public function buildSourcePage(string $subsysname, string $source): array {
    // Load the WdbSource entity using its identifier and subsystem.
    $source_storage = $this->entityTypeManager()->getStorage('wdb_source');
    $sources = $source_storage->loadByProperties(['source_identifier' => $source]);

    $wdb_source_entity = NULL;
    foreach ($sources as $source_entity) {
      /** @var \Drupal\wdb_core\Entity\WdbSource $source_entity */
      $field = $source_entity->get('subsystem_tags');
      if ($field && !$field->isEmpty()) {
        foreach ($field as $item) {
          $tag_entity = $item->entity;
          if ($tag_entity && strtolower($tag_entity->getName()) === strtolower($subsysname)) {
            $wdb_source_entity = $source_entity;
            break 2;
          }
        }
      }
    }

    if (!$wdb_source_entity) {
      // If the source is not found, throw a 404 error.
      throw new NotFoundHttpException();
    }
    // Use entity view builder for 'full' mode (respects display settings).
    $view_builder = $this->entityTypeManager()->getViewBuilder('wdb_source');
    $build = $view_builder->view($wdb_source_entity, 'full');

    return $build;
  }

  /**
   * Helper methods.
   */

  /**
   * Helper to load all page entities for a source.
   *
   * @param \Drupal\wdb_core\Entity\WdbSource $source_entity
   *   The source entity.
   *
   * @return \Drupal\wdb_core\Entity\WdbAnnotationPage[]
   *   An array of page entities.
   */
  private function getAllPagesForSource(WdbSource $source_entity): array {
    $storage = $this->entityTypeManager()->getStorage('wdb_annotation_page');
    $query = $storage->getQuery()
      ->condition('source_ref', $source_entity->id())
      ->sort('page_number', 'ASC')
      ->accessCheck(FALSE);
    $ids = $query->execute();
    return !empty($ids) ? $storage->loadMultiple($ids) : [];
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
    $source_storage = $this->entityTypeManager()->getStorage('wdb_source');
    $sources = $source_storage->loadByProperties(['source_identifier' => $source_identifier]);
    $wdb_source_entity = reset($sources);
    if ($wdb_source_entity instanceof WdbSource) {
      $field = $wdb_source_entity->get('subsystem_tags');
      if ($field && !$field->isEmpty()) {
        foreach ($field as $item) {
          $tag_entity = $item->entity;
          if ($tag_entity && strtolower($tag_entity->getName()) === strtolower($subsysname)) {
            return $wdb_source_entity;
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
    $annotation_code = $wdb_source_entity->get('source_identifier')->value . '_' . $page_number;
    $annotation_pages = $annotation_page_storage->loadByProperties([
      'annotation_code' => $annotation_code,
      'source_ref' => $wdb_source_entity->id(),
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
    if ($page_entity->hasField('canvas_identifier_fragment') && !$page_entity->get('canvas_identifier_fragment')->isEmpty()) {
      $fragment = $page_entity->get('canvas_identifier_fragment')->value;
      return $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost() . $fragment;
    }
    $page_identifier = $page_entity->get('page_number')->value;
    return rtrim($manifest_id_uri_base, '/') . '/canvas/' . $page_identifier;
  }

}
