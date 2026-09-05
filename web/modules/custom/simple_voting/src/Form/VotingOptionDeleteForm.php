<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Delete form for answer options.
 *
 * Blocks deletion once an option has votes, so recorded results stay intact,
 * and returns the user to the parent question's option list.
 */
class VotingOptionDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $votes = $this->voteCount();
    if ($votes > 0) {
      $form['locked'] = [
        '#markup' => $this->t('This option already has @count vote(s) and cannot be deleted. Delete the whole question if you need to remove it.', [
          '@count' => $votes,
        ]),
      ];
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->getRedirectUrl();
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(): Url {
    /** @var \Drupal\simple_voting\Entity\VotingOptionInterface $option */
    $option = $this->entity;
    $question_id = $option->getQuestionId();

    if ($question_id !== NULL) {
      return Url::fromRoute('simple_voting.question.options', [
        'voting_question' => $question_id,
      ]);
    }

    return Url::fromRoute('entity.voting_question.collection');
  }

  /**
   * Counts the votes cast for the option being deleted.
   */
  private function voteCount(): int {
    return (int) $this->entityTypeManager->getStorage('vote')->getQuery()
      ->accessCheck(FALSE)
      ->condition('answer_option', $this->entity->id())
      ->count()
      ->execute();
  }

}
