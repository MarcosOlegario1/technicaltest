<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines a single vote cast by a user on a question.
 */
interface VoteInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Returns the question this vote was cast on.
   */
  public function getQuestion(): ?VotingQuestionInterface;

  /**
   * Returns the question id.
   */
  public function getQuestionId(): ?int;

  /**
   * Returns the chosen answer option.
   */
  public function getAnswerOption(): ?VotingOptionInterface;

  /**
   * Returns the chosen answer option id.
   */
  public function getAnswerOptionId(): ?int;

  /**
   * Returns the creation timestamp.
   */
  public function getCreatedTime(): int;

}
