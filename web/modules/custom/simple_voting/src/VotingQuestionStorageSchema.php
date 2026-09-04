<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds indexes used to look questions up by identifier and status.
 */
class VotingQuestionStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();

    if (isset($schema[$base_table])) {
      $schema[$base_table]['indexes'] += [
        'voting_question__identifier' => ['identifier'],
        'voting_question__status' => ['status'],
      ];
    }

    return $schema;
  }

}
