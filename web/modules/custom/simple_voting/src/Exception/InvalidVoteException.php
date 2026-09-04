<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Exception;

/**
 * Thrown when the chosen option does not belong to the question.
 */
class InvalidVoteException extends VotingException {

}
