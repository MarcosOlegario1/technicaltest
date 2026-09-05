<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\Exception\DuplicateVoteException;
use Drupal\simple_voting\Exception\InvalidVoteException;
use Drupal\simple_voting\Exception\VoteRateLimitException;
use Drupal\simple_voting\Exception\VotingClosedException;
use Drupal\simple_voting\Exception\VotingException;
use Drupal\simple_voting\VotingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets a user pick one option and cast a vote on a question.
 */
class VoteForm extends FormBase {

  public function __construct(
    protected readonly VotingManager $votingManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_voting.manager'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_voting_vote_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?VotingQuestionInterface $voting_question = NULL): array {
    $form_state->set('question_id', $voting_question->id());

    $options = $this->loadOptions($voting_question);
    if (!$options) {
      $form['empty'] = [
        '#markup' => $this->t('This question has no answer options yet.'),
      ];
      return $form;
    }

    $labels = [];
    foreach ($options as $id => $option) {
      $labels[$id] = $option->getTitle();
    }

    $form['option'] = [
      '#type' => 'radios',
      '#title' => $this->t('Your choice'),
      '#options' => $labels,
      '#required' => TRUE,
    ];

    // Add the description and image below each radio.
    foreach ($options as $id => $option) {
      $extra = [];
      if ($option->getDescription() !== '') {
        $extra['description'] = [
          '#markup' => '<div class="simple-voting-option__description">' . $option->getDescription() . '</div>',
        ];
      }
      if (!$option->get('image')->isEmpty()) {
        $extra['image'] = $option->get('image')->view([
          'type' => 'image',
          'label' => 'hidden',
          'settings' => ['image_style' => 'thumbnail'],
        ]);
      }
      if ($extra) {
        $form['option'][$id]['#description'] = $extra;
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Vote'),
      ],
    ];

    $form['#cache']['contexts'][] = 'user';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $question = $this->entityTypeManager->getStorage('voting_question')
      ->load($form_state->get('question_id'));
    $option = $this->entityTypeManager->getStorage('voting_option')
      ->load($form_state->getValue('option'));

    if (!$question instanceof VotingQuestionInterface || $option === NULL) {
      $this->messenger()->addError($this->t('Something went wrong. Please try again.'));
      return;
    }

    try {
      $this->votingManager->recordVote($question, $option, $this->currentUser);
      $this->messenger()->addStatus($this->t('Thanks, your vote has been recorded.'));
    }
    catch (DuplicateVoteException) {
      $this->messenger()->addError($this->t('You have already voted on this question.'));
    }
    catch (VotingClosedException) {
      $this->messenger()->addError($this->t('Voting is currently closed.'));
    }
    catch (VoteRateLimitException) {
      $this->messenger()->addError($this->t('Too many votes in a short time. Please try again later.'));
    }
    catch (InvalidVoteException) {
      $this->messenger()->addError($this->t('That option is not valid for this question.'));
    }
    catch (VotingException) {
      $this->messenger()->addError($this->t('Your vote could not be saved. Please try again.'));
    }

    $form_state->setRedirect('simple_voting.question', [
      'voting_question' => $question->id(),
    ]);
  }

  /**
   * Loads the question's options, keyed and ordered.
   *
   * @return \Drupal\simple_voting\Entity\VotingOptionInterface[]
   *   The options.
   */
  private function loadOptions(VotingQuestionInterface $question): array {
    $storage = $this->entityTypeManager->getStorage('voting_option');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    return $storage->loadMultiple($ids);
  }

}
