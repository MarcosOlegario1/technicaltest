<?php

declare(strict_types=1);

namespace Drupal\simple_voting_api\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\simple_voting\Entity\VotingOptionInterface;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\Exception\DuplicateVoteException;
use Drupal\simple_voting\Exception\InvalidVoteException;
use Drupal\simple_voting\Exception\VoteRateLimitException;
use Drupal\simple_voting\Exception\VotingClosedException;
use Drupal\simple_voting\Exception\VotingException;
use Drupal\simple_voting\VotingManager;
use Drupal\simple_voting_api\ApiResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hand-built endpoints for the external voting application.
 */
class VotingApiController extends ControllerBase {

  public function __construct(
    protected readonly VotingManager $votingManager,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_voting.manager'),
      $container->get('file_url_generator'),
      $container->get('logger.channel.simple_voting'),
    );
  }

  /**
   * GET /api/voting/questions — the questions open for voting.
   */
  public function listQuestions(): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();

    $data = [];
    foreach ($storage->loadMultiple($ids) as $question) {
      /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface $question */
      $data[] = [
        'identifier' => $question->getIdentifier(),
        'question' => $question->getQuestion(),
        'show_results' => $question->showResults(),
      ];
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['voting_question_list', 'config:' . VotingManager::SETTINGS])
      ->addCacheContexts(['user.permissions']);

    return $this->cacheableJson(['questions' => $data], $cacheability);
  }

  /**
   * GET /api/voting/questions/{identifier} — one question with its options.
   */
  public function getQuestion(string $identifier): JsonResponse {
    $question = $this->loadQuestion($identifier);
    if ($question === NULL) {
      return $this->questionNotFound($identifier);
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($question)
      ->addCacheTags(['voting_option_list', 'config:' . VotingManager::SETTINGS])
      ->addCacheContexts(['user.permissions']);

    return $this->cacheableJson($this->serializeQuestion($question, TRUE), $cacheability);
  }

  /**
   * POST /api/voting/questions/{identifier}/vote — register a vote.
   */
  public function vote(string $identifier, Request $request): JsonResponse {
    $account = $this->currentUser();
    if (!$account->isAuthenticated()) {
      return ApiResponse::error('authentication_required', 'You must be authenticated to vote.', 403);
    }

    $question = $this->loadQuestion($identifier);
    if ($question === NULL) {
      return $this->questionNotFound($identifier);
    }

    $payload = json_decode($request->getContent() ?: '', TRUE);
    if (!is_array($payload) || !isset($payload['option'])) {
      return ApiResponse::error('invalid_payload', 'The request body must be a JSON object with an "option" id.', 400);
    }

    $option = $this->entityTypeManager()->getStorage('voting_option')->load($payload['option']);
    if (!$option instanceof VotingOptionInterface) {
      return ApiResponse::error('option_not_found', 'The selected option does not exist.', 404);
    }

    try {
      $vote = $this->votingManager->recordVote($question, $option, $account);
    }
    catch (VotingClosedException) {
      return ApiResponse::error('voting_closed', 'Voting is currently disabled.', 403);
    }
    catch (DuplicateVoteException) {
      $this->logger->info('API: user @uid tried to vote twice on @question.', [
        '@uid' => $account->id(),
        '@question' => $identifier,
      ]);
      return ApiResponse::error('already_voted', 'You have already voted on this question.', 409);
    }
    catch (InvalidVoteException) {
      return ApiResponse::error('invalid_option', 'The selected option does not belong to this question.', 422);
    }
    catch (VoteRateLimitException) {
      return ApiResponse::error('rate_limited', 'Too many votes in a short time. Try again later.', 429);
    }
    catch (VotingException) {
      return ApiResponse::error('vote_failed', 'The vote could not be saved.', 500);
    }

    $data = [
      'recorded' => TRUE,
      'vote_id' => (int) $vote->id(),
      'question' => $identifier,
      'option' => (int) $option->id(),
    ];
    if ($question->showResults()) {
      $data['results'] = $this->votingManager->getResults($question);
    }

    return ApiResponse::ok($data, 201);
  }

  /**
   * GET /api/voting/questions/{identifier}/results — the tally, if visible.
   */
  public function results(string $identifier): JsonResponse {
    $question = $this->loadQuestion($identifier);
    if ($question === NULL) {
      return $this->questionNotFound($identifier);
    }

    $account = $this->currentUser();
    $may_override = $account->hasPermission('access simple voting results');

    if (!$question->showResults() && !$may_override) {
      return ApiResponse::error('results_hidden', 'Results are not public for this question.', 403);
    }
    if (!$may_override && !$this->votingManager->hasVoted($question, $account)) {
      return ApiResponse::error('vote_required', 'You need to vote before seeing the results.', 403);
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheTags([
        $this->votingManager->resultsCacheTag((int) $question->id()),
        'voting_option_list',
        'config:' . VotingManager::SETTINGS,
      ])
      ->addCacheContexts(['user']);

    return $this->cacheableJson($this->votingManager->getResults($question), $cacheability);
  }

  /**
   * Loads a published question by its identifier.
   */
  private function loadQuestion(string $identifier): ?VotingQuestionInterface {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('identifier', $identifier)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    $question = $storage->load(reset($ids));
    if (!$question instanceof VotingQuestionInterface || !$question->isPublished()) {
      return NULL;
    }

    return $question;
  }

  /**
   * Builds the array representation of a question.
   */
  private function serializeQuestion(VotingQuestionInterface $question, bool $with_options): array {
    $data = [
      'identifier' => $question->getIdentifier(),
      'question' => $question->getQuestion(),
      'show_results' => $question->showResults(),
    ];

    if (!$with_options) {
      return $data;
    }

    $option_storage = $this->entityTypeManager()->getStorage('voting_option');
    $option_ids = $option_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    $data['options'] = [];
    foreach ($option_storage->loadMultiple($option_ids) as $option) {
      /** @var \Drupal\simple_voting\Entity\VotingOptionInterface $option */
      $data['options'][] = [
        'id' => (int) $option->id(),
        'title' => $option->getTitle(),
        'description' => $option->getDescription(),
        'image' => $this->optionImageUrl($option),
      ];
    }

    return $data;
  }

  /**
   * Returns the absolute URL of an option image, or NULL.
   */
  private function optionImageUrl(VotingOptionInterface $option): ?string {
    $item = $option->get('image');
    if ($item->isEmpty() || $item->entity === NULL) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateAbsoluteString($item->entity->getFileUri());
  }

  /**
   * Standard 404 for an unknown identifier.
   */
  private function questionNotFound(string $identifier): JsonResponse {
    return ApiResponse::error('question_not_found', sprintf('No question with identifier "%s".', $identifier), 404);
  }

  /**
   * Wraps a data array in a cacheable JSON response.
   */
  private function cacheableJson(array $data, CacheableMetadata $cacheability): CacheableJsonResponse {
    $response = new CacheableJsonResponse(['data' => $data]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
