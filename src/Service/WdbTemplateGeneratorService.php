<?php

namespace Drupal\wdb_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\wdb_core\Entity\WdbSource;
use Drupal\wdb_core\Entity\WdbSignInterpretation;
use Psr\Log\LoggerInterface;

/**
 * Service for generating TSV templates for data import.
 *
 * This service provides methods to create TSV file templates based on either
 * existing data within the system or from the output of a MeCab analysis file.
 */
class WdbTemplateGeneratorService {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * A cache of POS mapping entities, sorted by weight.
   *
   * @var array
   */
  protected array $posMappingCache = [];
  /**
   * Logger channel for this service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new WdbTemplateGeneratorService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('wdb_core');
    $this->loadPosMappings();
  }

  /**
   * Loads and caches all WdbPosMapping entities, sorted by weight.
   */
  protected function loadPosMappings() {
    $storage = $this->entityTypeManager->getStorage('wdb_pos_mapping');
    $ids = $storage->getQuery()
      ->sort('weight', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    if ($ids) {
      $this->posMappingCache = $storage->loadMultiple($ids);
    }
  }

  /**
   * Generates a TSV template from an existing WdbSource entity.
   *
   * @param \Drupal\wdb_core\Entity\WdbSource $source
   *   The source entity to generate a template from.
   *
   * @return string
   *   The generated TSV content as a string.
   */
  public function generateTemplateFromSource(WdbSource $source): string {
    $header = [
      'source', 'page', 'image_identifier', 'labelname',
      'sign', 'function', 'phone', 'note',
      'word_unit', 'basic_form', 'realized_form',
      'word_sequence',
      'lexical_category_name',
      'meaning', 'explanation',
      'verbal_form_name', 'gender_name', 'number_name', 'person_name',
      'voice_name', 'aspect_name', 'mood_name', 'grammatical_case_name',
    ];

    $rows = [];
    $rows[] = implode("\t", $header);

    $page_storage = $this->entityTypeManager->getStorage('wdb_annotation_page');
    $page_ids = $page_storage->getQuery()
      ->condition('source_ref', $source->id())
      ->accessCheck(FALSE)->execute();

    if (!empty($page_ids)) {
      $si_storage = $this->entityTypeManager->getStorage('wdb_sign_interpretation');
      $si_ids = $si_storage->getQuery()
        ->condition('annotation_page_ref', $page_ids, 'IN')
        ->sort('id', 'ASC')
        ->range(0, 5)
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($si_ids)) {
        $interpretations = $si_storage->loadMultiple($si_ids);
        foreach ($interpretations as $si) {
          if ($si instanceof WdbSignInterpretation) {
            $rows[] = $this->reconstructRowFromSignInterpretation($si);
          }
        }
      }
    }

    return implode("\n", $rows);
  }

  /**
   * Generates a TSV template from a MeCab analysis result file.
   *
   * @param string $source_file_path
   *   The path to the MeCab output file.
   * @param string $source_identifier
   *   The identifier for the source document.
   * @param array $context
   *   The batch API context array for logging skipped lines.
   *
   * @return string
   *   The generated TSV content as a string.
   */
  public function generateTemplateFromMecab(string $source_file_path, string $source_identifier, array &$context): string {
    $output_data = [];
    $header = [
      'source', 'page', 'image_identifier', 'labelname',
      'sign', 'function', 'phone', 'note',
      'word_unit', 'basic_form', 'realized_form',
      'word_sequence',
      'lexical_category_name',
      'meaning', 'explanation',
      'verbal_form_name', 'gender_name', 'number_name', 'person_name',
      'voice_name', 'aspect_name', 'mood_name', 'grammatical_case_name',
    ];
    $output_data[] = implode("\t", $header);

    $handle = @fopen($source_file_path, 'r');
    if ($handle === FALSE) {
      return '';
    }

    $word_counter = 0;
    $line_counter = 0;
    $context['warnings'] = [];

    // Assumes the Chaki import format from "Web-chamame".
    while (($line = fgets($handle)) !== FALSE) {
      $line_counter++;
      $line = trim($line);
      if (empty($line) || $line === 'EOS') {
        continue;
      }

      if (strpos($line, "\t") === FALSE) {
        $context['skipped_lines'][] = $this->t(
          'Line @num: Invalid format (skipped). Content: @content',
          [
            '@num' => $line_counter,
            '@content' => $line,
          ]
        );
        continue;
      }

      $parts = preg_split('/[\t,]/', $line);
      $realized_form = $parts[0] ?? '';
      $pos_parts = array_slice($parts, 1, 4);
      $pos_string = implode('-', $pos_parts);
      $basic_form = $parts[8] ?? $realized_form;

      $lexical_category_name = $this->getMappedCategoryName($pos_string);

      // If mapping failed, log a warning and add it to the context.
      if (empty($lexical_category_name)) {
        $warning_message = $this->t('Could not map POS string "@pos" from line @num to a lexical category. The "lexical_category_name" field will be empty.', [
          '@pos' => $pos_string,
          '@num' => $line_counter,
        ]);
        $this->logger->warning($warning_message);
        $context['warnings'][] = $warning_message;
      }

      $word_counter++;
      $current_word_unit = $word_counter;

      $characters = preg_split('//u', $realized_form, -1, PREG_SPLIT_NO_EMPTY);

      foreach ($characters as $char) {
        $row = [
          'source' => $source_identifier,
          'page' => '',
          'image_identifier' => '',
          'labelname' => '',
          'sign' => $char,
          'function' => '',
          'phone' => '',
          'note' => '',
          'word_unit' => $current_word_unit,
          'basic_form' => $basic_form,
          'realized_form' => $realized_form,
          'word_sequence' => $current_word_unit,
          'lexical_category_name' => $lexical_category_name,
          'meaning' => '',
          'explanation' => '',
          'verbal_form_name' => '',
          'gender_name' => '',
          'number_name' => '',
          'person_name' => '',
          'voice_name' => '',
          'aspect_name' => '',
          'mood_name' => '',
          'grammatical_case_name' => '',
        ];

        $ordered_row = [];
        foreach ($header as $key) {
          $ordered_row[] = $row[$key] ?? '';
        }
        $output_data[] = implode("\t", $ordered_row);
      }
    }
    fclose($handle);

    return implode("\n", $output_data);
  }

