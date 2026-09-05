<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_voting\Kernel;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\simple_voting\Traits\VotingTestEntitiesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the "Voting question" block.
 *
 * This is the widget's entry point outside the dedicated /voting pages.
 *
 * @group simple_voting
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class VotingQuestionBlockTest extends KernelTestBase {

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
    'block',
    'simple_voting',
  ];

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
  }

  /**
   * Builds a configured block instance for the given question.
   */
  private function createBlock(int $question_id): BlockPluginInterface {
    return $this->container->get('plugin.manager.block')
      ->createInstance('simple_voting_question', ['voting_question' => $question_id]);
  }

  /**
   * With no question configured, the block is neither visible nor buildable.
   */
  public function testUnconfiguredBlockIsForbidden(): void {
    $block = $this->createBlock(0);
    $voter = $this->createUser(['vote in simple voting']);

    $this->assertFalse($block->access($voter));
    $this->assertSame([], $block->build());
  }

  /**
   * A voter who has not voted yet sees the vote form.
   */
  public function testBlockShowsVoteFormBeforeVoting(): void {
    [$question] = $this->createQuestion('snacks', ['Fruit', 'Chips']);
    $voter = $this->createUser(['vote in simple voting']);
    \Drupal::currentUser()->setAccount($voter);

    $block = $this->createBlock((int) $question->id());
    $this->assertTrue($block->access($voter));

    $build = $block->build();
    $this->assertSame('html_tag', $build['question']['#type']);
    $this->assertSame('h2', $build['question']['#tag']);
    $this->assertSame('simple_voting_vote_form', $build['widget']['#form_id']);
  }

  /**
   * Once the account has voted, the block shows the results instead.
   */
  public function testBlockShowsResultsAfterVoting(): void {
    [$question, $options] = $this->createQuestion('snacks', ['Fruit', 'Chips']);
    $voter = $this->createUser(['vote in simple voting']);
    \Drupal::currentUser()->setAccount($voter);

    $this->container->get('simple_voting.manager')->recordVote($question, $options[0], $voter);

    $block = $this->createBlock((int) $question->id());
    $this->assertTrue($block->access($voter));

    $build = $block->build();
    $this->assertSame('simple_voting_results', $build['widget']['#theme']);
  }

  /**
   * The global switch hides the block even for someone who already voted.
   *
   * This mirrors the CMS page and the API, which also close on the global
   * switch regardless of whether the caller has already voted.
   */
  public function testGlobalSwitchHidesTheBlockEvenAfterVoting(): void {
    [$question, $options] = $this->createQuestion('snacks', ['Fruit', 'Chips']);
    $voter = $this->createUser(['vote in simple voting']);
    $this->container->get('simple_voting.manager')->recordVote($question, $options[0], $voter);

    $this->config('simple_voting.settings')->set('voting_enabled', FALSE)->save();

    $this->assertFalse($this->createBlock((int) $question->id())->access($voter));
  }

  /**
   * A recorded vote grants access even without the vote permission.
   *
   * This covers a permission revoked after the vote was cast: the account
   * should still be able to see its own result.
   */
  public function testPastVoteGrantsAccessWithoutThePermission(): void {
    [$question, $options] = $this->createQuestion('snacks', ['Fruit', 'Chips']);
    $former_voter = $this->createUser();
    $this->container->get('simple_voting.manager')->recordVote($question, $options[0], $former_voter);

    $bystander = $this->createUser();

    $this->assertFalse($this->createBlock((int) $question->id())->access($bystander));
    $this->assertTrue($this->createBlock((int) $question->id())->access($former_voter));
  }

}
