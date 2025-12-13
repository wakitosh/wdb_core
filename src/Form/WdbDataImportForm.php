<?php

namespace Drupal\wdb_core\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\wdb_core\Service\WdbDataImporterService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for importing linguistic data from a TSV/CSV file.
 */
class WdbDataImportForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The WDB data importer service.
   *
   * @var \Drupal\wdb_core\Service\WdbDataImporterService
   */
  protected WdbDataImporterService $dataImporter;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Constructs a new WdbDataImportForm object.
   *
   * @param \Drupal\wdb_core\Service\WdbDataImporterService $dataImporter
   *   The WDB data importer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(WdbDataImporterService $dataImporter, EntityTypeManagerInterface $entityTypeManager, LanguageManagerInterface $languageManager, FileSystemInterface $fileSystem) {
    $this->dataImporter = $dataImporter;
    $this->entityTypeManager = $entityTypeManager;
    $this->languageManager = $languageManager;
    $this->fileSystem = $fileSystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wdb_core.data_importer'),
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wdb_core_data_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('Upload a TSV or CSV file containing linguistic annotation data. The file must be sorted by "word_unit" to ensure correct sign sequence generation. The optional "image_identifier" column can be used to explicitly set per-page IIIF image identifiers.'),
    ];

    $language_options = [];
    $languages = $this->languageManager->getLanguages();
    foreach ($languages as $langcode => $language) {
      $language_options[$langcode] = $language->getName();
    }

    $form['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#description' => $this->t('Select the language for the data in the import file.'),
      '#options' => $language_options,
      '#required' => TRUE,
    ];

    $form['data_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Data File'),
      '#description' => $this->t('Allowed extensions: tsv, csv.'),
      '#required' => TRUE,
      '#upload_validators' => [
        // Use the 'FileExtension' constraint plugin. The key is the plugin ID,
        // and the value is an array of options for that plugin.
        'FileExtension' => [
          'extensions' => 'tsv csv',
        ],
      ],
      '#upload_location' => 'private://wdb_imports/',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Data'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_id = $form_state->getValue(['data_file', 0]);
    if (empty($file_id)) {
      return;
    }

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->load($file_id);
    if (!$file) {
      $this->messenger()->addError($this->t('File upload failed. Please try again.'));
      return;
    }

    $file->setPermanent();
    $file->save();
    $langcode = $form_state->getValue('langcode');

    $operations = [
      ['\Drupal\wdb_core\Form\WdbDataImportForm::processImportFile', [$file->getFileUri(), $langcode]],
    ];

    $batch = [
      'title' => $this->t('Importing linguistic data for language: @lang', ['@lang' => $langcode]),
      'operations' => $operations,
      'finished' => '\Drupal\wdb_core\Form\WdbDataImportForm::batchFinishedCallback',
      'wdb_source_filename' => $file->getFilename(),
      'wdb_language' => $langcode,
      'init_message' => $this->t('Starting data import.'),
      'progress_message' => $this->t('Processing row @current of @total.'),
      'error_message' => $this->t('An error occurred during processing.'),
    ];

    batch_set($batch);
  }

  /**
   * Batch API operation callback for processing the file in chunks.
   *
   * @param string $file_uri
   *   The URI of the file to process.
   * @param string $langcode
   *   The language code.
   * @param array $context
   *   The batch API context array.
   *
   * @throws \Exception
   *   If the import file cannot be opened.
   */
  public static function processImportFile(string $file_uri, string $langcode, array &$context) {
    /** @var \Drupal\wdb_core\Service\WdbDataImporterService $importer */
    $importer = \Drupal::service('wdb_core.data_importer');

    // Initialize sandbox and results on the first run.
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['file_path'] = \Drupal::service('file_system')->realpath($file_uri);
      $context['sandbox']['max'] = max(0, count(file($context['sandbox']['file_path'], FILE_SKIP_EMPTY_LINES)) - 1);
      $context['sandbox']['file_position'] = 0;
      $context['results'] = [
        'created' => 0,
        'failed' => 0,
        'errors' => [],
        'warnings' => [],
        'created_entities' => [],
      ];
      $context['sandbox']['sequencing_state'] = ['last_word_unit_id' => NULL, 'sign_seq_counter' => 1];
    }

    $limit = 50;
    $header = $context['sandbox']['header'] ?? NULL;
    $handle = @fopen($context['sandbox']['file_path'], 'r');

    if ($handle === FALSE) {
      throw new \Exception('Failed to open the import file for processing.');
    }

    // Seek to the last known position in the file for subsequent runs.
    if ($context['sandbox']['file_position'] > 0) {
      fseek($handle, $context['sandbox']['file_position']);
    }
    elseif (empty($header)) {
      // Read the header row on the first run.
      $header = fgetcsv($handle, 0, "\t");
      // Remove UTF-8 BOM if present.
      if (isset($header[0]) && strpos($header[0], "\xEF\xBB\xBF") === 0) {
        $header[0] = substr($header[0], 3);
      }
      $context['sandbox']['header'] = $header;
    }

    for ($i = 0; $i < $limit && !feof($handle); $i++) {
      $data = fgetcsv($handle, 0, "\t");
      if ($data === FALSE || $data === [NULL] || !is_array($data)) {
        continue;
      }

      if (count($header) !== count($data)) {
        $context['results']['failed']++;
        $context['results']['errors'][] = t('Skipped row @row_num due to column count mismatch.', ['@row_num' => $context['sandbox']['progress'] + 1]);
        $context['sandbox']['progress']++;
        continue;
      }

      $rowData = array_combine($header, $data);
      $word_unit_from_tsv = (int) trim($rowData['word_unit'] ?? 0);
      $word_seq = (float) trim($rowData['word_sequence'] ?? 0);

      // Manage the sign sequence counter based on the word_unit.
      $state = &$context['sandbox']['sequencing_state'];
      if ($word_unit_from_tsv !== $state['last_word_unit_id']) {
        $state['sign_seq_counter'] = 1;
      }
      $sign_seq = (float) $state['sign_seq_counter'];

      $success = $importer->processImportRow($rowData, $langcode, $word_seq, $sign_seq, $context);

      if ($success) {
        $state['sign_seq_counter']++;
      }
      $state['last_word_unit_id'] = $word_unit_from_tsv;

      $context['sandbox']['progress']++;
    }

    $context['sandbox']['file_position'] = ftell($handle);
    fclose($handle);

    // Update the batch progress.
    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch API finished callback.
   *
   * @param bool $success
   *   A boolean indicating whether the batch operation was successful.
   * @param array $results
   *   An array of results collected during the batch operation.
   * @param array $operations
   *   An array of the operations that were performed.
   */
  public static function batchFinishedCallback(bool $success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    $batch = &batch_get();

    // Get filename and language from the batch definition.
    $filename = $batch['sets'][0]['wdb_source_filename'] ?? 'N/A';
    $langcode = $batch['sets'][0]['wdb_language'] ?? 'und';

    if ($success) {
      $created_count = $results['created'] ?? 0;
      $failed_count = $results['failed'] ?? 0;
      if ($created_count > 0) {
        $messenger->addStatus(\Drupal::translation()->formatPlural($created_count, 'Successfully processed 1 record.', 'Successfully processed @count records.'));
      }
      if ($failed_count > 0) {
        $messenger->addWarning(\Drupal::translation()->formatPlural($failed_count, '1 row was skipped. See logs for details.', '@count rows were skipped. See logs for details.'));
      }
      if (!empty($results['warnings'])) {
        $messenger->addWarning(\Drupal::translation()->formatPlural(
            count($results['warnings']),
            '1 warning was generated. See logs for details.',
            '@count warnings were generated. See logs for details.'
        ));
        foreach ($results['warnings'] as $warning) {
          \Drupal::logger('wdb_import')->warning($warning);
        }
      }
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          \Drupal::logger('wdb_import')->warning($error);
        }
      }
      // Create an import log entry.
      if ($created_count > 0 || $failed_count > 0) {
        try {
          $log_storage = \Drupal::entityTypeManager()->getStorage('wdb_import_log');
          $summary = t('Processed: @processed, Succeeded: @created, Failed: @failed, Warnings: @warnings', [
            '@processed' => ($created_count + $failed_count),
            '@created' => $created_count,
            '@failed' => $failed_count,
            '@warnings' => count($results['warnings'] ?? []),
          ]);
          $log = $log_storage->create([
            'label' => t('Import on @date', ['@date' => \Drupal::service('date.formatter')->format(time(), 'short')]),
            'user_id' => \Drupal::currentUser()->id(),
            'status' => ($failed_count === 0),
            'summary' => $summary,
            'created_entities' => json_encode($results['created_entities'] ?? []),
            'source_filename' => $filename,
            'language' => $langcode,
          ]);
          $log->save();
          $messenger->addStatus(t('An import log has been created. You can review or roll back this import from the <a href=":url">Import History</a> page.', [
            ':url' => Url::fromRoute('entity.wdb_import_log.collection')->toString(),
          ]));
        }
        catch (\Exception $e) {
          $messenger->addError(t('Failed to create an import log.'));
          \Drupal::logger('wdb_import')->error('Failed to create import log: @message', ['@message' => $e->getMessage()]);
        }
      }
    }
    else {
      $error_operation = reset($operations);
      $message = t('An error occurred during processing. The error message was: @message', ['@message' => $error_operation]);
      $messenger->addError($message);
    }
  }

}