  /**
   * Reconstructs a single TSV row from a WdbSignInterpretation entity.
   *
   * @param \Drupal\wdb_core\Entity\WdbSignInterpretation $si
   *   The sign interpretation entity.
   *
   * @return string
   *   A tab-separated string for a single row.
   */
  private function reconstructRowFromSignInterpretation(WdbSignInterpretation $si): string {
    $row = [];
    $page = $si->get('annotation_page_ref')->entity;
    $source = $page ? $page->get('source_ref')->entity : NULL;
    $label = $si->get('label_ref')->entity;
    $sign_function = $si->get('sign_function_ref')->entity;
    $sign = $sign_function ? $sign_function->get('sign_ref')->entity : NULL;

    $map_storage = $this->entityTypeManager->getStorage('wdb_word_map');
    $maps = $map_storage->loadByProperties(['sign_interpretation_ref' => $si->id()]);
    $wu = NULL;
    if (!empty($maps)) {
      $first = reset($maps);
      /** @var \Drupal\wdb_core\Entity\WdbWordMap $first */
      if ($first) {
        $wu = $first->get('word_unit_ref')->entity;
      }
    }

    $row['source'] = $source ? $source->get('source_identifier')->value : '';
    $row['page'] = $page ? $page->get('page_number')->value : '';
    // Use only the stored value here. Do not call getImageIdentifier(),
    // because template generation should not depend on subsystem patterns.
    $row['image_identifier'] = $page ? (string) ($page->get('image_identifier')->value ?? '') : '';
    $row['labelname'] = $label ? $label->label() : '';
    $row['sign'] = $sign ? $sign->label() : '';
    $row['function'] = $sign_function ? $sign_function->get('function_name')->value : '';
    $row['phone'] = $si->get('phone')->value;
    $row['note'] = $si->get('note')->value;

    if ($wu) {
      // Be tolerant of missing original identifier to avoid deprecations.
      $original_identifier = (string) ($wu->get('original_word_unit_identifier')->value ?? '');
      $original_id_parts = explode('_', $original_identifier);
      $row['word_unit'] = end($original_id_parts);
      $row['realized_form'] = $wu->get('realized_form')->value;
      $row['word_sequence'] = $wu->get('word_sequence')->value;

      $meaning = $wu->get('word_meaning_ref')->entity;
      if ($meaning) {
        $word = $meaning->get('word_ref')->entity;
        $row['basic_form'] = $word ? $word->get('basic_form')->value : '';
        $row['lexical_category_name'] = $word && $word->get('lexical_category_ref')->entity ? $word->get('lexical_category_ref')->entity->label() : '';
        $row['meaning'] = $meaning->get('meaning_identifier')->value;
        $row['explanation'] = $meaning->get('explanation')->value;
      }

      $grammar_fields = [
        'verbal_form', 'gender', 'number', 'person',
        'voice', 'aspect', 'mood', 'grammatical_case',
      ];
      foreach ($grammar_fields as $field) {
        $row[$field . '_name'] = $wu->get($field . '_ref')->entity ? $wu->get($field . '_ref')->entity->label() : '';
      }
    }

    $header_keys = [
      'source', 'page', 'image_identifier', 'labelname', 'sign', 'function',
      'phone', 'note', 'word_unit', 'basic_form', 'realized_form',
      'word_sequence',
      'lexical_category_name',
      'meaning', 'explanation',
      'verbal_form_name', 'gender_name', 'number_name', 'person_name',
      'voice_name', 'aspect_name', 'mood_name', 'grammatical_case_name',
    ];
    $ordered_row = [];
    foreach ($header_keys as $key) {
      $ordered_row[] = $row[$key] ?? '';
    }

    return implode("\t", $ordered_row);
  }

  /**
   * Gets the mapped lexical category name for a given POS string.
   *
   * @param string $pos_string
   *   The Part-of-Speech string (e.g., "名詞-普通名詞-一般-*").
   *
   * @return string
   *   The name of the mapped taxonomy term, or an empty string if no match.
   */
  private function getMappedCategoryName(string $pos_string): string {
    foreach ($this->posMappingCache as $mapping) {
      $pattern = $mapping->source_pos_string;
      // Convert fnmatch-like wildcard pattern to a safe regex.
      $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/u';
      if (preg_match($regex, $pos_string) === 1) {
        $term_id = $mapping->target_lexical_category;
        if ($term_id) {
          $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
          return $term ? $term->label() : '';
        }
      }
    }
    return '';
  }

}
