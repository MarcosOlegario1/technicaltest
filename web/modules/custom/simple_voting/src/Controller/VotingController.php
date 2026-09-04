<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Controller;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\Form\VoteForm;
use Drupal\simple_voting\VotingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the in-CMS voting pages.
 */
class VotingController extends ControllerBase {

  public function __construct(
    protected readonly VotingManager $votingManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('simple_voting.manager'));
  }

  /**
   * Lists the questions that are open for voting.
   */
  public function overview(): array {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($ids) as $question) {
      /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface $question */
      $items[] = [
        '#type' => 'link',
        '#title' => $question->getQuestion(),
        '#url' => Url::fromRoute('simple_voting.question', [
          'voting_question' => $question->id(),
        ]),
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#empty' => $this->t('There are no questions to vote on right now.'),
      '#cache' => [
        'tags' => ['voting_question_list', 'config:' . VotingManager::SETTINGS],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Page title for a single question.
   */
  public function questionTitle(VotingQuestionInterface $voting_question): string {
    return $voting_question->getQuestion();
  }

  /**
   * Shows the vote form, or the results once the user has voted.
   */
  public function question(VotingQuestionInterface $voting_question): array {
    $account = $this->currentUser();

    $build = [
      '#cache' => [
        'tags' => Cache::mergeTags($voting_question->getCacheTags(), ['config:' . VotingManager::SETTINGS]),
        'contexts' => ['user'],
      ],
    ];

    if ($this->votingManager->hasVoted($voting_question, $account)) {
      $build['content'] = $this->buildVotedView($voting_question, $account);
      return $build;
    }

    $access = $this->votingManager->checkVotingAccess($voting_question, $account);
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($access)
      ->applyTo($build);

    if (!$access->isAllowed()) {
      $build['content'] = [
        '#markup' => '<p>' . $this->t('Voting on this question is not available.') . '</p>',
      ];
      return $build;
    }

    $build['content'] = $this->formBuilder()->getForm(VoteForm::class, $voting_question);
    return $build;
  }

  /**
   * Admin results page: always shows the tally regardless of the question flag.
   */
  public function adminResults(VotingQuestionInterface $voting_question): array {
    return [
      '#theme' => 'simple_voting_results',
      '#question' => $voting_question,
      '#results' => $this->votingManager->getResults($voting_question),
      '#cache' => [
        'tags' => Cache::mergeTags(
          $voting_question->getCacheTags(),
          [$this->votingManager->resultsCacheTag((int) $voting_question->id()), 'voting_option_list'],
        ),
      ],
    ];
  }

  /**
   * Builds what a user sees after they have voted.
   */
  protected function buildVotedView(VotingQuestionInterface $question, AccountInterface $account): array {
    $results_tag = $this->votingManager->resultsCacheTag((int) $question->id());

    if (!$question->showResults() && !$account->hasPermission('access simple voting results')) {
      return [
        '#markup' => '<p>' . $this->t('Your vote has been recorded. Thank you.') . '</p>',
        '#cache' => ['tags' => [$results_tag]],
      ];
    }

    $user_vote = $this->votingManager->getUserVote($question, $account);

    return [
      '#theme' => 'simple_voting_results',
      '#question' => $question,
      '#results' => $this->votingManager->getResults($question),
      '#user_choice' => $user_vote?->getAnswerOptionId(),
      '#cache' => ['tags' => [$results_tag, 'voting_option_list']],
    ];
  }

  /**
   * Access for the admin results page.
   */
  public function adminResultsAccess(VotingQuestionInterface $voting_question): AccessResultInterface {
    return $voting_question->access('update', $this->currentUser(), TRUE);
  }

}
