<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Url;

/**
 * Delete form for answer options.
 *
 * Sends the user back to the parent question's option list.
 */
class VotingOptionDeleteForm extends ContentEntityDeleteForm {

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

}
