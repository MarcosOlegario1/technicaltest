<?php

/**
 * @file
 * Fires a large batch of real, concurrent HTTP votes at the external API.
 *
 * This is a load/concurrency test, not a demo seed: it hits the actual
 * running site over HTTP (auth, routing, controller, database), the same way
 * a real external application would, so it exercises the whole stack, not
 * just the service layer.
 *
 * Run it with: lando drush php:script scripts/load_test_api.php
 *
 * Tune it with environment variables (defaults produce 500 * 40 = 20,000
 * unique votes, plus a 5% batch of deliberate concurrent duplicates):
 *
 *   LOAD_TEST_USERS=500            Distinct voter accounts to create.
 *   LOAD_TEST_QUESTIONS=40         Throwaway questions to create.
 *   LOAD_TEST_CONCURRENCY=100      Simultaneous in-flight HTTP requests.
 *   LOAD_TEST_DUPLICATE_RATIO=0.05 Extra concurrent re-votes, to stress the
 *                                  race path (expected result: 409, never a
 *                                  second row).
 *   LOAD_TEST_BASE_URL=https://my-lando-app.lndo.site
 *   LOAD_TEST_KEEP_DATA=1          Skip cleanup at the end, to inspect data.
 *
 * Example, a quick 1,000-vote smoke run:
 *   LOAD_TEST_USERS=100 LOAD_TEST_QUESTIONS=10 \
 *     lando drush php:script scripts/load_test_api.php
 *
 * It always deletes any load-test users/questions left over from a previous
 * run before starting, so it is safe to re-run.
 */

declare(strict_types=1);

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

$users_total = max(1, (int) (getenv('LOAD_TEST_USERS') ?: 500));
$questions_total = max(1, (int) (getenv('LOAD_TEST_QUESTIONS') ?: 40));
$concurrency = max(1, (int) (getenv('LOAD_TEST_CONCURRENCY') ?: 100));
$duplicate_ratio = max(0.0, (float) (getenv('LOAD_TEST_DUPLICATE_RATIO') ?: 0.05));
$base_url = getenv('LOAD_TEST_BASE_URL') ?: 'https://my-lando-app.lndo.site';
$keep_data = getenv('LOAD_TEST_KEEP_DATA') === '1';

const LOAD_TEST_ROLE = 'load_test_voter';
const LOAD_TEST_PASSWORD = 'LoadTest123!';
const LOAD_TEST_PREFIX = 'load_test_';

$run_id = date('Ymd_His');
$entity_type_manager = \Drupal::entityTypeManager();
$question_storage = $entity_type_manager->getStorage('voting_question');
$option_storage = $entity_type_manager->getStorage('voting_option');
$user_storage = $entity_type_manager->getStorage('user');
$vote_storage = $entity_type_manager->getStorage('vote');

/**
 * Deletes users, questions (and, via the module's own hooks, their options
 * and votes) left over from an earlier run.
 */
function load_test_cleanup(EntityStorageInterface $question_storage, EntityStorageInterface $user_storage): void {
  $question_ids = $question_storage->getQuery()->accessCheck(FALSE)
    ->condition('identifier', LOAD_TEST_PREFIX, 'STARTS_WITH')
    ->execute();
  if ($question_ids) {
    $question_storage->delete($question_storage->loadMultiple($question_ids));
  }

  $user_ids = $user_storage->getQuery()->accessCheck(FALSE)
    ->condition('name', LOAD_TEST_PREFIX, 'STARTS_WITH')
    ->execute();
  if ($user_ids) {
    $user_storage->delete($user_storage->loadMultiple($user_ids));
  }
}

fwrite(STDOUT, "Cleaning up leftovers from a previous run...\n");
load_test_cleanup($question_storage, $user_storage);

// The role that grants exactly what an external API client needs, nothing
// more, matching the real "access simple voting api" permission a genuine
// integration would be given.
if (!Role::load(LOAD_TEST_ROLE)) {
  Role::create(['id' => LOAD_TEST_ROLE, 'label' => 'Load test voter'])
    ->grantPermission('access simple voting api')
    ->save();
}

$settings = \Drupal::configFactory()->getEditable('simple_voting.settings');
$was_enabled = (bool) $settings->get('voting_enabled');
if (!$was_enabled) {
  $settings->set('voting_enabled', TRUE)->save();
  fwrite(STDOUT, "Voting was globally disabled; temporarily enabling it for the run.\n");
}

$attempted = $users_total * $questions_total;
fwrite(STDOUT, sprintf(
  "Plan: %d users x %d questions = %d unique votes, +%d deliberate duplicate re-votes, concurrency %d.\n",
  $users_total,
  $questions_total,
  $attempted,
  (int) round($attempted * $duplicate_ratio),
  $concurrency
));

