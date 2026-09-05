<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines a voting question: a prompt people can vote on.
 */
interface VotingQuestionInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, EntityOwnerInterface {

  /**
   * Returns the human readable question text.
   */
  public function getQuestion(): string;

  /**
   * Returns the unique machine identifier used by the external API.
   */
  public function getIdentifier(): string;

  /**
   * Sets the unique machine identifier.
   */
  public function setIdentifier(string $identifier): self;

  /**
   * Tells whether the vote totals should be shown to voters after they vote.
   */
  public function showResults(): bool;

  /**
   * Sets whether the vote totals are shown to voters after they vote.
   */
  public function setShowResults(bool $show): self;

  /**
   * Returns the creation timestamp.
   */
  public function getCreatedTime(): int;

}
