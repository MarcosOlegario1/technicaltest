<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Psr\Log\LoggerInterface;

/**
 * Central place for the voting rules shared by the CMS and the API.
 */
class VotingManager {

  public const SETTINGS = 'simple_voting.settings';

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Tells whether voting is currently open.
   */
  public function isVotingEnabled(): bool {
    return (bool) $this->configFactory->get(self::SETTINGS)->get('voting_enabled');
  }

  /**
   * Checks whether an account may cast a vote on a question.
   *
   * This covers the global switch, the question state and the permission. It
   * does not check whether the account has already voted; callers that need
   * that should ask for it explicitly.
   *
   * @param \Drupal\simple_voting\Entity\VotingQuestionInterface $question
   *   The question being voted on.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account trying to vote.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, with the cacheability of everything it looked at.
   */
  public function checkVotingAccess(VotingQuestionInterface $question, AccountInterface $account): AccessResultInterface {
    $settings = $this->configFactory->get(self::SETTINGS);

    if (!$this->isVotingEnabled()) {
      return AccessResult::forbidden('Voting is disabled.')
        ->addCacheableDependency($settings);
    }

    if (!$question->isPublished()) {
      return AccessResult::forbidden('The question is not published.')
        ->addCacheableDependency($question);
    }

    return AccessResult::allowedIfHasPermission($account, 'vote in simple voting')
      ->addCacheableDependency($settings)
      ->addCacheableDependency($question);
  }

}
