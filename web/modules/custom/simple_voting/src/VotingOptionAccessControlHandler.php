<?php

declare(strict_types=1);

namespace Drupal\simple_voting;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for answer options.
 */
class VotingOptionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer simple voting')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\simple_voting\Entity\VotingOptionInterface $entity */
    if ($operation === 'view') {
      $question = $entity->getQuestion();
      if ($question === NULL) {
        return AccessResult::forbidden()->addCacheableDependency($entity);
      }
      return $question->access('view', $account, TRUE)->addCacheableDependency($entity);
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer simple voting');
  }

}