fwrite(STDOUT, "Creating $questions_total throwaway questions...\n");
$questions = [];
for ($i = 1; $i <= $questions_total; $i++) {
  $question = $question_storage->create([
    'question' => "Load test question {$run_id} #{$i}",
    'identifier' => LOAD_TEST_PREFIX . "q_{$run_id}_{$i}",
    'status' => TRUE,
    'show_results' => TRUE,
  ]);
  $question->save();

  $option_ids = [];
  foreach (['A', 'B', 'C'] as $label) {
    $option = $option_storage->create([
      'question' => $question->id(),
      'title' => "Option {$label}",
    ]);
    $option->save();
    $option_ids[] = (int) $option->id();
  }

  $questions[] = ['identifier' => $question->getIdentifier(), 'options' => $option_ids];
}

fwrite(STDOUT, "Creating $users_total test users...\n");
$user_names = [];
for ($i = 1; $i <= $users_total; $i++) {
  $name = LOAD_TEST_PREFIX . "user_{$run_id}_{$i}";
  $user = User::create(['name' => $name, 'status' => 1]);
  $user->addRole(LOAD_TEST_ROLE);
  $user->setPassword(LOAD_TEST_PASSWORD);
  $user->save();
  $user_names[] = $name;

  if ($i % 100 === 0 || $i === $users_total) {
    fwrite(STDOUT, "  $i/$users_total\n");
  }
}

// One request per (user, question) pair: every voter attempts every
// question exactly once, which is how a real audience behaves.
$requests = [];
foreach ($user_names as $user_name) {
  foreach ($questions as $question) {
    $requests[] = [
      'user' => $user_name,
      'identifier' => $question['identifier'],
      'option' => $question['options'][array_rand($question['options'])],
    ];
  }
}

// A batch of deliberate duplicates: the same (user, question) pair fired
// again, concurrently with the rest, to stress the exact race the unique
// database key exists for. The expected outcome is a 409 for every one of
// these, never a second row in the vote table.
$duplicate_count = (int) round(count($requests) * $duplicate_ratio);
$duplicate_source = $requests;
for ($i = 0; $i < $duplicate_count; $i++) {
  $requests[] = $duplicate_source[array_rand($duplicate_source)];
}
shuffle($requests);

$total = count($requests);
fwrite(STDOUT, "Firing $total requests against $base_url ...\n");

// http_errors is disabled: a 409/429/4xx/5xx is a normal, expected response
// this script classifies itself. Without this, Guzzle would treat every
// non-2xx status as a rejected promise, indistinguishable from a real
// network failure.
$client = new Client(['base_uri' => $base_url, 'verify' => FALSE, 'timeout' => 20, 'http_errors' => FALSE]);

$counts = [
  'success' => 0,
  'duplicate' => 0,
  'rate_limited' => 0,
  'other_4xx' => 0,
  'server_error' => 0,
  'network_error' => 0,
];
$done = 0;
$start = microtime(TRUE);
$network_error_samples = [];

/**
 * Prints a live-updating progress line, without flooding the terminal.
 */
function load_test_report_progress(int $done, int $total, array $counts, float $start): void {
  $step = max(1, (int) ($total / 200));
  if ($done % $step !== 0 && $done !== $total) {
    return;
  }
  $elapsed = max(0.001, microtime(TRUE) - $start);
  fwrite(STDOUT, sprintf(
    "\r[%5.1f%%] %d/%d | ok=%d dup=%d rate_limited=%d other_4xx=%d 5xx=%d net_err=%d | %.0f req/s   ",
    $done / $total * 100,
    $done,
    $total,
    $counts['success'],
    $counts['duplicate'],
    $counts['rate_limited'],
    $counts['other_4xx'],
    $counts['server_error'],
    $counts['network_error'],
    $done / $elapsed
  ));
}

$request_generator = function () use ($requests) {
  foreach ($requests as $r) {
    yield new Request(
      'POST',
      "/api/voting/questions/{$r['identifier']}/vote",
      [
        'Authorization' => 'Basic ' . base64_encode($r['user'] . ':' . LOAD_TEST_PASSWORD),
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
      ],
      json_encode(['option' => $r['option']], JSON_THROW_ON_ERROR)
    );
  }
};

$pool = new Pool($client, $request_generator(), [
  'concurrency' => $concurrency,
  'fulfilled' => function (ResponseInterface $response) use (&$counts, &$done, $total, $start) {
    $code = $response->getStatusCode();
    if ($code === 201) {
      $counts['success']++;
    }
    elseif ($code === 409) {
      $counts['duplicate']++;
    }
    elseif ($code === 429) {
      $counts['rate_limited']++;
    }
    elseif ($code >= 500) {
      $counts['server_error']++;
    }
    else {
      $counts['other_4xx']++;
    }
    $done++;
    load_test_report_progress($done, $total, $counts, $start);
  },
  'rejected' => function ($reason) use (&$counts, &$done, $total, $start, &$network_error_samples) {
    $counts['network_error']++;
    if (count($network_error_samples) < 5) {
      $network_error_samples[] = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
    }
    $done++;
    load_test_report_progress($done, $total, $counts, $start);
  },
]);

