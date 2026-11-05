<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\wdb_core\Entity\WdbSource;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for displaying a WDB Source entity.
 */
class WdbSourceViewController extends ControllerBase implements ContainerInjectionInterface {

  use WdbResourceAccessTrait;

  /**
   * Constructs a new WdbSourceViewController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Helper method to load a WdbSource entity by its string identifier.
   *
   * @param string $source_identifier
   *   The unique string identifier of the source.
   *
   * @return \Drupal\wdb_core\Entity\WdbSource|null
   *   The loaded WdbSource entity, or NULL if not found.
   */
  private function loadSourceByIdentifier(string $source_identifier): ?WdbSource {
    $sources = $this->entityTypeManager()->getStorage('wdb_source')->loadByProperties(['source_identifier' => $source_identifier]);
    return reset($sources) ?: NULL;
  }

  /**
   * Displays a WDB Source entity.
   *
   * @param string $source_identifier
   *   The source identifier from the URL.
   *
   * @return array
   *   A render array for the entity view page.
   */
  public function view(string $source_identifier) {
    $wdb_source = $this->loadSourceByIdentifier($source_identifier);
    if (!$wdb_source) {
      throw new NotFoundHttpException();
    }
    $this->resourceAccess()->assertViewAccess($wdb_source);
    $view_builder = $this->entityTypeManager()->getViewBuilder('wdb_source');
    $build = $view_builder->view($wdb_source, 'full');
    // This route's controller performs resource authorization; do not let a
    // response cached for another user skip the controller on a later request.
    $build['#cache']['contexts'][] = 'user';
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * Returns the title for a WDB Source page.
   *
   * @param string $source_identifier
   *   The source identifier from the URL.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The page title.
   */
  public function getTitle(string $source_identifier) {
    $wdb_source = $this->loadSourceByIdentifier($source_identifier);
    if ($wdb_source) {
      $this->resourceAccess()->assertViewAccess($wdb_source);
      return $wdb_source->label();
    }
    return $this->t('Source');
  }

}
