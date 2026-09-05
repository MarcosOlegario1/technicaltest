<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only admin list of votes, useful for auditing.
 */
class VoteListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['question'] = $this->t('Question');
    $header['option'] = $this->t('Option');
    $header['voter'] = $this->t('Voter');
    $header['created'] = $this->t('Cast on');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\simple_voting\Entity\VoteInterface $entity */
    $question = $entity->getQuestion();
    $option = $entity->getAnswerOption();
    $voter = $entity->getOwner();

    $row['id'] = $entity->id();
    $row['question'] = $question ? $question->label() : $this->t('Deleted');
    $row['option'] = $option ? $option->label() : $this->t('Deleted');
    $row['voter'] = $voter ? $voter->getDisplayName() : $this->t('Deleted');
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    // Votes are never edited, only removed for moderation.
    unset($operations['edit']);
    return $operations;
  }

}