$pool->promise()->wait();
$elapsed = microtime(TRUE) - $start;
fwrite(STDOUT, "\n\n");

// Verify the database agrees with what the API reported: exactly one row
// per successful vote, and never more than one row per (question, voter).
$question_ids = $question_storage->getQuery()->accessCheck(FALSE)
  ->condition('identifier', LOAD_TEST_PREFIX, 'STARTS_WITH')
  ->execute();

$db_vote_count = (int) $vote_storage->getQuery()->accessCheck(FALSE)
  ->condition('question', $question_ids, 'IN')
  ->count()
  ->execute();

$duplicate_rows_query = \Drupal::database()->select('vote', 'v');
$duplicate_rows_query->addExpression('COUNT(*)', 'pairs');
$duplicate_rows_query->condition('v.question', $question_ids, 'IN');
$duplicate_rows_query->groupBy('v.question');
$duplicate_rows_query->groupBy('v.voter');
$duplicate_rows_query->having('COUNT(*) > 1');
$offending_pairs = count($duplicate_rows_query->execute()->fetchAll());

fwrite(STDOUT, "===== Results =====\n");
fwrite(STDOUT, sprintf("Requests sent:       %d\n", $total));
fwrite(STDOUT, sprintf("Elapsed:             %.1fs (%.0f req/s)\n", $elapsed, $total / max(0.001, $elapsed)));
fwrite(STDOUT, sprintf("201 recorded:        %d\n", $counts['success']));
fwrite(STDOUT, sprintf("409 already voted:   %d (expected for the deliberate duplicates)\n", $counts['duplicate']));
fwrite(STDOUT, sprintf("429 rate limited:    %d\n", $counts['rate_limited']));
fwrite(STDOUT, sprintf("other 4xx:           %d\n", $counts['other_4xx']));
fwrite(STDOUT, sprintf("5xx / network errors: %d + %d\n", $counts['server_error'], $counts['network_error']));
if ($network_error_samples) {
  fwrite(STDOUT, "Sample network error(s):\n  - " . implode("\n  - ", $network_error_samples) . "\n");
}
$unconfirmed = $counts['network_error'] + $counts['server_error'];
$vote_gap = $db_vote_count - $counts['success'];
if ($vote_gap === 0) {
  $vote_line = 'match';
}
elseif ($vote_gap > 0 && $vote_gap <= $unconfirmed) {
  // More rows than confirmed successes, fully explained by requests whose
  // response never reached the client (timeout/connection reset): the vote
  // was still recorded server-side, only the confirmation was lost. Not a
  // correctness problem, but worth noting: those clients believe their vote
  // failed when it did not.
  $vote_line = "{$vote_gap} vote(s) recorded but never confirmed to the caller (network/5xx on an otherwise successful request)";
}
else {
  // Rows missing, or more extra rows than errors can explain: a real loss
  // or double-count, not a timeout artifact.
  $vote_line = 'MISMATCH — unexplained by network/5xx errors alone';
}
fwrite(STDOUT, sprintf("Vote rows in the DB: %d (API reported %d successes — %s)\n", $db_vote_count, $counts['success'], $vote_line));
fwrite(STDOUT, sprintf("(question, voter) pairs with more than one row: %d (%s)\n", $offending_pairs, $offending_pairs === 0 ? 'none, integrity held' : 'DATA CORRUPTION'));

$problem = $offending_pairs > 0 || ($vote_gap < 0) || ($vote_gap > $unconfirmed);
fwrite(STDOUT, $problem
  ? "\nFAIL: a duplicate vote or an unexplained row count survived — see the counters above.\n"
  : "\nPASS: no duplicate votes, and every row is accounted for (recorded or explained by a lost confirmation).\n");
if (!$problem && $unconfirmed > 0) {
  fwrite(STDOUT, "NOTE: $unconfirmed request(s) timed out or errored without the caller getting a response, even though the vote itself was safely recorded. Under real load, an external client should retry a timeout by checking GET .../results or its own state before assuming the vote failed, rather than blindly resubmitting.\n");
}

if (!$keep_data) {
  fwrite(STDOUT, "\nCleaning up the load test's users and questions...\n");
  load_test_cleanup($question_storage, $user_storage);
  if (!$was_enabled) {
    $settings->set('voting_enabled', FALSE)->save();
  }
}
else {
  fwrite(STDOUT, "\nLOAD_TEST_KEEP_DATA=1: leaving the generated data in place.\n");
}

echo "done\n";
