<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add and edit form for voting questions.
 */
class VotingQuestionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface $question */
    $question = $this->entity;

    $form['identifier'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Identifier'),
      '#default_value' => $question->getIdentifier(),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#disabled' => !$question->isNew(),
      '#description' => $this->t('Unique name used by the external API. It cannot be changed once the question is saved.'),
      '#machine_name' => [
        'exists' => [$this, 'identifierExists'],
        'source' => ['question', 'widget', 0, 'value'],
        'replace_pattern' => '[^a-z0-9_]+',
        'replace' => '_',
      ],
      '#weight' => -5,
    ];

    return $form;
  }

  /**
   * Checks whether a question already uses the given identifier.
   *
   * @param string $identifier
   *   The candidate identifier.
   *
   * @return bool
   *   TRUE if the identifier is already taken.
   */
  public function identifierExists(string $identifier): bool {
    $query = $this->entityTypeManager->getStorage('voting_question')->getQuery()
      ->accessCheck(FALSE)
      ->condition('identifier', $identifier)
      ->range(0, 1);

    if (!$this->entity->isNew()) {
      $query->condition('id', $this->entity->id(), '<>');
    }

    return (bool) $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state): EntityInterface {
    /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface $entity */
    $entity = parent::buildEntity($form, $form_state);
    $entity->setIdentifier((string) $form_state->getValue('identifier'));
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('The question %label has been saved.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $result;
  }

}
