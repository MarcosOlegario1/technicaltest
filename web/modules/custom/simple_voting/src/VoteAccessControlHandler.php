<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for votes.
 *
 * Votes are managed only by the voting logic itself. Administrators may look at
 * them and remove them; nobody edits a vote through the UI.
 */
class VoteAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (in_array($operation, ['view', 'delete'], TRUE)) {
      return AccessResult::allowedIfHasPermission($account, 'administer simple voting');
    }

    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Votes are only created through the voting manager.
    return AccessResult::forbidden();
  }

}
