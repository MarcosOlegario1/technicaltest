<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_voting\VoteAccessControlHandler;
use Drupal\simple_voting\VoteListBuilder;
use Drupal\simple_voting\VoteStorageSchema;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the vote entity.
 *
 * A vote links one user to one answer option of one question. A unique key on
 * (question, voter) keeps a user from voting twice on the same question.
 */
#[ContentEntityType(
  id: 'vote',
  label: new TranslatableMarkup('Vote'),
  label_collection: new TranslatableMarkup('Votes'),
  label_singular: new TranslatableMarkup('vote'),
  label_plural: new TranslatableMarkup('votes'),
  label_count: [
    'singular' => '@count vote',
    'plural' => '@count votes',
  ],
  handlers: [
    'list_builder' => VoteListBuilder::class,
    'access' => VoteAccessControlHandler::class,
    'storage_schema' => VoteStorageSchema::class,
    'form' => [
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'vote',
  admin_permission: 'administer simple voting',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'voter',
  ],
  links: [
    'collection' => '/admin/content/vote',
    'canonical' => '/admin/content/vote/{vote}',
    'delete-form' => '/admin/content/vote/{vote}/delete',
  ],
)]
class Vote extends ContentEntityBase implements VoteInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): ?VotingQuestionInterface {
    $question = $this->get('question')->entity;
    return $question instanceof VotingQuestionInterface ? $question : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestionId(): ?int {
    $value = $this->get('question')->target_id;
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAnswerOption(): ?VotingOptionInterface {
    $option = $this->get('answer_option')->entity;
    return $option instanceof VotingOptionInterface ? $option : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAnswerOptionId(): ?int {
    $value = $this->get('answer_option')->target_id;
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Question'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['answer_option'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Answer option'))
      ->setSetting('target_type', 'voting_option')
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['voter']
      ->setLabel(new TranslatableMarkup('Voter'))
      ->setDescription(new TranslatableMarkup('The user who cast this vote.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Cast on'))
      ->setReadOnly(TRUE);

    return $fields;
  }

}
