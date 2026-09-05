<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Exception;

/**
 * Thrown when an account casts votes faster than the allowed rate.
 */
class VoteRateLimitException extends VotingException {

}
