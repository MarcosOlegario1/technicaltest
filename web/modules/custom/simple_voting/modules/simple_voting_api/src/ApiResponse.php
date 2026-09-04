<?php

declare(strict_types=1);

namespace Drupal\simple_voting_api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds the JSON envelopes used by every API endpoint.
 *
 * Success payloads are wrapped in "data", failures in "error" with a stable
 * machine code, so external clients can branch on it.
 */
final class ApiResponse {

  /**
   * A successful response.
   */
  public static function ok(array $data, int $status = 200): JsonResponse {
    return new JsonResponse(['data' => $data], $status);
  }

  /**
   * An error response.
   */
  public static function error(string $code, string $message, int $status): JsonResponse {
    return new JsonResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
    ], $status);
  }

}
