<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_voting\Form\VotingOptionDeleteForm;
use Drupal\simple_voting\Form\VotingOptionForm;
use Drupal\simple_voting\VotingOptionAccessControlHandler;

/**
 * Defines the voting option entity.
 */
#[ContentEntityType(
  id: 'voting_option',
  label: new TranslatableMarkup('Answer option'),
  label_collection: new TranslatableMarkup('Answer options'),
  label_singular: new TranslatableMarkup('answer option'),
  label_plural: new TranslatableMarkup('answer options'),
  label_count: [
    'singular' => '@count answer option',
    'plural' => '@count answer options',
  ],
  handlers: [
    'view_builder' => EntityViewBuilder::class,
    'access' => VotingOptionAccessControlHandler::class,
    'form' => [
      'default' => VotingOptionForm::class,
      'add' => VotingOptionForm::class,
      'edit' => VotingOptionForm::class,
      'delete' => VotingOptionDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'voting_option',
  admin_permission: 'administer simple voting',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  links: [
    'canonical' => '/admin/content/voting-option/{voting_option}',
    'edit-form' => '/admin/content/voting-option/{voting_option}/edit',
    'delete-form' => '/admin/content/voting-option/{voting_option}/delete',
  ],
)]
class VotingOption extends ContentEntityBase implements VotingOptionInterface {

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
  public function getTitle(): string {
    return (string) $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->get('description')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return (int) $this->get('weight')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // The parent question is set from the route when adding an option, so it
    // has no widget of its own.
    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Question'))
      ->setDescription(new TranslatableMarkup('The question this option answers.'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('A short description shown next to the option.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 5,
        'settings' => ['rows' => 3],
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'basic_string',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(new TranslatableMarkup('Image'))
      ->setDescription(new TranslatableMarkup('Optional image shown with this option.'))
      ->setSettings([
        'file_directory' => 'voting/options',
        'file_extensions' => 'png jpg jpeg webp',
        'max_filesize' => '5 MB',
        'alt_field' => TRUE,
        'alt_field_required' => FALSE,
      ])
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'image',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Weight'))
      ->setDescription(new TranslatableMarkup('Options with a lower weight are shown first.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
