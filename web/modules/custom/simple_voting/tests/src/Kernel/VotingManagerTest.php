<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_voting\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\simple_voting\Traits\VotingTestEntitiesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\Exception\DuplicateVoteException;
use Drupal\simple_voting\Exception\InvalidVoteException;
use Drupal\simple_voting\Exception\VotingClosedException;
use Drupal\simple_voting\VotingManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the shared voting logic.
 *
 * @group simple_voting
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class VotingManagerTest extends KernelTestBase {

  use UserCreationTrait;
  use VotingTestEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'options',
    'simple_voting',
  ];

  /**
   * The voting manager under test.
   */
  protected VotingManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('voting_question');
    $this->installEntitySchema('voting_option');
    $this->installEntitySchema('vote');
    $this->installConfig(['simple_voting']);

    $this->manager = $this->container->get('simple_voting.manager');
  }

  /**
   * A vote is recorded once and shows up in the results.
   */
  public function testRecordVoteAndResults(): void {
    [$question, $options] = $this->createQuestion('colours', ['Red', 'Green', 'Blue']);
    $voter = $this->createUser(['vote in simple voting']);

    $vote = $this->manager->recordVote($question, $options[0], $voter);

    $this->assertSame((int) $question->id(), $vote->getQuestionId());
    $this->assertSame((int) $options[0]->id(), $vote->getAnswerOptionId());
    $this->assertTrue($this->manager->hasVoted($question, $voter));

    $results = $this->manager->getResults($question);
    $this->assertSame(1, $results['total']);
    $this->assertCount(3, $results['options']);
    $this->assertSame(1, $results['options'][0]['count']);
    $this->assertSame(100.0, $results['options'][0]['percentage']);
    $this->assertSame(0, $results['options'][1]['count']);
  }

  /**
   * The same user cannot vote twice on a question.
   */
  public function testDuplicateVoteIsRejected(): void {
    [$question, $options] = $this->createQuestion('lunch', ['Pizza', 'Salad']);
    $voter = $this->createUser(['vote in simple voting']);

    $this->manager->recordVote($question, $options[0], $voter);

    $this->expectException(DuplicateVoteException::class);
    $this->manager->recordVote($question, $options[1], $voter);
  }

  /**
   * The database unique key blocks a racing duplicate, not just the pre-check.
   */
  public function testConcurrentDuplicateIsRejected(): void {
    [$question, $options] = $this->createQuestion('race', ['A', 'B']);
    $voter = $this->createUser(['vote in simple voting']);

    // A manager that always believes the user has not voted yet, so the insert
    // is the only thing standing in the way.
    $blind_manager = new class(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('database'),
      $this->container->get('flood'),
      $this->container->get('cache.default'),
      $this->container->get('cache_tags.invalidator'),
      $this->container->get('logger.channel.simple_voting'),
    ) extends VotingManager {

      /**
       * {@inheritdoc}
       */
      public function hasVoted(VotingQuestionInterface $question, $account): bool {
        return FALSE;
      }

    };

    $blind_manager->recordVote($question, $options[0], $voter);

    $this->expectException(DuplicateVoteException::class);
    $blind_manager->recordVote($question, $options[0], $voter);
  }

  /**
   * Voting while the global switch is off is refused.
   */
  public function testVotingDisabled(): void {
    [$question, $options] = $this->createQuestion('closed', ['Yes', 'No']);
    $voter = $this->createUser(['vote in simple voting']);

    $this->config('simple_voting.settings')->set('voting_enabled', FALSE)->save();

    $this->assertFalse($this->manager->isVotingEnabled());
    $this->expectException(VotingClosedException::class);
    $this->manager->recordVote($question, $options[0], $voter);
  }

  /**
   * An option from another question cannot be used.
   */
  public function testOptionFromAnotherQuestionIsRejected(): void {
    [$question] = $this->createQuestion('one', ['X']);
    [, $other_options] = $this->createQuestion('two', ['Y']);
    $voter = $this->createUser(['vote in simple voting']);

    $this->expectException(InvalidVoteException::class);
    $this->manager->recordVote($question, $other_options[0], $voter);
  }

  /**
   * Two questions cannot share an identifier.
   */
  public function testDuplicateIdentifierIsRejected(): void {
    $this->createQuestion('shared_id', ['One']);

    $storage = $this->container->get('entity_type.manager')->getStorage('voting_question');
    $duplicate = $storage->create([
      'question' => 'Another one?',
      'identifier' => 'shared_id',
      'status' => TRUE,
    ]);

    // The entity constraint catches it on the admin form.
    $this->assertCount(1, $duplicate->validate());

    // The database unique key catches writes that skip validation.
    $this->expectException(EntityStorageException::class);
    $duplicate->save();
  }

  /**
   * Access reflects the global switch, the published flag and the permission.
   */
  public function testCheckVotingAccess(): void {
    [$question] = $this->createQuestion('access', ['One']);

    $with_permission = $this->createUser(['vote in simple voting']);
    $without_permission = $this->createUser();

    $this->assertTrue($this->manager->checkVotingAccess($question, $with_permission)->isAllowed());
    $this->assertFalse($this->manager->checkVotingAccess($question, $without_permission)->isAllowed());

    $question->setUnpublished()->save();
    $this->assertFalse($this->manager->checkVotingAccess($question, $with_permission)->isAllowed());
  }

}
