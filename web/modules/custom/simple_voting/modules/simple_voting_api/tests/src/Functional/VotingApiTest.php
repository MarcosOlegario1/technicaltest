<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_voting_api\Functional;

use Drupal\Tests\BrowserTestBase;
use Psr\Http\Message\ResponseInterface;

/**
 * Exercises the external voting API end to end.
 *
 * @group simple_voting
 */
class VotingApiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['simple_voting', 'simple_voting_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The question identifier used across the test.
   */
  protected string $identifier = 'best_editor';

  /**
   * The option ids created for the question.
   *
   * @var int[]
   */
  protected array $optionIds = [];

  /**
   * API user name.
   */
  protected string $userName = 'api_client';

  /**
   * API user password.
   */
  protected string $password = 'api_client_pw';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $question = $this->container->get('entity_type.manager')->getStorage('voting_question')->create([
      'question' => 'Best editor?',
      'identifier' => $this->identifier,
      'status' => TRUE,
      'show_results' => TRUE,
    ]);
    $question->save();

    foreach (['Vim', 'Emacs'] as $weight => $title) {
      $option = $this->container->get('entity_type.manager')->getStorage('voting_option')->create([
        'question' => $question->id(),
        'title' => $title,
        'weight' => $weight,
      ]);
      $option->save();
      $this->optionIds[] = (int) $option->id();
    }

    $this->drupalCreateUser(['access simple voting api'], $this->userName)
      ->setPassword($this->password)
      ->save();
  }

  /**
   * The full read, vote and results cycle.
   */
  public function testVotingCycle(): void {
    // Anonymous access is refused.
    $response = $this->request('GET', 'api/voting/questions', NULL, FALSE);
    $this->assertContains($response->getStatusCode(), [401, 403]);

    // List.
    $response = $this->request('GET', 'api/voting/questions');
    $this->assertSame(200, $response->getStatusCode());
    $body = $this->decode($response);
    $identifiers = array_column($body['data']['questions'], 'identifier');
    $this->assertContains($this->identifier, $identifiers);

    // Detail with options.
    $response = $this->request('GET', 'api/voting/questions/' . $this->identifier);
    $this->assertSame(200, $response->getStatusCode());
    $body = $this->decode($response);
    $this->assertCount(2, $body['data']['options']);

    // Unknown identifier.
    $response = $this->request('GET', 'api/voting/questions/does-not-exist');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('question_not_found', $this->decode($response)['error']['code']);

    // Vote.
    $response = $this->request('POST', 'api/voting/questions/' . $this->identifier . '/vote', [
      'option' => $this->optionIds[0],
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $body = $this->decode($response);
    $this->assertTrue($body['data']['recorded']);
    $this->assertSame(1, $body['data']['results']['total']);

    // Voting again is a conflict.
    $response = $this->request('POST', 'api/voting/questions/' . $this->identifier . '/vote', [
      'option' => $this->optionIds[1],
    ]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('already_voted', $this->decode($response)['error']['code']);

    // Results are now visible to the voter.
    $response = $this->request('GET', 'api/voting/questions/' . $this->identifier . '/results');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $this->decode($response)['data']['total']);
  }

  /**
   * Turning voting off closes every endpoint.
   */
  public function testGlobalSwitchClosesTheApi(): void {
    $this->config('simple_voting.settings')->set('voting_enabled', FALSE)->save();

    foreach (['questions', 'questions/' . $this->identifier, 'questions/' . $this->identifier . '/results'] as $path) {
      $response = $this->request('GET', 'api/voting/' . $path);
      $this->assertSame(403, $response->getStatusCode(), "GET $path is closed");
    }

    $response = $this->request('POST', 'api/voting/questions/' . $this->identifier . '/vote', [
      'option' => $this->optionIds[0],
    ]);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Sends a request to the API.
   */
  protected function request(string $method, string $path, ?array $json = NULL, bool $authenticate = TRUE): ResponseInterface {
    $options = [
      'headers' => ['Accept' => 'application/json'],
      'http_errors' => FALSE,
    ];
    if ($authenticate) {
      $options['auth'] = [$this->userName, $this->password];
    }
    if ($json !== NULL) {
      $options['headers']['Content-Type'] = 'application/json';
      $options['body'] = json_encode($json);
    }

    return $this->getHttpClient()->request($method, $this->buildUrl($path), $options);
  }

  /**
   * Decodes a JSON response body.
   */
  protected function decode(ResponseInterface $response): array {
    return json_decode((string) $response->getBody(), TRUE);
  }

}
