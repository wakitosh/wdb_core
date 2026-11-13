<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
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
    $q = (string) $request->query->get('q', '');
    $subsystems = $q === '' ? [] : $this->resourceAccess()->readableSubsystemIds();
    if (!$subsystems) {
      return new JsonResponse([]);
    }

    // Each route suggests one field, reached from data that belongs to a
    // source: word units for forms and lemmas, sign interpretations for signs.
    [$entity_type, $field, $subsystem_field] = match ($request->attributes->get('_route')) {
      'wdb_core.autocomplete.basic_form' => ['wdb_word_unit', 'word_meaning_ref.entity.word_ref.entity.basic_form', 'source_ref.entity.subsystem_tags'],
      'wdb_core.autocomplete.sign' => ['wdb_sign_interpretation', 'sign_function_ref.entity.sign_ref.entity.sign_code', 'annotation_page_ref.entity.source_ref.entity.subsystem_tags'],
      default => ['wdb_word_unit', 'realized_form', 'source_ref.entity.subsystem_tags'],
    };

    // One grouped query returns the distinct values. Loading every readable
    // entity to filter in PHP took seconds per keystroke on a real data set.
    $rows = $this->entityTypeManager->getStorage($entity_type)->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition($subsystem_field, $subsystems, 'IN')
      ->condition($field, $q, 'CONTAINS')
      ->groupBy($field)
      ->sort($field)
      ->range(0, 10)
      ->execute();

    $matches = [];
    foreach ($rows as $row) {
      $value = reset($row);
      if ($value !== NULL && $value !== '') {
        $matches[] = ['value' => $value, 'label' => $value];
      }
    }
    return new JsonResponse($matches);
  }

}
