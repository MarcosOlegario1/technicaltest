<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\VoteWidgetBuilder;
use Drupal\simple_voting\VotingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Embeds one voting question, with its vote form or results, on any page.
 *
 * This is how a question reaches the Drupal front end outside the dedicated
 * /voting pages: place the block through Structure > Block layout, or, from
 * a Twig template, render it directly with
 * {{ drupal_block('simple_voting_question:' ~ block_id) }}.
 */
#[Block(
  id: 'simple_voting_question',
  admin_label: new TranslatableMarkup('Voting question'),
  category: new TranslatableMarkup('Simple voting'),
)]
class VotingQuestionBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly VotingManager $votingManager,
    protected readonly VoteWidgetBuilder $voteWidgetBuilder,
    protected readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('simple_voting.manager'),
      $container->get('simple_voting.vote_widget_builder'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['voting_question' => NULL] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['voting_question'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'voting_question',
      '#title' => $this->t('Question'),
      '#description' => $this->t('The question this block lets visitors vote on.'),
      '#default_value' => $this->getQuestion(),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $id = EntityAutocomplete::extractEntityIdFromAutocompleteInput((string) $form_state->getValue('voting_question'));
    $this->configuration['voting_question'] = $id;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    $question = $this->getQuestion();
    if (!$question instanceof VotingQuestionInterface) {
      return AccessResult::forbidden('No voting question configured for this block.');
    }

    $can_vote = $this->votingManager->checkVotingAccess($question, $account);

    // The global switch closes the entire flow, including a past voter's own
    // results, the same way it closes the CMS pages and the API.
    if (!$this->votingManager->isVotingEnabled()) {
      return AccessResult::forbidden('Voting is disabled.')
        ->addCacheableDependency($can_vote)
        ->cachePerUser();
    }

    $voted = $this->votingManager->hasVoted($question, $account);

    return AccessResult::allowedIf($voted)
      ->orIf($can_vote)
      ->addCacheableDependency($question)
      ->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $question = $this->getQuestion();
    if (!$question instanceof VotingQuestionInterface) {
      return [];
    }

    $build['question'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $question->getQuestion(),
    ];
    $build['widget'] = $this->voteWidgetBuilder->build($question, $this->currentUser);

    return $build;
  }

  /**
   * Loads the configured question, if any.
   */
  protected function getQuestion(): ?VotingQuestionInterface {
    $id = $this->configuration['voting_question'] ?? NULL;
    if (!$id) {
      return NULL;
    }

    $question = $this->entityTypeManager->getStorage('voting_question')->load($id);
    return $question instanceof VotingQuestionInterface ? $question : NULL;
  }

}
