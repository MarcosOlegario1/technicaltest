<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Builds the admin list of voting questions.
 */
class VotingQuestionListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['question'] = $this->t('Question');
    $header['identifier'] = $this->t('Identifier');
    $header['status'] = $this->t('Published');
    $header['show_results'] = $this->t('Shows results');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface $entity */
    $row['question'] = $entity->toLink($entity->getQuestion());
    $row['identifier'] = $entity->getIdentifier();
    $row['status'] = $entity->isPublished() ? $this->t('Yes') : $this->t('No');
    $row['show_results'] = $entity->showResults() ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

}
