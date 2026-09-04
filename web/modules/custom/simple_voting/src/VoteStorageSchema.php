<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Schema tweaks that protect vote integrity under load.
 *
 * The unique key on (question, voter) is the last line of defence against a
 * user voting twice on the same question when requests race each other. The
 * extra index on (question, answer_option) keeps the results aggregation fast.
 */
class VoteStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();

    if (isset($schema[$base_table])) {
      $schema[$base_table]['unique keys']['vote__question_voter'] = ['question', 'voter'];
      $schema[$base_table]['indexes']['vote__question_option'] = ['question', 'answer_option'];
    }

    return $schema;
  }

}
