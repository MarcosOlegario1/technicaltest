<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_voting\Traits;

/**
 * Creates questions and options for tests, without the CMS or the API.
 */
trait VotingTestEntitiesTrait {

  /**
   * Creates a published question with the given option titles.
   *
   * @param string $identifier
   *   The question identifier.
   * @param string[] $option_titles
   *   The option titles to create.
   * @param bool $show_results
   *   Whether the question shows results after voting.
   *
   * @return array
   *   A tuple of [question, options[]].
   */
  protected function createQuestion(string $identifier, array $option_titles, bool $show_results = TRUE): array {
    $question_storage = \Drupal::entityTypeManager()->getStorage('voting_question');
    $option_storage = \Drupal::entityTypeManager()->getStorage('voting_option');

    $question = $question_storage->create([
      'question' => ucfirst($identifier) . '?',
      'identifier' => $identifier,
      'status' => TRUE,
      'show_results' => $show_results,
    ]);
    $question->save();

    $options = [];
    $weight = 0;
    foreach ($option_titles as $title) {
      $option = $option_storage->create([
        'question' => $question->id(),
        'title' => $title,
        'weight' => $weight++,
      ]);
      $option->save();
      $options[] = $option;
    }

    return [$question, $options];
  }

}
