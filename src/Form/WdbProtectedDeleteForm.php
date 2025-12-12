<?php

namespace Drupal\wdb_core\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A delete form that blocks deletion when referenced, with friendly message.
 */
class WdbProtectedDeleteForm extends ContentEntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $label = $this->entity->label();
    return new TranslatableMarkup('Are you sure you want to delete %label?', ['%label' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return new TranslatableMarkup('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $entity = $this->entity;
    if (!$entity instanceof EntityInterface) {
      return;
    }

    $messages = $this->findReferencesMessages($entity);
    if ($messages) {
      // Show a user-friendly error summary instead of a 500 error.
      $message = new TranslatableMarkup('This item cannot be deleted because it is referenced by other content: @details', [
        '@details' => implode('; ', $messages),
      ]);
      $form_state->setErrorByName('confirm', $message);
      $this->messenger()->addError($message);
    }
  }

  /**
   * Find reference messages by entity type.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being deleted.
   *
   * @return string[]
   *   List of human-readable reasons blocking deletion.
   */
  protected function findReferencesMessages(EntityInterface $entity): array {
    $etm = \Drupal::entityTypeManager();
    $id = (int) $entity->id();
    $type = $entity->getEntityTypeId();
    $reasons = [];

    switch ($type) {
      case 'wdb_source':
        if ($this->hasAny($etm, 'wdb_annotation_page', 'source_ref', $id)) {
          $reasons[] = 'WDB Annotation Page exists';
        }
        if ($this->hasAny($etm, 'wdb_word_unit', 'source_ref', $id)) {
          $reasons[] = 'WDB Word Unit exists';
        }
        break;

      case 'wdb_annotation_page':
        if ($this->hasAny($etm, 'wdb_label', 'annotation_page_ref', $id)) {
          $reasons[] = 'WDB Label exists';
        }
        if ($this->hasAny($etm, 'wdb_sign_interpretation', 'annotation_page_ref', $id)) {
          $reasons[] = 'WDB Sign Interpretation exists';
        }
        if ($this->hasAny($etm, 'wdb_word_unit', 'annotation_page_refs.target_id', $id)) {
          $reasons[] = 'WDB Word Unit exists';
        }
        break;

      case 'wdb_sign':
        if ($this->hasAny($etm, 'wdb_sign_function', 'sign_ref', $id)) {
          $reasons[] = 'WDB Sign Function exists';
        }
        break;

      case 'wdb_sign_function':
        if ($this->hasAny($etm, 'wdb_sign_interpretation', 'sign_function_ref', $id)) {
          $reasons[] = 'WDB Sign Interpretation exists';
        }
        break;

      case 'wdb_label':
        if ($this->hasAny($etm, 'wdb_sign_interpretation', 'label_ref', $id)) {
          $reasons[] = 'WDB Sign Interpretation exists';
        }
        break;

      case 'wdb_word_meaning':
        if ($this->hasAny($etm, 'wdb_word_unit', 'word_meaning_ref', $id)) {
          $reasons[] = 'WDB Word Unit exists';
        }
        break;

      case 'wdb_word':
        if ($this->hasAny($etm, 'wdb_word_meaning', 'word_ref', $id)) {
          $reasons[] = 'WDB Word Meaning exists';
        }
        break;
    }

    return $reasons;
  }

  /**
   * Helper: quickly check existence of referencing entities.
   */
  private function hasAny($etm, string $type, string $field, int $id): bool {
    return (bool) $etm->getStorage($type)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $id)
      ->range(0, 1)
      ->execute();
  }

}
