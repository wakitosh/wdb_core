<?php

namespace Drupal\wdb_cantaloupe_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the Cantaloupe auth settings.
 */
class TokenSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['wdb_cantaloupe_auth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wdb_cantaloupe_auth_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wdb_cantaloupe_auth.settings');

    $form['token_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Token lifetime (seconds)'),
      '#description' => $this->t('How long issued IIIF tokens remain valid after creation.'),
      '#default_value' => $config->get('token_ttl') ?? 600,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['token_param'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Query parameter name'),
      '#description' => $this->t('Parameter appended to IIIF URLs (defaults to wdb_token).'),
      '#default_value' => $config->get('token_param') ?: 'wdb_token',
      '#maxlength' => 64,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $ttl = (int) $form_state->getValue('token_ttl');
    if ($ttl <= 0) {
      $form_state->setErrorByName('token_ttl', $this->t('Please enter a positive value for the token lifetime.'));
    }

    $param = trim((string) $form_state->getValue('token_param'));
    if ($param === '') {
      $form_state->setValue('token_param', 'wdb_token');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('wdb_cantaloupe_auth.settings')
      ->set('token_ttl', (int) $form_state->getValue('token_ttl'))
      ->set('token_param', trim((string) $form_state->getValue('token_param')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
