<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_voting\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Exercises voting through the Drupal front end, not the external API.
 *
 * The PDF asks people to be able to access each question independently and
 * vote, and to see results according to the per-question setting; this is
 * the only test that drives that flow through the actual /voting pages
 * (VotingController, VoteForm) rather than through the REST API.
 *
 * @group simple_voting
 */
class CmsVotingTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['simple_voting'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The question shown across the test.
   *
   * @var \Drupal\simple_voting\Entity\VotingQuestionInterface
   */
  protected $question;

  /**
   * The question's answer options, keyed by title.
   *
   * @var \Drupal\simple_voting\Entity\VotingOptionInterface[]
   */
  protected array $options = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $question_storage = $this->container->get('entity_type.manager')->getStorage('voting_question');
    $option_storage = $this->container->get('entity_type.manager')->getStorage('voting_option');

    $this->question = $question_storage->create([
      'question' => 'Tabs or spaces?',
      'identifier' => 'tabs_or_spaces',
      'status' => TRUE,
      'show_results' => TRUE,
    ]);
    $this->question->save();

    foreach (['Tabs' => 'Bold choice.', 'Spaces' => 'The safe choice.'] as $title => $description) {
      $option = $option_storage->create([
        'question' => $this->question->id(),
        'title' => $title,
        'description' => $description,
      ]);
      $option->save();
      $this->options[$title] = $option;
    }
  }

  /**
   * A voter sees the options with their description, votes, and sees results.
   */
  public function testVoteFormShowsOptionsAndRecordsVote(): void {
    $voter = $this->drupalCreateUser(['vote in simple voting']);
    $this->drupalLogin($voter);

    $this->drupalGet('/voting/' . $this->question->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tabs or spaces?');
    $this->assertSession()->pageTextContains('Bold choice.');
    $this->assertSession()->pageTextContains('The safe choice.');

    $this->submitForm(['option' => $this->options['Tabs']->id()], 'Vote');

    $this->assertSession()->pageTextContains('Total votes: 1');
    $this->assertSession()->pageTextContains('your vote');
  }

  /**
   * The same account cannot vote twice; it is shown the results instead.
   */
  public function testDuplicateVoteShowsResultsInstead(): void {
    $voter = $this->drupalCreateUser(['vote in simple voting']);
    $this->drupalLogin($voter);

    $this->drupalGet('/voting/' . $this->question->id());
    $this->submitForm(['option' => $this->options['Tabs']->id()], 'Vote');

    // Visiting again shows the tally, not the form.
    $this->drupalGet('/voting/' . $this->question->id());
    $this->assertSession()->elementNotExists('css', 'input[name="option"]');
    $this->assertSession()->pageTextContains('Total votes: 1');
  }

  /**
   * When the question hides results, a voter only gets a confirmation.
   */
  public function testResultsHiddenShowsOnlyConfirmation(): void {
    $this->question->setShowResults(FALSE)->save();

    $voter = $this->drupalCreateUser(['vote in simple voting']);
    $this->drupalLogin($voter);

    $this->drupalGet('/voting/' . $this->question->id());
    $this->submitForm(['option' => $this->options['Tabs']->id()], 'Vote');

    $this->assertSession()->pageTextContains('Your vote has been recorded');
    $this->assertSession()->pageTextNotContains('Total votes');
  }

  /**
   * Disabling voting globally closes the CMS pages, not just the API.
   */
  public function testGlobalSwitchClosesTheVotePage(): void {
    $this->config('simple_voting.settings')->set('voting_enabled', FALSE)->save();

    $voter = $this->drupalCreateUser(['vote in simple voting']);
    $this->drupalLogin($voter);

    $this->drupalGet('/voting/' . $this->question->id());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A user without the permission cannot reach the vote form either.
   */
  public function testUserWithoutPermissionIsDenied(): void {
    $visitor = $this->drupalCreateUser();
    $this->drupalLogin($visitor);

    $this->drupalGet('/voting/' . $this->question->id());
    $this->assertSession()->statusCodeEquals(403);
  }

}
