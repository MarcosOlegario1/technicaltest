<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_voting\Entity\VotingQuestionInterface;

/**
 * Lists the answer options of a single question.
 */
class QuestionOptionsController extends ControllerBase {

  /**
   * Page title.
   */
  public function title(VotingQuestionInterface $voting_question): string {
    return (string) $this->t('Answer options for "@question"', [
      '@question' => $voting_question->label(),
    ]);
  }

  /**
   * Builds the option table for a question.
   */
  public function list(VotingQuestionInterface $voting_question): array {
    $storage = $this->entityTypeManager()->getStorage('voting_option');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('question', $voting_question->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $option) {
      /** @var \Drupal\simple_voting\Entity\VotingOptionInterface $option */
      $rows[] = [
        'title' => $option->getTitle(),
        'description' => $option->getDescription(),
        'image' => $option->get('image')->isEmpty() ? $this->t('No') : $this->t('Yes'),
        'weight' => $option->getWeight(),
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => $option->toUrl('edit-form'),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => $option->toUrl('delete-form'),
              ],
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Title'),
        $this->t('Description'),
        $this->t('Image'),
        $this->t('Weight'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('This question has no answer options yet.'),
      '#cache' => [
        'tags' => Cache::mergeTags($voting_question->getCacheTags(), ['voting_option_list']),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
