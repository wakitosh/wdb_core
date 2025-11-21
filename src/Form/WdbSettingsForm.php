<?php

namespace Drupal\wdb_core\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure WDB Core settings for this site.
 *
 * This form dynamically generates configuration tabs for each "subsystem"
 * defined in the 'subsystem' taxonomy vocabulary.
 */
class WdbSettingsForm extends ConfigFormBase {

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /** @var self $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wdb_core_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    // Dynamically build a list of configuration object names to be managed by
    // this form, one for each subsystem term.
    $config_names = [];
    $subsystem_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'subsystem']);
    foreach ($subsystem_terms as $term) {
      $config_names[] = 'wdb_core.subsystem.' . $term->id();
    }
    return $config_names;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $entity_type_manager = $this->entityTypeManager;

    $form['vertical_tabs'] = ['#type' => 'vertical_tabs'];

    // --- Dynamically generate a settings tab for each subsystem ---
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');

    // --- FIX: Sort by term name (alphabetically) instead of weight. ---
    $tids = $term_storage->getQuery()
      ->condition('vid', 'subsystem')
      ->sort('name')
      ->accessCheck(FALSE)
      ->execute();
    // --- END OF FIX ---
    $subsystem_terms = $term_storage->loadMultiple($tids);

    $form['subsystems'] = ['#type' => 'container', '#tree' => TRUE];

    foreach ($subsystem_terms as $term_id => $term) {
      $config_name = 'wdb_core.subsystem.' . $term_id;
      $config = $this->config($config_name);

      $form['subsystems'][$term_id] = [
        '#type' => 'details',
        '#title' => $term->label(),
        '#group' => 'vertical_tabs',
      ];

      $form['subsystems'][$term_id]['display_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Display Title'),
        '#description' => $this->t('The title to be displayed for this subsystem in a block.'),
        '#default_value' => $config->get('display_title'),
        '#maxlength' => 255,
      ];

      $form['subsystems'][$term_id]['display_title_link'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Display Title Link URL'),
        '#description' => $this->t('If you want to link the subsystem title, enter the URL here. You can use an internal path (e.g., /node/1) or a full external URL (e.g., https://example.com). Leave blank for no link.'),
        '#default_value' => $config->get('display_title_link'),
      ];

      $form['subsystems'][$term_id]['iiif_settings'] = [
        '#type' => 'details',
        '#title' => $this->t('IIIF Settings'),
        '#open' => TRUE,
      ];
      $form['subsystems'][$term_id]['iiif_settings']['iiif_server_scheme'] = [
        '#type' => 'select',
        '#title' => $this->t('IIIF Server Scheme'),
        '#options' => ['http' => $this->t('http'), 'https' => $this->t('https')],
        '#default_value' => $config->get('iiif_server_scheme'),
      ];
      $form['subsystems'][$term_id]['iiif_settings']['iiif_server_hostname'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IIIF Server Hostname'),
        '#default_value' => $config->get('iiif_server_hostname'),
        '#description' => $this->t('Do not include slashes at the beginning or end.'),
      ];
      $form['subsystems'][$term_id]['iiif_settings']['iiif_server_prefix'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IIIF Server Prefix'),
        '#default_value' => $config->get('iiif_server_prefix'),
        '#description' => $this->t('Do not include slashes at the beginning or end. No URL encoding is required.'),
      ];
      $form['subsystems'][$term_id]['iiif_settings']['iiif_fileExt'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IIIF Image File Extension'),
        '#default_value' => $config->get('iiif_fileExt'),
        '#description' => $this->t('Do not include the leading dot (e.g., "jpg", not ".jpg").'),
      ];
      $form['subsystems'][$term_id]['iiif_settings']['iiif_license'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IIIF License URL'),
        '#default_value' => $config->get('iiif_license'),
      ];
      $form['subsystems'][$term_id]['iiif_settings']['iiif_attribution'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IIIF Attribution Text'),
        '#default_value' => $config->get('iiif_attribution'),
      ];
      $form['subsystems'][$term_id]['iiif_settings']['iiif_logo'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IIIF Logo URL'),
        '#default_value' => $config->get('iiif_logo'),
      ];

      $form['subsystems'][$term_id]['iiif_settings']['iiif_identifier_pattern'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IIIF Identifier Pattern'),
        '#default_value' => $config->get('iiif_identifier_pattern'),
        '#description' => $this->t('Define a pattern to automatically generate image identifiers. Use placeholders such as <code>{source_identifier}</code>, <code>{page_number}</code>, <code>{page_name}</code>, <code>{subsystem_name}</code>. Optional filters are available, e.g. <code>{source_identifier|substr:0:8}</code> or <code>{source_identifier|substr:-4}</code> (same arguments as PHP substr: start, optional length).'),
        '#placeholder' => '{source_identifier}/{page_number}.tif',
      ];

      if (!empty($config->get('iiif_identifier_pattern'))) {
        $form['subsystems'][$term_id]['iiif_settings']['reapply_pattern'] = [
          '#type' => 'details',
          '#title' => $this->t('Update Existing Pages'),
          '#description' => $this->t('If you have changed the IIIF Identifier Pattern, you can apply the new pattern to all existing annotation pages within this subsystem. This will overwrite any manually set identifiers.'),
        ];
        $form['subsystems'][$term_id]['iiif_settings']['reapply_pattern']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Apply pattern to existing pages in "@subsystem"', ['@subsystem' => $term->label()]),
          '#submit' => ['::submitReapplyPattern'],
          '#subsystem_id' => $term_id,
        ];
      }

      $form['subsystems'][$term_id]['allowAnonymous'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow anonymous access'),
        '#default_value' => $config->get('allowAnonymous'),
      ];
      // Group restriction: prefer entity autocomplete when Group module exists;
      // fall back to raw UUID text when it does not.
      $has_group = $entity_type_manager->hasDefinition('group');
      if ($has_group) {
        $default_group = NULL;
        $existing_uuid = $config->get('group_uuid');
        if (!empty($existing_uuid)) {
          $group_storage = $entity_type_manager->getStorage('group');
          $matches = $group_storage->loadByProperties(['uuid' => $existing_uuid]);
          $default_group = $matches ? reset($matches) : NULL;
        }
        $form['subsystems'][$term_id]['group_ref'] = [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Restrict via Drupal Group'),
          '#description' => $this->t('Optional. Select a Drupal Group whose members may access this subsystem when anonymous access is disabled. The selection is stored as the Group UUID.'),
          '#target_type' => 'group',
          '#default_value' => $default_group,
          '#tags' => FALSE,
          '#states' => [
            'visible' => [
              ':input[name="subsystems[' . $term_id . '][allowAnonymous]"]' => ['checked' => FALSE],
            ],
          ],
        ];
      }
      else {
        $form['subsystems'][$term_id]['group_uuid'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Restrict via Drupal Group (UUID)'),
          '#description' => $this->t('Optional. Paste the UUID of a Drupal Group whose members may access this subsystem when anonymous access is disabled.'),
          '#default_value' => $config->get('group_uuid'),
          '#placeholder' => $this->t('e.g., 550e8400-e29b-41d4-a716-446655440000'),
          '#states' => [
            'visible' => [
              ':input[name="subsystems[' . $term_id . '][allowAnonymous]"]' => ['checked' => FALSE],
            ],
          ],
        ];
      }
      $form['subsystems'][$term_id]['pageNavigation'] = [
        '#type' => 'select',
        '#title' => $this->t('Page Navigation Direction'),
        '#options' => [
          'left-to-right' => $this->t('Left-to-Right'),
          'right-to-left' => $this->t('Right-to-Left'),
        ],
        '#default_value' => $config->get('pageNavigation'),
      ];
      $form['subsystems'][$term_id]['hullConcavity'] = [
        '#type' => 'number',
        '#title' => $this->t('Hull Concavity'),
        '#description' => $this->t('A higher value creates a more detailed polygon. Set to 0 for a convex hull.'),
        '#default_value' => $config->get('hullConcavity'),
      ];

      $form['subsystems'][$term_id]['export_templates'] = [
        '#type' => 'details',
        '#title' => $this->t('Export Templates'),
        '#open' => FALSE,
      ];
      $form['subsystems'][$term_id]['export_templates']['tei'] = [
        '#type' => 'textarea',
        '#title' => $this->t('TEI/XML Template'),
        '#rows' => 15,
        '#default_value' => $config->get('export_templates.tei'),
      ];
      $form['subsystems'][$term_id]['export_templates']['rdf'] = [
        '#type' => 'textarea',
        '#title' => $this->t('RDF/XML Template'),
        '#rows' => 15,
        '#default_value' => $config->get('export_templates.rdf'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $subsystems_values = $form_state->getValue('subsystems');
    if (is_array($subsystems_values)) {
      foreach ($subsystems_values as $term_id => $values) {
        if (!is_numeric($term_id)) {
          continue;
        }
        // Resolve the group UUID from either entity autocomplete or raw text.
        $resolved_group_uuid = NULL;
        if ($this->entityTypeManager->hasDefinition('group') && isset($values['group_ref']) && $values['group_ref']) {
          $group_storage = $this->entityTypeManager->getStorage('group');
          $group = $group_storage->load($values['group_ref']);
          $resolved_group_uuid = $group ? $group->uuid() : NULL;
        }
        elseif (!empty($values['group_uuid'])) {
          $resolved_group_uuid = $values['group_uuid'];
        }

        $config_name = 'wdb_core.subsystem.' . $term_id;
        $this->config($config_name)
          ->set('display_title', $values['display_title'])
          ->set('display_title_link', $values['display_title_link'])
          ->set('allowAnonymous', $values['allowAnonymous'])
          ->set('group_uuid', $resolved_group_uuid ?: NULL)
          ->set('pageNavigation', $values['pageNavigation'])
          ->set('hullConcavity', $values['hullConcavity'])
          ->set('iiif_server_scheme', $values['iiif_settings']['iiif_server_scheme'])
          ->set('iiif_server_hostname', $values['iiif_settings']['iiif_server_hostname'])
          ->set('iiif_server_prefix', $values['iiif_settings']['iiif_server_prefix'])
          ->set('iiif_fileExt', $values['iiif_settings']['iiif_fileExt'])
          ->set('iiif_license', $values['iiif_settings']['iiif_license'])
          ->set('iiif_attribution', $values['iiif_settings']['iiif_attribution'])
          ->set('iiif_logo', $values['iiif_settings']['iiif_logo'])
          ->set('iiif_identifier_pattern', $values['iiif_settings']['iiif_identifier_pattern'])
          ->set('export_templates.tei', $values['export_templates']['tei'])
          ->set('export_templates.rdf', $values['export_templates']['rdf'])
          ->save();
      }
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $has_group = $this->entityTypeManager->hasDefinition('group');
    $subsystems_values = $form_state->getValue('subsystems') ?: [];

    foreach ($subsystems_values as $term_id => $values) {
      if (!is_numeric($term_id)) {
        continue;
      }
      // Skip validation when anonymous access is allowed.
      if (!empty($values['allowAnonymous'])) {
        continue;
      }

      if ($has_group && array_key_exists('group_ref', $values)) {
        if (!empty($values['group_ref'])) {
          $group = $this->entityTypeManager->getStorage('group')->load($values['group_ref']);
          if (!$group) {
            $form_state->setErrorByName("subsystems][$term_id][group_ref", $this->t('Selected Group does not exist.'));
          }
        }
      }
      elseif (!$has_group && !empty($values['group_uuid'])) {
        // When Group module is missing, we cannot fully validate the UUID,
        // but perform a basic format check.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $values['group_uuid'])) {
          $form_state->setErrorByName("subsystems][$term_id][group_uuid", $this->t('Please enter a valid UUID.'));
        }
      }
    }
  }

  /**
   * Submit handler for the "Apply pattern" button.
   */
  public function submitReapplyPattern(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $subsystem_id = $triggering_element['#subsystem_id'];

    $operations = [
      [
        '\Drupal\wdb_core\Form\WdbSettingsForm::batchProcessReapplyPattern',
        [$subsystem_id],
      ],
    ];

    $batch = [
      'title' => $this->t('Applying new identifier pattern...'),
      'operations' => $operations,
      'finished' => '\Drupal\wdb_core\Form\WdbSettingsForm::batchFinishedCallback',
    ];

    batch_set($batch);
  }

