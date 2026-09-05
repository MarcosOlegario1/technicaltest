<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Exception;

/**
 * Thrown when a user tries to vote twice on the same question.
 */
class DuplicateVoteException extends VotingException {

}
