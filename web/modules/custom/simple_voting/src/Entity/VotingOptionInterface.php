<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines an answer option that belongs to a voting question.
 */
interface VotingOptionInterface extends ContentEntityInterface {

  /**
   * Returns the parent question, or NULL if it is gone.
   */
  public function getQuestion(): ?VotingQuestionInterface;

  /**
   * Returns the parent question id.
   */
  public function getQuestionId(): ?int;

  /**
   * Returns the option title.
   */
  public function getTitle(): string;

  /**
   * Returns the short description.
   */
  public function getDescription(): string;

  /**
   * Returns the sort weight.
   */
  public function getWeight(): int;

}
