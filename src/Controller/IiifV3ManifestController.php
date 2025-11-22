<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\wdb_core\Access\SubsystemAccessPolicyInterface;
use Drupal\wdb_core\Service\WdbDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller to generate a IIIF Presentation API v3 compliant manifest.
 */
class IiifV3ManifestController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The WDB data service.
   *
   * @var \Drupal\wdb_core\Service\WdbDataService
   */
  protected WdbDataService $wdbDataService;

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected ClientFactory $httpClientFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The subsystem access policy.
   *
   * @var \Drupal\wdb_core\Access\SubsystemAccessPolicyInterface
   */
  protected SubsystemAccessPolicyInterface $accessPolicy;

  /**
   * Constructs a new IiifV3ManifestController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\wdb_core\Service\WdbDataService $wdb_data_service
   *   The WDB data service.
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\wdb_core\Access\SubsystemAccessPolicyInterface $access_policy
   *   The subsystem access policy service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user proxy.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WdbDataService $wdb_data_service, ClientFactory $http_client_factory, RequestStack $request_stack, SubsystemAccessPolicyInterface $access_policy, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->wdbDataService = $wdb_data_service;
    $this->httpClientFactory = $http_client_factory;
    $this->requestStack = $request_stack;
    $this->accessPolicy = $access_policy;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('wdb_core.data_service'),
      $container->get('http_client_factory'),
      $container->get('request_stack'),
      $container->get('wdb_core.subsystem_access_policy'),
      $container->get('current_user'),
    );
  }

  /**
   * Builds the V3 manifest and returns it as a JSON response.
   *
   * @param string $subsysname
   *   The machine name of the subsystem.
   * @param string $source
   *   The source identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the IIIF manifest.
   */
  public function buildManifest(string $subsysname, string $source): JsonResponse {
    $source_storage = $this->entityTypeManager->getStorage('wdb_source');
    $source_entities = $source_storage->loadByProperties(['source_identifier' => $source]);
    if (empty($source_entities)) {
      throw new NotFoundHttpException();
    }
    /** @var \Drupal\wdb_core\Entity\WdbSource $source_entity */
    $source_entity = reset($source_entities);

    $manifest_uri = Url::fromRoute('wdb_core.iiif_manifest_v3', ['subsysname' => $subsysname, 'source' => $source], ['absolute' => TRUE])->toString();

    $manifest = [
      '@context' => 'http://iiif.io/api/presentation/3/context.json',
      'id' => $manifest_uri,
      'type' => 'Manifest',
      'label' => ['en' => [$source_entity->label()]],
      'items' => [],
    ];

    $all_pages = $this->wdbDataService->getAllPagesForSource($source_entity);
    $iiif_base_url = $this->wdbDataService->getIiifBaseUrlForSubsystem($subsysname);
    if (!$iiif_base_url) {
      $this->getLogger('wdb_core')->error('IIIF base URL is not configured for subsystem: @subsys', ['@subsys' => $subsysname]);
      throw new NotFoundHttpException('IIIF configuration is incomplete for this subsystem.');
    }

    $subsys_config = $this->wdbDataService->getSubsystemConfig($subsysname);
    if (!$subsys_config) {
      throw new NotFoundHttpException('Subsystem configuration not found.');
    }

    $user_has_gallery_access = $this->accessPolicy->userHasAccess($subsysname, $this->currentUser, ['permission' => 'view wdb gallery pages']);

    // Add the viewingDirection property from the subsystem configuration.
    $page_navigation = $subsys_config->get('pageNavigation');
    if ($page_navigation) {
      $manifest['viewingDirection'] = $page_navigation;
    }

    // Optional: rights (license), attribution, and logo based on subsystem
    // config.
    if ($license = $subsys_config->get('iiif_license')) {
      // IIIF Presentation API 3 uses the 'rights' property for license URI.
      $manifest['rights'] = $license;
    }

    if ($attribution = $subsys_config->get('iiif_attribution')) {
      // Use requiredStatement with a standard Attribution label.
      $manifest['requiredStatement'] = [
        'label' => ['en' => ['Attribution']],
        'value' => ['en' => [$attribution]],
      ];
    }

    if ($logo_url = $subsys_config->get('iiif_logo')) {
      // Simple media type inference from extension (fallback image/png).
      $ext = strtolower(pathinfo(parse_url($logo_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
      $mime_map = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
      ];
      $format = $mime_map[$ext] ?? 'image/png';

      // In IIIF v3 branding is expressed via provider[].logo[]. If provider
      // absent, create a minimal one using display_title if available.
      if (!isset($manifest['provider'])) {
        $display_title = $subsys_config->get('display_title');
        $provider_id = $subsys_config->get('display_title_link');
        if (!$provider_id) {
          // Fallback to front page if no explicit link configured.
          try {
            $provider_id = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
          }
          catch (\Exception $e) {
            // Final fallback: use manifest URI.
            $provider_id = $manifest_uri;
          }
        }
        // Ensure provider_id is absolute (IIIF requires URI).
        if ($provider_id && !preg_match('/^https?:\/\//i', $provider_id)) {
          if ($this->requestStack && ($req = $this->requestStack->getCurrentRequest())) {
            $base = $req->getSchemeAndHttpHost();
            $provider_id = rtrim($base, '/') . '/' . ltrim($provider_id, '/');
          }
        }
        $provider = [
          'id' => $provider_id,
          'type' => 'Agent',
          'label' => $display_title ? ['en' => [$display_title]] : ['en' => ['Provider']],
          'logo' => [
            [
              'id' => $logo_url,
              'type' => 'Image',
              'format' => $format,
            ],
          ],
        ];
        $manifest['provider'] = [$provider];
      }
      elseif (isset($manifest['provider'][0])) {
        // Ensure provider has an id if one was previously created without it.
        if (!isset($manifest['provider'][0]['id'])) {
          $fallback_id = $subsys_config->get('display_title_link') ?: $manifest_uri;
          if ($fallback_id && !preg_match('/^https?:\/\//i', $fallback_id)) {
            if ($this->requestStack && ($req = $this->requestStack->getCurrentRequest())) {
              $base = $req->getSchemeAndHttpHost();
              $fallback_id = rtrim($base, '/') . '/' . ltrim($fallback_id, '/');
            }
          }
          $manifest['provider'][0]['id'] = $fallback_id;
        }
        if (!isset($manifest['provider'][0]['logo'])) {
          $manifest['provider'][0]['logo'] = [];
        }
        $manifest['provider'][0]['logo'][] = [
          'id' => $logo_url,
          'type' => 'Image',
          'format' => $format,
        ];
      }
    }

    // Determine desired IIIF delivered image file extension; currently we keep
    // IIIF Image API canonical default (jpg) for delivered tiles and thumbs.
    // (Extensible if future formats are required.)
    $image_ext = 'jpg';

    foreach ($all_pages as $page_entity) {
      $image_identifier = $page_entity->getImageIdentifier();
      if (empty($image_identifier)) {
        continue;
      }

      $info_json_url = $iiif_base_url . '/' . rawurlencode($image_identifier) . '/info.json';
      // Always sign server-side fetches so Cantaloupe accepts the request.
      $signed_info_json_url = $this->wdbDataService->appendIiifTokenToUrl($info_json_url, $subsysname, $image_identifier);

      // Fetch image dimensions from the IIIF server's info.json file.
      try {
        $client = $this->httpClientFactory->fromOptions(['timeout' => 10]);
        $response = $client->get($signed_info_json_url);
        $info_data = json_decode($response->getBody(), TRUE);
        $width = $info_data['width'] ?? 2000;
        $height = $info_data['height'] ?? 2000;
      }
      catch (\Exception $e) {
        $this->getLogger('wdb_core')->error(
          'Failed to fetch info.json for @url: @message',
          [
            '@url' => $signed_info_json_url,
            '@message' => $e->getMessage(),
          ]
        );
        // Use fallback dimensions on failure.
        $width = 2000;
        $height = 2000;
      }

      $canvas_uri = $page_entity->getCanvasUri();

      // Generate the URL for the annotation list associated with this canvas.
      $annotation_list_uri = Url::fromRoute('wdb_core.word_annotation_list_v3', [
        'wdb_annotation_page' => $page_entity->id(),
      ], [
        'absolute' => TRUE,
      ])->toString();

      // Define the base URI for the image service (without /info.json).
      $image_service_uri = $iiif_base_url . '/' . rawurlencode($image_identifier);
      $public_image_service_uri = $this->maybeAppendIiifToken($image_service_uri, $subsysname, $image_identifier, $user_has_gallery_access);

      // Define the URI for the actual image content to be displayed.
      $image_content_uri = $image_service_uri . '/full/max/0/default.' . $image_ext;
      $public_image_content_uri = $this->maybeAppendIiifToken($image_content_uri, $subsysname, $image_identifier, $user_has_gallery_access);

      // Generate the thumbnail image URL (150px width, auto height).
      $thumbnail_image_uri = $image_service_uri . '/full/150,/0/default.' . $image_ext;
      $public_thumbnail_uri = $this->maybeAppendIiifToken($thumbnail_image_uri, $subsysname, $image_identifier, $user_has_gallery_access);

      $canvas = [
        'id' => $canvas_uri,
        'type' => 'Canvas',
        'height' => $height,
        'width' => $width,
        'label' => ['en' => [$page_entity->label()]],
        // Add the thumbnail property.
        'thumbnail' => [
          [
            'id' => $public_thumbnail_uri,
            'type' => 'Image',
            'format' => 'image/jpeg',
            'service' => [
              [
                'id' => $public_image_service_uri,
                'type' => 'ImageService3',
                'profile' => 'level2',
              ],
            ],
          ],
        ],
        'items' => [
          [
            'id' => $canvas_uri . '/page/1',
            'type' => 'AnnotationPage',
            'items' => [
              [
                'id' => $canvas_uri . '/page/1/image',
                'type' => 'Annotation',
                'motivation' => 'painting',
                'body' => [
                  'id' => $public_image_content_uri,
                  'type' => 'Image',
                  'format' => 'image/jpeg',
                  'width' => $width,
                  'height' => $height,
                  'service' => [
                    [
                      'id' => $public_image_service_uri,
                      'type' => 'ImageService3',
                      'profile' => 'level2',
                    ],
                  ],
                ],
                'target' => $canvas_uri,
              ],
            ],
          ],
        ],
        'annotations' => [
          [
            'id' => $annotation_list_uri,
            'type' => 'AnnotationPage',
            'label' => ['en' => ['Word-level Annotations for this page']],
          ],
        ],
      ];
      $manifest['items'][] = $canvas;
    }

    // Normalize provider IDs to absolute URLs (in case request stack was not
    // available during provider construction or a relative path was stored).
    if (!empty($manifest['provider']) && is_array($manifest['provider'])) {
      $scheme = parse_url($manifest_uri, PHP_URL_SCHEME);
      $host = parse_url($manifest_uri, PHP_URL_HOST);
      $port = parse_url($manifest_uri, PHP_URL_PORT);
      if ($scheme && $host) {
        $base = $scheme . '://' . $host . ($port ? ':' . $port : '');
        foreach ($manifest['provider'] as &$prov) {
          if (isset($prov['id']) && is_string($prov['id']) && !preg_match('/^https?:\/\//i', $prov['id'])) {
            $prov['id'] = rtrim($base, '/') . '/' . ltrim($prov['id'], '/');
          }
        }
        unset($prov);
      }
    }

    $response = new JsonResponse($manifest);
    $response->headers->set('Content-Type', 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"');

    return $response;
  }

  /**
   * Returns a signed IIIF URL only when the viewer may access the subsystem.
   */
  protected function maybeAppendIiifToken(string $url, string $subsysname, string $image_identifier, bool $should_sign): string {
    return $should_sign ? $this->wdbDataService->appendIiifTokenToUrl($url, $subsysname, $image_identifier) : $url;
  }

}
