<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Exception;

/**
 * Thrown when a vote is attempted while voting is globally disabled.
 */
class VotingClosedException extends VotingException {

}
