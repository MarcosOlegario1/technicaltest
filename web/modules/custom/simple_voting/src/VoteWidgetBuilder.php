<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\Form\VoteForm;

/**
 * Builds the vote-or-results render array for a question.
 *
 * Both the in-CMS voting page
 * (\Drupal\simple_voting\Controller\VotingController) and the "Voting
 * question" block use this, so a visitor sees the same behaviour regardless
 * of where the question is embedded: the vote form if they can still vote,
 * the tally (or a bare confirmation) once they have voted, or an access
 * message otherwise.
 */
class VoteWidgetBuilder {

  use StringTranslationTrait;

  public function __construct(
    protected readonly VotingManager $votingManager,
    protected readonly FormBuilderInterface $formBuilder,
  ) {}

  /**
   * Builds the widget for one question and viewer.
   */
  public function build(VotingQuestionInterface $question, AccountInterface $account): array {
    if ($this->votingManager->hasVoted($question, $account)) {
      return $this->buildResults($question, $account);
    }

    $access = $this->votingManager->checkVotingAccess($question, $account);
    if (!$access->isAllowed()) {
      $build = [
        '#markup' => '<p>' . $this->t('Voting on this question is not available.') . '</p>',
      ];
      CacheableMetadata::createFromRenderArray($build)
        ->addCacheableDependency($access)
        ->applyTo($build);
      return $build;
    }

    $build = $this->formBuilder->getForm(VoteForm::class, $question);
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($access)
      ->applyTo($build);
    return $build;
  }

  /**
   * Builds the results view, or a bare confirmation when results are hidden.
   */
  protected function buildResults(VotingQuestionInterface $question, AccountInterface $account): array {
    $results_tag = $this->votingManager->resultsCacheTag((int) $question->id());

    if (!$question->showResults() && !$account->hasPermission('access simple voting results')) {
      return [
        '#markup' => '<p>' . $this->t('Your vote has been recorded. Thank you.') . '</p>',
        '#cache' => [
          'tags' => [$results_tag],
          'contexts' => ['user.permissions'],
        ],
      ];
    }

    $user_vote = $this->votingManager->getUserVote($question, $account);

    return [
      '#theme' => 'simple_voting_results',
      '#question' => $question,
      '#results' => $this->votingManager->getResults($question),
      '#user_choice' => $user_vote?->getAnswerOptionId(),
      '#cache' => [
        'tags' => Cache::mergeTags([$results_tag], ['voting_option_list']),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
