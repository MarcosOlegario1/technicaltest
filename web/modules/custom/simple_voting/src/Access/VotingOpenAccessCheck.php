<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\simple_voting\VotingManager;

/**
 * Blocks the CMS voting routes while voting is globally disabled.
 *
 * Used through the '_simple_voting_voting_open' route requirement.
 */
class VotingOpenAccessCheck implements AccessInterface {

  public function __construct(
    protected readonly VotingManager $votingManager,
  ) {}

  /**
   * Checks that voting is open.
   */
  public function access(): AccessResultInterface {
    return AccessResult::allowedIf($this->votingManager->isVotingEnabled())
      ->addCacheTags(['config:' . VotingManager::SETTINGS]);
  }

}
