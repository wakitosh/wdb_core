<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles autocomplete requests for WDB forms.
 *
 * Provides a JSON response with suggestions for various entity fields based on
 * user input and the calling route.
 */
class AutocompleteController extends ControllerBase implements ContainerInjectionInterface {

  use WdbResourceAccessTrait;

  /**
   * Constructs a new AutocompleteController object.
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
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Handles autocomplete suggestions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions.
   */
  public function handleAutocomplete(Request $request): JsonResponse {
    $matches = [];
    $q = (string) $request->query->get('q', '');
    $route_name = $request->attributes->get('_route');

    if (strlen($q) > 0) {
      // Set default target entity and field.
      $target_entity_type = 'wdb_word_unit';
      $target_field = 'realized_form';

      // Determine the target entity and field based on the route name.
      if ($route_name === 'wdb_core.autocomplete.basic_form') {
        $target_entity_type = 'wdb_word';
        $target_field = 'basic_form';
      }
      elseif ($route_name === 'wdb_core.autocomplete.sign') {
        $target_entity_type = 'wdb_sign';
        $target_field = 'sign_code';
      }

      // Build and execute the entity query.
      $query = $this->entityTypeManager->getStorage($target_entity_type)->getQuery();
      $subsystems = $this->resourceAccess()->readableSubsystemIds();
      if (!$subsystems) {
        return new JsonResponse([]);
      }
      if ($target_entity_type === 'wdb_word_unit') {
        $query->condition('source_ref.entity.subsystem_tags', $subsystems, 'IN');
      }
      else {
        $ids = $this->readableDictionaryIds($target_entity_type, $subsystems);
        if (!$ids) {
          return new JsonResponse([]);
        }
        $query->condition('id', $ids, 'IN');
      }
      $query->condition($target_field, $q, 'CONTAINS');
      // Removed unsupported groupBy; fetch more rows and deduplicate manually.
      $query->range(0, 50);
      $ids = $query->accessCheck(FALSE)->execute();

      if (!empty($ids)) {
        $entities = $this->entityTypeManager->getStorage($target_entity_type)->loadMultiple($ids);
        $found_values = [];
        foreach ($entities as $entity) {
          if ($entity instanceof ContentEntityInterface && $entity->hasField($target_field) && !$entity->get($target_field)->isEmpty()) {
            $value = $entity->get($target_field)->value;
            if (!in_array($value, $found_values, TRUE)) {
              $matches[] = ['value' => $value, 'label' => $value];
              $found_values[] = $value;
              if (count($matches) >= 10) {
                break;
              }
            }
          }
        }
      }
    }
    return new JsonResponse($matches);
  }

  /**
   * Limits dictionary suggestions to entries used by readable sources.
   */
  private function readableDictionaryIds(string $type, array $subsystems): array {
    $ids = [];
    if ($type === 'wdb_word') {
      $storage = $this->entityTypeManager->getStorage('wdb_word_unit');
      $unit_ids = $storage->getQuery()->condition('source_ref.entity.subsystem_tags', $subsystems, 'IN')->accessCheck(FALSE)->execute();
      $meaning_ids = [];
      foreach ($storage->loadMultiple($unit_ids) as $unit) {
        if ($id = $unit->get('word_meaning_ref')->target_id) {
          $meaning_ids[$id] = $id;
        }
      }
      foreach ($this->entityTypeManager->getStorage('wdb_word_meaning')->loadMultiple($meaning_ids) as $meaning) {
        if ($id = $meaning->get('word_ref')->target_id) {
          $ids[$id] = $id;
        }
      }
    }
    else {
      $storage = $this->entityTypeManager->getStorage('wdb_sign_interpretation');
      $interpretation_ids = $storage->getQuery()->condition('annotation_page_ref.entity.source_ref.entity.subsystem_tags', $subsystems, 'IN')->accessCheck(FALSE)->execute();
      $function_ids = [];
      foreach ($storage->loadMultiple($interpretation_ids) as $interpretation) {
        if ($id = $interpretation->get('sign_function_ref')->target_id) {
          $function_ids[$id] = $id;
        }
      }
      foreach ($this->entityTypeManager->getStorage('wdb_sign_function')->loadMultiple($function_ids) as $function) {
        if ($id = $function->get('sign_ref')->target_id) {
          $ids[$id] = $id;
        }
      }
    }
    return array_values($ids);
  }

}
