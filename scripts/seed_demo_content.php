<?php

/**
 * @file
 * Seeds demo content for the simple voting system.
 *
 * Run it with: lando drush php:script scripts/seed_demo_content.php
 *
 * It is safe to run more than once: existing questions with the same identifier
 * are left untouched. The demo users below use trivial passwords and are only
 * meant for local evaluation.
 */

declare(strict_types=1);

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$entity_type_manager = \Drupal::entityTypeManager();
$question_storage = $entity_type_manager->getStorage('voting_question');
$option_storage = $entity_type_manager->getStorage('voting_option');

/**
 * Creates a question with its options unless it already exists.
 */
$ensure_question = static function (array $definition) use ($question_storage, $option_storage): void {
  $existing = $question_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('identifier', $definition['identifier'])
    ->execute();
  if ($existing) {
    echo "skip: {$definition['identifier']} already exists\n";
    return;
  }

  $question = $question_storage->create([
    'question' => $definition['question'],
    'identifier' => $definition['identifier'],
    'status' => TRUE,
    'show_results' => $definition['show_results'],
  ]);
  $question->save();

  $weight = 0;
  foreach ($definition['options'] as $option) {
    $option_storage->create([
      'question' => $question->id(),
      'title' => $option['title'],
      'description' => $option['description'] ?? '',
      'weight' => $weight++,
    ])->save();
  }

  echo "created: {$definition['identifier']} with " . count($definition['options']) . " options\n";
};

$questions = [
  [
    'identifier' => 'favorite_language',
    'question' => 'What is your favorite programming language?',
    'show_results' => TRUE,
    'options' => [
      ['title' => 'PHP', 'description' => 'The language Drupal is built on.'],
      ['title' => 'Python', 'description' => 'Readable and everywhere.'],
      ['title' => 'JavaScript', 'description' => 'Runs in every browser.'],
      ['title' => 'Rust', 'description' => 'Memory safety without a garbage collector.'],
    ],
  ],
  [
    'identifier' => 'office_snack',
    'question' => 'Which snack should we stock in the office?',
    'show_results' => TRUE,
    'options' => [
      ['title' => 'Fruit', 'description' => 'Apples, bananas and oranges.'],
      ['title' => 'Nuts', 'description' => 'Almonds and cashews.'],
      ['title' => 'Chocolate', 'description' => 'Dark, 70% cocoa.'],
    ],
  ],
  [
    'identifier' => 'deploy_day',
    'question' => 'What is the best day to deploy to production?',
    'show_results' => FALSE,
    'options' => [
      ['title' => 'Monday'],
      ['title' => 'Tuesday'],
      ['title' => 'Wednesday'],
      ['title' => 'Thursday'],
      ['title' => 'Never on Friday'],
    ],
  ],
];

foreach ($questions as $definition) {
  $ensure_question($definition);
}

/**
 * Demo roles.
 */
foreach ([
  'voter' => ['label' => 'Voter', 'permissions' => ['vote in simple voting']],
  'api_consumer' => ['label' => 'API consumer', 'permissions' => ['access simple voting api']],
] as $role_id => $info) {
  $role = Role::load($role_id) ?? Role::create(['id' => $role_id, 'label' => $info['label']]);
  foreach ($info['permissions'] as $permission) {
    $role->grantPermission($permission);
  }
  $role->save();
}

/**
 * Demo users. Trivial passwords, local use only.
 */
foreach ([
  'voter' => 'voter',
  'api_consumer' => 'api_consumer',
] as $name => $role_id) {
  $user = user_load_by_name($name);
  if (!$user) {
    $user = User::create(['name' => $name]);
    $user->setPassword($name);
    $user->activate();
    $user->addRole($role_id);
    $user->save();
    echo "created user: {$name} / {$name}\n";
  }
  else {
    echo "skip user: {$name} already exists\n";
  }
}

/**
 * Cast a handful of votes so the results pages are not empty.
 */
$voting_manager = \Drupal::service('simple_voting.manager');
$favorite = $question_storage->loadByProperties(['identifier' => 'favorite_language']);
$favorite = $favorite ? reset($favorite) : NULL;

if ($favorite) {
  $options = array_values($option_storage->loadByProperties(['question' => $favorite->id()]));
  $tally = [0, 0, 1, 2, 3, 0, 1, 0, 2, 0];
  foreach ($tally as $index => $option_index) {
    $name = 'demo_voter_' . $index;
    $user = user_load_by_name($name);
    if (!$user) {
      $user = User::create(['name' => $name]);
      $user->activate();
      $user->addRole('voter');
      $user->save();
    }
    try {
      $voting_manager->recordVote($favorite, $options[$option_index], $user);
    }
    catch (\Drupal\simple_voting\Exception\VotingException $e) {
      // Already voted on a previous run; nothing to do.
    }
  }
  echo "cast demo votes on favorite_language\n";
}

echo "done\n";
