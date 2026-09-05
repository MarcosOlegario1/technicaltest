<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Controller;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\VoteWidgetBuilder;
use Drupal\simple_voting\VotingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the in-CMS voting pages.
 */
class VotingController extends ControllerBase {

  public function __construct(
    protected readonly VotingManager $votingManager,
    protected readonly VoteWidgetBuilder $voteWidgetBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_voting.manager'),
      $container->get('simple_voting.vote_widget_builder'),
    );
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
    $build = [
      '#cache' => [
        'tags' => Cache::mergeTags($voting_question->getCacheTags(), ['config:' . VotingManager::SETTINGS]),
        'contexts' => ['user'],
      ],
    ];

    $build['content'] = $this->voteWidgetBuilder->build($voting_question, $this->currentUser());
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
   * Access for the admin results page.
   */
  public function adminResultsAccess(VotingQuestionInterface $voting_question): AccessResultInterface {
    return $voting_question->access('update', $this->currentUser(), TRUE);
  }

}
