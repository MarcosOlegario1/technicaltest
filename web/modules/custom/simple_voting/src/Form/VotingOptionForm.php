<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\simple_voting\Entity\VotingQuestionInterface;

/**
 * Add and edit form for answer options.
 */
class VotingOptionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['question_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Question'),
      '#plain_text' => $this->getQuestion()->label(),
      '#weight' => -20,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state): EntityInterface {
    /** @var \Drupal\simple_voting\Entity\VotingOptionInterface $entity */
    $entity = parent::buildEntity($form, $form_state);
    if ($entity->isNew()) {
      $entity->set('question', $this->getQuestion()->id());
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('The option %label has been saved.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl(Url::fromRoute('simple_voting.question.options', [
      'voting_question' => $this->getQuestion()->id(),
    ]));

    return $result;
  }

  /**
   * Returns the question this option belongs to.
   *
   * On the add form it comes from the route, on the edit form from the entity.
   */
  protected function getQuestion(): VotingQuestionInterface {
    $question = $this->getRouteMatch()->getParameter('voting_question');
    if ($question instanceof VotingQuestionInterface) {
      return $question;
    }

    /** @var \Drupal\simple_voting\Entity\VotingOptionInterface $option */
    $option = $this->entity;
    return $option->getQuestion();
  }

}
