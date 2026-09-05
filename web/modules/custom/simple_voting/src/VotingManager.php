<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_voting\Entity\VoteInterface;
use Drupal\simple_voting\Entity\VotingOptionInterface;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\Exception\DuplicateVoteException;
use Drupal\simple_voting\Exception\InvalidVoteException;
use Drupal\simple_voting\Exception\VoteRateLimitException;
use Drupal\simple_voting\Exception\VotingClosedException;
use Drupal\simple_voting\Exception\VotingException;
use Psr\Log\LoggerInterface;

/**
 * Central place for the voting rules shared by the CMS and the API.
 */
class VotingManager {

  public const SETTINGS = 'simple_voting.settings';

  /**
   * Flood event name and limits for vote submissions.
   */
  private const FLOOD_EVENT = 'simple_voting.vote';
  private const FLOOD_THRESHOLD = 50;
  private const FLOOD_WINDOW = 3600;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    protected readonly FloodInterface $flood,
    protected readonly CacheBackendInterface $cache,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
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
   * Covers the global switch, the question state and the permission. It does
   * not check whether the account has already voted.
   *
   * @param \Drupal\simple_voting\Entity\VotingQuestionInterface $question
   *   The question being voted on.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account trying to vote.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, carrying the cacheability of what it looked at.
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

  /**
   * Tells whether an account has already voted on a question.
   */
  public function hasVoted(VotingQuestionInterface $question, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }

