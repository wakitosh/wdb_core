<?php

namespace Drupal\wdb_core\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wdb_core\Entity\Traits\ConfigurableListDisplayTrait;

/**
 * Defines a class to build a listing of WDB Annotation Page entities.
 *
 * This class is responsible for rendering the administrative list of all
 * WDB Annotation Page entities at /admin/content/wdb_annotation_page.
 *
 * @see \Drupal\wdb_core\Entity\WdbAnnotationPage
 */
class WdbAnnotationPageListBuilder extends EntityListBuilder {
  use ConfigurableListDisplayTrait;

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    // Avoid stale admin listings (especially paged URLs like ?page=N) after
    // creating/deleting entities.
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return $this->buildConfigurableHeader([
      'id',
      'annotation_code',
      'source_ref',
      'page_number',
      'page_name',
      'page_name_computed',
      'image_identifier',
    ]) + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\wdb_core\Entity\WdbAnnotationPage $entity */
    return $this->buildConfigurableRow($entity, [
      'id',
      'annotation_code',
      'source_ref',
      'page_number',
      'page_name',
      'page_name_computed',
      'image_identifier',
    ]) + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, $entity_type) {
    /** @var static $instance */
    $instance = parent::createInstance($container, $entity_type);
    $instance->configFactory = $container->get('config.factory');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getListEntityTypeId(): string {
    return 'wdb_annotation_page';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    // You can add custom operations here if needed. For example, a link
    // to the gallery view page for this specific annotation page.
    return $operations;
  }

}
