<?php

namespace Drupal\wdb_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Controller for the WDB administration dashboard.
 *
 * Provides a central page with links to all administrative sections of the
 * WDB Core module, including content management, tools, and configuration.
 */
class DashboardController extends ControllerBase {

  /**
   * Builds the WDB dashboard page.
   *
   * @return array
   *   A render array for the dashboard page.
   */
  public function build() {
    $build = [];

    // --- Content Management ---
    $build['content_header'] = [
      '#markup' => '<h2>' . $this->t('Content Management') . '</h2>',
    ];
    $build['entity_links'] = [
      '#theme' => 'item_list',
      '#items' => [
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Sources'), Url::fromRoute('entity.wdb_source.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Annotation Pages'), Url::fromRoute('entity.wdb_annotation_page.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Labels (Annotation Regions)'), Url::fromRoute('entity.wdb_label.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Signs'), Url::fromRoute('entity.wdb_sign.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Sign Functions'), Url::fromRoute('entity.wdb_sign_function.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Sign Interpretations'), Url::fromRoute('entity.wdb_sign_interpretation.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Word Maps'), Url::fromRoute('entity.wdb_word_map.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Word Units'), Url::fromRoute('entity.wdb_word_unit.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Word Meanings'), Url::fromRoute('entity.wdb_word_meaning.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage Words (Lemmas)'), Url::fromRoute('entity.wdb_word.collection'))->toString()],
      ],
    ];

    // --- Tools & Utilities ---
    $build['tools_header'] = [
      '#markup' => '<h2>' . $this->t('Tools & Utilities') . '</h2>',
    ];
    $build['tools_links'] = [
      '#theme' => 'item_list',
      '#items' => [
        ['#markup' => Link::fromTextAndUrl($this->t('Generate Import Template'), Url::fromRoute('wdb_core.template_generator_form'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Import Linguistic Data'), Url::fromRoute('wdb_core.data_import_form'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Import Log'), Url::fromRoute('entity.wdb_import_log.collection'))->toString()],
        ['#markup' => Link::fromTextAndUrl($this->t('Manage POS Mappings'), Url::fromRoute('entity.wdb_pos_mapping.collection'))->toString()],
      ],
    ];

    // --- Configuration ---
    $build['config_header'] = [
      '#markup' => '<h2>' . $this->t('Configuration') . '</h2>',
    ];

    $build['config_links'] = [
      '#theme' => 'item_list',
      '#items' => [
        ['#markup' => Link::fromTextAndUrl($this->t('Module Settings'), Url::fromRoute('wdb_core.settings_form'))->toString()],
      ],
    ];
    return $build;
  }

}
