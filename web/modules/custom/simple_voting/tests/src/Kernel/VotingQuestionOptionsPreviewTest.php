<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_voting\Kernel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\simple_voting\Traits\VotingTestEntitiesTrait;
use Drupal\file\Entity\File;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the read-only options preview shown on the question's view page.
 *
 * The PDF asks every option to carry an image, a title and a description;
 * the default entity view builder only has a display for the question text,
 * so simple_voting_voting_question_view() is what actually puts the options
 * on the page.
 *
 * @group simple_voting
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class VotingQuestionOptionsPreviewTest extends KernelTestBase {

  use VotingTestEntitiesTrait;

  /**
   * A minimal 1x1 PNG, used as a real file for the image field.
   */
  private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'options',
    'simple_voting',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('voting_question');
    $this->installEntitySchema('voting_option');
    $this->installEntitySchema('vote');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['simple_voting', 'image']);
  }

  /**
   * The preview lists every option with its title, description and image.
   */
  public function testPreviewIncludesTitleDescriptionAndImage(): void {
    [$question, $options] = $this->createQuestion('colours', ['Red', 'Green']);

    $options[0]->set('description', 'The colour of fire.');
    $options[0]->set('image', ['target_id' => $this->createImageFile()->id()]);
    $options[0]->save();

    $build = [];
    simple_voting_voting_question_view($build, $question, $this->createMock(EntityViewDisplayInterface::class), 'full');

    $this->assertSame('simple_voting_question_options', $build['simple_voting_options_preview']['#theme']);
    $preview_options = $build['simple_voting_options_preview']['#options'];

    $this->assertCount(2, $preview_options);
    $this->assertSame('Red', $preview_options[0]['title']);
    $this->assertSame('The colour of fire.', $preview_options[0]['description']);
    $this->assertNotEmpty($preview_options[0]['image']);

    $this->assertSame('Green', $preview_options[1]['title']);
    $this->assertSame('', $preview_options[1]['description']);
    $this->assertNull($preview_options[1]['image']);
  }

  /**
   * The hook does nothing for view modes other than "full", or new entities.
   */
  public function testPreviewOnlyAppliesToTheFullViewMode(): void {
    [$question] = $this->createQuestion('teams', ['A', 'B']);

    $build = [];
    simple_voting_voting_question_view($build, $question, $this->createMock(EntityViewDisplayInterface::class), 'teaser');
    $this->assertArrayNotHasKey('simple_voting_options_preview', $build);
  }

  /**
   * Creates and saves a tiny permanent image file.
   */
  private function createImageFile(): File {
    $uri = 'public://swatch.png';
    \Drupal::service('file_system')->saveData(base64_decode(self::TINY_PNG_BASE64), $uri);

    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();

    return $file;
  }

}
