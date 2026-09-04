<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Global settings for the simple voting system.
 */
class SettingsForm extends ConfigFormBase {

  private const SETTINGS = 'simple_voting.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_voting_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['voting_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable voting'),
      '#description' => $this->t('When unchecked, the whole voting flow is turned off, in the CMS and through the external API. Administrators can still manage questions and options.'),
      '#default_value' => $this->config(self::SETTINGS)->get('voting_enabled'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('voting_enabled', (bool) $form_state->getValue('voting_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
