<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_voting\Form\VotingQuestionForm;
use Drupal\simple_voting\VotingQuestionAccessControlHandler;
use Drupal\simple_voting\VotingQuestionListBuilder;
use Drupal\simple_voting\VotingQuestionStorageSchema;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the voting question entity.
 */
#[ContentEntityType(
  id: 'voting_question',
  label: new TranslatableMarkup('Voting question'),
  label_collection: new TranslatableMarkup('Voting questions'),
  label_singular: new TranslatableMarkup('voting question'),
  label_plural: new TranslatableMarkup('voting questions'),
  label_count: [
    'singular' => '@count voting question',
    'plural' => '@count voting questions',
  ],
  handlers: [
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => VotingQuestionListBuilder::class,
    'access' => VotingQuestionAccessControlHandler::class,
    'storage_schema' => VotingQuestionStorageSchema::class,
    'form' => [
      'default' => VotingQuestionForm::class,
      'add' => VotingQuestionForm::class,
      'edit' => VotingQuestionForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'voting_question',
  admin_permission: 'administer simple voting',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'question',
    'owner' => 'uid',
    'published' => 'status',
  ],
  links: [
    'collection' => '/admin/content/voting-question',
    'canonical' => '/admin/content/voting-question/{voting_question}',
    'add-form' => '/admin/content/voting-question/add',
    'edit-form' => '/admin/content/voting-question/{voting_question}/edit',
    'delete-form' => '/admin/content/voting-question/{voting_question}/delete',
  ],
)]
class VotingQuestion extends ContentEntityBase implements VotingQuestionInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->get('question')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getIdentifier(): string {
    return (string) $this->get('identifier')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setIdentifier(string $identifier): VotingQuestionInterface {
    $this->set('identifier', $identifier);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function showResults(): bool {
    return (bool) $this->get('show_results')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setShowResults(bool $show): VotingQuestionInterface {
    $this->set('show_results', $show);
    return $this;
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
    $fields += static::publishedBaseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['question'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Question'))
      ->setDescription(new TranslatableMarkup('The text people will vote on.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Machine identifier the external API uses to address a question. It is set
    // through a dedicated form element, so it has no widget of its own.
    $fields['identifier'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Identifier'))
      ->setDescription(new TranslatableMarkup('Unique machine name used by the external API.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField');

    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show results after voting'))
      ->setDescription(new TranslatableMarkup('When on, voters see the vote totals right after voting. When off, they only get a confirmation.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['status']
      ->setLabel(new TranslatableMarkup('Published'))
      ->setDescription(new TranslatableMarkup('Unpublished questions are hidden from voters and from the API.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => ['display_label' => TRUE],
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['uid']
      ->setLabel(new TranslatableMarkup('Author'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