    return (bool) $this->voteStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question->id())
      ->condition('voter', $account->id())
      ->count()
      ->execute();
  }

  /**
   * Returns the vote an account cast on a question, if any.
   */
  public function getUserVote(VotingQuestionInterface $question, AccountInterface $account): ?VoteInterface {
    if ($account->isAnonymous()) {
      return NULL;
    }

    $ids = $this->voteStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question->id())
      ->condition('voter', $account->id())
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    /** @var \Drupal\simple_voting\Entity\VoteInterface $vote */
    $vote = $this->voteStorage()->load(reset($ids));
    return $vote;
  }

  /**
   * Records a vote.
   *
   * @param \Drupal\simple_voting\Entity\VotingQuestionInterface $question
   *   The question being voted on.
   * @param \Drupal\simple_voting\Entity\VotingOptionInterface $option
   *   The chosen option. It must belong to the question.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The voter.
   *
   * @return \Drupal\simple_voting\Entity\VoteInterface
   *   The stored vote.
   *
   * @throws \Drupal\simple_voting\Exception\VotingClosedException
   *   If voting is globally disabled.
   * @throws \Drupal\simple_voting\Exception\InvalidVoteException
   *   If the option does not belong to the question.
   * @throws \Drupal\simple_voting\Exception\VoteRateLimitException
   *   If the account is voting faster than the allowed rate.
   * @throws \Drupal\simple_voting\Exception\DuplicateVoteException
   *   If the account has already voted on the question.
   * @throws \Drupal\simple_voting\Exception\VotingException
   *   If the vote could not be stored for any other reason.
   */
  public function recordVote(VotingQuestionInterface $question, VotingOptionInterface $option, AccountInterface $account): VoteInterface {
    if (!$this->isVotingEnabled()) {
      throw new VotingClosedException('Voting is currently disabled.');
    }

    if ($option->getQuestionId() !== (int) $question->id()) {
      throw new InvalidVoteException('The selected option does not belong to this question.');
    }

    $flood_identifier = 'user:' . $account->id();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW, $flood_identifier)) {
      $this->logger->warning('Vote rejected for user @user on question @question: rate limit reached.', [
        '@user' => $account->id(),
        '@question' => $question->getIdentifier(),
      ]);
      throw new VoteRateLimitException('Too many votes in a short time. Please try again later.');
    }

    // Cheap pre-check so the common duplicate case never opens a transaction.
    if ($this->hasVoted($question, $account)) {
      throw new DuplicateVoteException('You have already voted on this question.');
    }

    /** @var \Drupal\simple_voting\Entity\VoteInterface $vote */
    $vote = $this->voteStorage()->create([
      'question' => $question->id(),
      'answer_option' => $option->id(),
      'voter' => $account->id(),
    ]);

    $transaction = $this->database->startTransaction();
    try {
      $vote->save();
    }
    catch (\Exception $e) {
      $transaction->rollBack();

      // Entity storage wraps the driver exception, so check both levels. A
      // unique key hit here means a concurrent request for the same user won
      // the race.
      $is_duplicate = $e instanceof IntegrityConstraintViolationException
        || $e->getPrevious() instanceof IntegrityConstraintViolationException;

      if ($is_duplicate) {
        $this->logger->notice('Concurrent duplicate vote blocked for user @user on question @question.', [
          '@user' => $account->id(),
          '@question' => $question->getIdentifier(),
        ]);
        throw new DuplicateVoteException('You have already voted on this question.', 0, $e);
      }

      $this->logger->error('Could not record vote for user @user on question @question: @message', [
        '@user' => $account->id(),
        '@question' => $question->getIdentifier(),
        '@message' => $e->getMessage(),
      ]);
      throw new VotingException('The vote could not be saved.', 0, $e);
    }
    unset($transaction);

    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $flood_identifier);
    $this->cacheTagsInvalidator->invalidateTags([$this->resultsCacheTag((int) $question->id())]);

    $this->logger->info('Vote @vote recorded: user @user chose option @option on question @question.', [
      '@vote' => $vote->id(),
      '@user' => $account->id(),
      '@option' => $option->id(),
      '@question' => $question->getIdentifier(),
    ]);

    return $vote;
  }

  /**
   * Returns the aggregated results for a question.
   *
   * The payload is small and tag-cached so repeated reads stay cheap under
   * load. Every option of the question is present, including those with no
   * votes yet.
   *
   * @param \Drupal\simple_voting\Entity\VotingQuestionInterface $question
   *   The question to summarise.
   *
   * @return array
   *   An array with 'question_id', 'total' and an 'options' list, each item
   *   holding 'id', 'title', 'count' and 'percentage'.
   */
  public function getResults(VotingQuestionInterface $question): array {
    $question_id = (int) $question->id();
    $cid = 'simple_voting:results:' . $question_id;

    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    $option_storage = $this->entityTypeManager->getStorage('voting_option');
    $option_ids = $option_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question_id)
      ->sort('weight')
      ->sort('id')
      ->execute();
    $options = $option_storage->loadMultiple($option_ids);

    $counts = [];
    if ($options) {
      $query = $this->database->select('vote', 'v');
      $query->addField('v', 'answer_option', 'option_id');
      $query->addExpression('COUNT(*)', 'total');
      $query->condition('v.question', $question_id);
      $query->groupBy('v.answer_option');
      $counts = $query->execute()->fetchAllKeyed();
    }

    $total = array_sum(array_map('intval', $counts));
    $result_options = [];
    foreach ($options as $option) {
      /** @var \Drupal\simple_voting\Entity\VotingOptionInterface $option */
      $count = (int) ($counts[$option->id()] ?? 0);
      $result_options[] = [
        'id' => (int) $option->id(),
        'title' => $option->getTitle(),
        'count' => $count,
        'percentage' => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
      ];
    }

    $data = [
      'question_id' => $question_id,
      'total' => $total,
      'options' => $result_options,
    ];

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, [
      $this->resultsCacheTag($question_id),
      'voting_option_list',
    ]);

    return $data;
  }

  /**
   * Returns the cache tag that covers a question's results.
   */
  public function resultsCacheTag(int $question_id): string {
    return 'simple_voting:results:' . $question_id;
  }

  /**
   * Returns the vote entity storage.
   */
  private function voteStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('vote');
  }

}