  /**
   * Batch API operation for reapplying the identifier pattern.
   */
  public static function batchProcessReapplyPattern($subsystem_id, &$context) {
    $entity_type_manager = \Drupal::entityTypeManager();
    $source_storage = $entity_type_manager->getStorage('wdb_source');
    $page_storage = $entity_type_manager->getStorage('wdb_annotation_page');

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $source_ids = $source_storage->getQuery()
        ->condition('subsystem_tags', $subsystem_id)
        ->accessCheck(FALSE)
        ->execute();

      $page_ids = [];
      if (!empty($source_ids)) {
        $page_ids = $page_storage->getQuery()
          ->condition('source_ref', $source_ids, 'IN')
          ->accessCheck(FALSE)
          ->execute();
      }

      $context['sandbox']['page_ids'] = array_values($page_ids);
      $context['sandbox']['max'] = count($page_ids);
      $context['results']['updated'] = 0;
    }

    $page_ids_chunk = array_slice($context['sandbox']['page_ids'], $context['sandbox']['progress'], 10);

    if (empty($page_ids_chunk)) {
      $context['finished'] = 1;
      return;
    }

    $pages_to_update = $page_storage->loadMultiple($page_ids_chunk);
    foreach ($pages_to_update as $page) {
      /** @var \Drupal\wdb_core\Entity\WdbAnnotationPage $page */
      // Regenerate the identifier from the pattern and persist it.
      $new_identifier = $page->getImageIdentifier(TRUE);
      $page->set('image_identifier', $new_identifier);
      $page->save();
      $context['results']['updated']++;
    }

    $context['sandbox']['progress'] += count($page_ids_chunk);
    $context['message'] = t('Updating page @progress of @total...', [
      '@progress' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['max'],
    ]);

    if ($context['sandbox']['progress'] >= $context['sandbox']['max']) {
      $context['finished'] = 1;
    }
    else {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch API finished callback.
   */
  public static function batchFinishedCallback($success, $results, $operations) {
    $messenger = \Drupal::messenger();
    if ($success) {
      $updated_count = $results['updated'] ?? 0;
      $messenger->addStatus(\Drupal::translation()->formatPlural(
        $updated_count,
        'Successfully updated 1 annotation page.',
        'Successfully updated @count annotation pages.'
      ));
    }
    else {
      $messenger->addError(t('An error occurred during the update process.'));
    }
  }

}
