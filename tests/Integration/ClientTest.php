<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\Exceptions\ApiException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use Fedoseev\Hindsight\TagsMatch;
use Fedoseev\Hindsight\Tests\Support\ArrayLogger;
use Fedoseev\Hindsight\Tests\Support\OpenApiContract;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    use UsesRealHindsight;

    private string $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectToHindsight();
        $this->bank = $this->newBank();
    }

    protected function tearDown(): void
    {
        $this->deleteBanks();

        parent::tearDown();
    }

    public function test_the_server_reports_its_version(): void
    {
        $version = $this->hindsight->version();

        OpenApiContract::instance()->assertResponse('GET', '/version', $this->recorder->lastBody());
        $this->assertMatchesRegularExpression('/^1\.\d+\.\d+/', $version['api_version']);
        $this->assertIsArray($this->hindsight->health());
    }

    public function test_recall_on_a_bank_nobody_wrote_to(): void
    {
        // The docs say reads of a missing bank answer 404; the middleware relies on recognising that answer.
        try {
            $this->assertTrue($this->hindsight->recall($this->bank, 'anything')->isEmpty());
        } catch (NotFoundException $exception) {
            $this->assertTrue(Memory::isMissingBank($exception, $this->bank), $exception->getMessage());
        }
    }

    public function test_the_retired_profile_endpoint_answers_410(): void
    {
        // Why the client has no getBankProfile(): since Hindsight 1.1 the server always refuses it.
        $this->hindsight->createBank($this->bank);

        try {
            $this->hindsight->request('GET', "/v1/default/banks/{$this->bank}/profile");
            $this->fail('The profile endpoint should be gone.');
        } catch (ApiException $exception) {
            $this->assertSame(410, $exception->statusCode);
        }
    }

    public function test_bank_lifecycle(): void
    {
        $profile = $this->hindsight->createBank($this->bank, name: 'PHP client test', reflectMission: 'Remember test facts.');
        OpenApiContract::instance()->assertResponse('PUT', "/v1/default/banks/{$this->bank}", $this->recorder->lastBody());
        $this->assertSame('PHP client test', $profile['name']);

        $this->hindsight->updateBank($this->bank, reflectMission: 'Remember updated test facts.');
        $this->assertSame('Remember updated test facts.', $this->hindsight->getBankConfig($this->bank)['config']['reflect_mission'] ?? null);

        $banks = $this->hindsight->listBanks(search: $this->bank);
        $this->assertContains($this->bank, array_column($banks['banks'], 'bank_id'));

        $config = $this->hindsight->updateBankConfig($this->bank, ['retain_chunk_size' => 1500]);
        $this->assertSame(1500, $config['overrides']['retain_chunk_size'] ?? null);
        $this->assertArrayNotHasKey('retain_chunk_size', $this->hindsight->resetBankConfig($this->bank)['overrides']);

        $this->hindsight->getBankStats($this->bank);
        OpenApiContract::instance()->assertResponse('GET', "/v1/default/banks/{$this->bank}/stats", $this->recorder->lastBody());

        $this->assertTrue($this->hindsight->deleteBank($this->bank)['success']);
    }

    public function test_retained_text_can_be_recalled(): void
    {
        $retained = $this->hindsight->retain($this->bank, 'Alice works at Google as a software engineer.', context: 'integration test');

        OpenApiContract::instance()->assertResponse('POST', "/v1/default/banks/{$this->bank}/memories", $this->recorder->lastBody());
        $this->assertTrue($retained->success);
        $this->assertSame(1, $retained->itemsCount);
        $this->assertFalse($retained->async);

        $recalled = $this->hindsight->recall($this->bank, 'Where does Alice work?');

        OpenApiContract::instance()->assertResponse('POST', "/v1/default/banks/{$this->bank}/memories/recall", $this->recorder->lastBody());
        $this->assertNotEmpty($recalled->results);
        $this->assertStringContainsString('Google', implode("\n", $recalled->texts()));
        $this->assertNotSame('', $recalled->results[0]->id);
    }

    public function test_an_async_retain_can_be_awaited(): void
    {
        $retained = $this->hindsight->retain($this->bank, 'Bob prefers green tea over coffee.', async: true);

        $this->assertTrue($retained->async);
        $this->assertNotNull($retained->operationId);

        $operation = $this->hindsight->waitForOperation($this->bank, $retained->operationId, timeout: 60, interval: 0.25);

        OpenApiContract::instance()->assertResponse('GET', "/v1/default/banks/{$this->bank}/operations/{$operation->id}", $this->recorder->lastBody());
        $this->assertTrue($operation->isCompleted());
        $this->assertStringContainsString('green tea', implode("\n", $this->hindsight->recall($this->bank, 'What does Bob drink?')->texts()));
    }

    public function test_tags_keep_memories_of_different_users_apart(): void
    {
        $this->hindsight->retainBatch($this->bank, [
            ['content' => 'This customer drinks tea every morning.', 'tags' => ['user:alice']],
            ['content' => 'This customer drinks coffee every morning.', 'tags' => ['user:bob']],
        ]);

        $texts = implode("\n", $this->hindsight->recall($this->bank, 'What does the customer drink in the morning?', tags: ['user:alice'], tagsMatch: TagsMatch::AnyStrict)->texts());

        $this->assertStringContainsString('tea', $texts);
        $this->assertStringNotContainsString('coffee', $texts);
    }

    public function test_documents_can_be_listed_read_and_deleted(): void
    {
        $this->hindsight->retain($this->bank, 'The office moves to Amsterdam in March.', documentId: 'office-news');

        $this->assertContains('office-news', array_column($this->hindsight->listDocuments($this->bank)['items'], 'id'));
        $this->assertSame('office-news', $this->hindsight->getDocument($this->bank, 'office-news')['id']);

        $deleted = $this->hindsight->deleteDocument($this->bank, 'office-news');
        $this->assertTrue($deleted['success']);

        $this->expectException(NotFoundException::class);
        $this->hindsight->getDocument($this->bank, 'office-news');
    }

    public function test_memories_can_be_listed_and_cleared(): void
    {
        $this->hindsight->retain($this->bank, 'Carol manages the Berlin team.');

        $this->assertGreaterThanOrEqual(1, $this->hindsight->listMemories($this->bank, limit: 10)['total']);

        $this->hindsight->clearMemories($this->bank);

        $this->assertSame(0, $this->hindsight->listMemories($this->bank, limit: 10)['total']);
    }

    public function test_directives_crud(): void
    {
        $this->hindsight->createBank($this->bank);

        $directive = $this->hindsight->createDirective($this->bank, 'Tone', 'Answer politely.', priority: 5, tags: ['tone']);
        $this->assertSame('Tone', $directive['name']);

        $this->assertContains($directive['id'], array_column($this->hindsight->listDirectives($this->bank)['items'], 'id'));

        $this->assertFalse($this->hindsight->updateDirective($this->bank, $directive['id'], ['is_active' => false])['is_active']);

        $this->hindsight->deleteDirective($this->bank, $directive['id']);

        $this->expectException(NotFoundException::class);
        $this->hindsight->getDirective($this->bank, $directive['id']);
    }

    public function test_non_ascii_text_survives_the_round_trip(): void
    {
        $this->hindsight->retain($this->bank, 'Офис переезжает в Амстердам в марте.');

        $this->assertStringContainsString('Амстердам', implode("\n", $this->hindsight->recall($this->bank, 'Амстердам')->texts()));
    }

    public function test_the_server_names_parameters_it_ignored(): void
    {
        $logger = new ArrayLogger;
        $client = new Hindsight((string) (getenv('HINDSIGHT_TEST_URL') ?: 'http://127.0.0.1:8888'), apiKey: getenv('HINDSIGHT_TEST_API_KEY') ?: null, logger: $logger);

        // Reads of a bank nobody wrote to answer 404, so store something first.
        $client->retain($this->bank, 'Frank likes jazz.');
        $client->recall($this->bank, 'anything', options: ['max_token' => 10]);

        $this->assertCount(1, $logger->records);
        $this->assertSame(['max_token'], $logger->records[0]['context']['parameters']);
    }

    public function test_reflect_reports_that_the_server_has_no_llm(): void
    {
        $this->hindsight->retain($this->bank, 'Dave likes chess.');

        try {
            $this->hindsight->reflect($this->bank, 'What does Dave like?');
            $this->fail('Reflect should fail on a server without an LLM.');
        } catch (ApiException $exception) {
            $this->assertSame(400, $exception->statusCode);
            $this->assertStringStartsWith('Hindsight API error 400: ', $exception->getMessage());
        }
    }
}
