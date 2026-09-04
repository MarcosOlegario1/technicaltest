<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Enforces a unique identifier at the database level and indexes the status.
 *
 * The entity also carries a UniqueField constraint, which covers the admin
 * form; the unique key here also protects programmatic writes, which skip
 * entity validation.
 */
class VotingQuestionStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();

    if (isset($schema[$base_table])) {
      $schema[$base_table]['unique keys']['voting_question__identifier'] = ['identifier'];
      $schema[$base_table]['indexes']['voting_question__status'] = ['status'];
    }

    return $schema;
  }

}
