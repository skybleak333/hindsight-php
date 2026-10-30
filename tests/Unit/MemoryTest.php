<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Responses\RecallResult;
use Fedoseev\Hindsight\TagsMatch;

final class MemoryTest extends ClientTestCase
{
    public function test_retain_stores_one_item(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories', [
            'success' => true, 'bank_id' => 'b1', 'items_count' => 1, 'async' => false,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30, 'total_tokens' => 150],
        ]);

        $response = $this->hindsight->retain('b1', 'Alice works at Google as a software engineer');

        $this->assertSent('POST', '/v1/default/banks/b1/memories');
        $this->assertSame([
            'items' => [['content' => 'Alice works at Google as a software engineer']],
            'async' => false,
        ], $this->http->lastJson());

        $this->assertTrue($response->success);
        $this->assertSame('b1', $response->bankId);
        $this->assertSame(1, $response->itemsCount);
        $this->assertFalse($response->async);
        $this->assertNull($response->operationId);
        $this->assertSame(150, $response->usage['total_tokens'] ?? null);
    }

    public function test_retain_sends_every_option_with_the_api_field_names(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories', [
            'success' => true, 'bank_id' => 'b1', 'items_count' => 1, 'async' => true,
            'operation_id' => '0b6f1a52-4f0b-4b53-9a59-6a0a8c4c2f10',
        ]);

        $response = $this->hindsight->retain(
            'b1',
            'Alice moved to Berlin',
            timestamp: new DateTimeImmutable('2026-03-01 09:30:00', new DateTimeZone('Europe/Berlin')),
            context: 'chat with Alice',
            documentId: 'conversation-42',
            metadata: ['channel' => 'support'],
            entities: [['text' => 'Alice', 'type' => 'person']],
            tags: ['user:42'],
            updateMode: 'append',
            async: true,
            operationId: '0b6f1a52-4f0b-4b53-9a59-6a0a8c4c2f10',
            strategy: 'conversation',
        );

        $this->assertSame([
            'items' => [[
                'content' => 'Alice moved to Berlin',
                'timestamp' => '2026-03-01T09:30:00+01:00',
                'context' => 'chat with Alice',
                'metadata' => ['channel' => 'support'],
                'document_id' => 'conversation-42',
                'entities' => [['text' => 'Alice', 'type' => 'person']],
                'tags' => ['user:42'],
                'strategy' => 'conversation',
                'update_mode' => 'append',
            ]],
            'async' => true,
            'operation_id' => '0b6f1a52-4f0b-4b53-9a59-6a0a8c4c2f10',
        ], $this->http->lastJson());

        $this->assertTrue($response->async);
        $this->assertSame('0b6f1a52-4f0b-4b53-9a59-6a0a8c4c2f10', $response->operationId);
    }

    public function test_retain_sends_empty_metadata_as_an_object(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories', ['success' => true, 'bank_id' => 'b1', 'items_count' => 1, 'async' => false]);

        $this->hindsight->retain('b1', 'Alice likes tea', metadata: []);

        $this->assertStringContainsString('"metadata":{}', (string) $this->http->lastRequest()->getBody());
    }

    public function test_sync_retain_does_not_send_an_operation_id(): void
    {
        // The API only reads operation_id for async retains; the official clients drop it too.
        $this->respond('POST', '/v1/default/banks/b1/memories', ['success' => true, 'bank_id' => 'b1', 'items_count' => 1, 'async' => false]);

        $this->hindsight->retain('b1', 'Alice likes tea', operationId: '0b6f1a52-4f0b-4b53-9a59-6a0a8c4c2f10');

        $this->assertArrayNotHasKey('operation_id', $this->http->lastJson() ?? []);
    }

    public function test_retain_batch_maps_camel_case_keys_and_keeps_api_keys(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories', ['success' => true, 'bank_id' => 'b1', 'items_count' => 2, 'async' => true, 'operation_id' => 'op-1']);

        $response = $this->hindsight->retainBatch('b1', [
            ['content' => 'Alice likes tea', 'documentId' => 'doc-1', 'timestamp' => new DateTimeImmutable('2026-01-02T03:04:05Z')],
            ['content' => 'Bob likes coffee', 'document_id' => 'doc-2', 'observation_scopes' => 'per_tag', 'tags' => ['team']],
        ], async: true);

        $this->assertSame([
            'items' => [
                ['content' => 'Alice likes tea', 'document_id' => 'doc-1', 'timestamp' => '2026-01-02T03:04:05+00:00'],
                ['content' => 'Bob likes coffee', 'document_id' => 'doc-2', 'observation_scopes' => 'per_tag', 'tags' => ['team']],
            ],
            'async' => true,
        ], $this->http->lastJson());

        $this->assertSame(2, $response->itemsCount);
    }

    public function test_recall_sends_the_query_and_maps_results(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories/recall', [
            'results' => [
                [
                    'id' => 'f1', 'text' => 'Alice works at Google', 'type' => 'world',
                    'context' => 'onboarding call', 'occurred_start' => '2026-01-10T00:00:00Z',
                    'mentioned_at' => '2026-01-11T12:00:00Z', 'document_id' => 'doc-1',
                    'tags' => ['user:42'], 'entities' => ['Alice', 'Google'], 'metadata' => ['source' => 'crm'],
                ],
                ['id' => 'f2', 'text' => 'Alice prefers short answers', 'type' => 'experience'],
            ],
        ]);

        $response = $this->hindsight->recall(
            'b1',
            'What does Alice do?',
            types: ['world', 'experience'],
            budget: Budget::High,
            maxTokens: 1024,
            tags: ['user:42'],
            tagsMatch: TagsMatch::AnyStrict,
        );

        $this->assertSent('POST', '/v1/default/banks/b1/memories/recall');
        $this->assertSame([
            'query' => 'What does Alice do?',
            'budget' => 'high',
            'max_tokens' => 1024,
            'types' => ['world', 'experience'],
            'tags' => ['user:42'],
            'tags_match' => 'any_strict',
        ], $this->http->lastJson());

        $this->assertCount(2, $response);
        $this->assertSame(['Alice works at Google', 'Alice prefers short answers'], $response->texts());

        $first = $response->results[0];
        $this->assertInstanceOf(RecallResult::class, $first);
        $this->assertSame('f1', $first->id);
        $this->assertSame('world', $first->type);
        $this->assertSame('onboarding call', $first->context);
        $this->assertSame('2026-01-10T00:00:00Z', $first->occurredStart);
        $this->assertSame('2026-01-11T12:00:00Z', $first->mentionedAt);
        $this->assertSame('doc-1', $first->documentId);
        $this->assertSame(['user:42'], $first->tags);
        $this->assertSame(['source' => 'crm'], $first->metadata);
        $this->assertSame('Alice works at Google', (string) $first);
    }

    public function test_recall_uses_api_defaults_when_nothing_is_set(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories/recall', ['results' => []]);

        $response = $this->hindsight->recall('b1', 'anything');

        $this->assertSame(['query' => 'anything', 'budget' => 'mid', 'max_tokens' => 4096], $this->http->lastJson());
        $this->assertTrue($response->isEmpty());
    }

    public function test_recall_accepts_plain_strings_and_extra_options(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories/recall', ['results' => []]);

        $this->hindsight->recall('b1', 'anything', budget: 'low', tagsMatch: 'all', tags: ['a'], queryTimestamp: new DateTimeImmutable('2026-05-30T23:40:00Z'), options: [
            'include' => ['entities' => null, 'chunks' => ['max_tokens' => 2000]],
            'min_scores' => ['final' => 0.5],
        ]);

        $this->assertSame([
            'query' => 'anything',
            'budget' => 'low',
            'max_tokens' => 4096,
            'tags' => ['a'],
            'tags_match' => 'all',
            'query_timestamp' => '2026-05-30T23:40:00+00:00',
            'include' => ['entities' => null, 'chunks' => ['max_tokens' => 2000]],
            'min_scores' => ['final' => 0.5],
        ], $this->http->lastJson());
    }

    public function test_recall_formats_results_for_a_prompt(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories/recall', ['results' => [
            ['id' => 'f1', 'text' => 'Alice works at Google', 'occurred_start' => '2026-01-10T00:00:00Z'],
            ['id' => 'f2', 'text' => 'Alice prefers short answers'],
        ]]);

        $this->assertSame(
            "- Alice works at Google (2026-01-10)\n- Alice prefers short answers",
            $this->hindsight->recall('b1', 'Alice')->toPrompt(),
        );
    }

    public function test_reflect_returns_the_answer(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/reflect', [
            'text' => 'Alice is a software engineer at Google.',
            'based_on' => ['memories' => [['id' => 'f1', 'text' => 'Alice works at Google']]],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 40, 'total_tokens' => 940],
        ]);

        $response = $this->hindsight->reflect('b1', 'Tell me about Alice', budget: Budget::Mid, maxTokens: 800, includeFacts: true, tags: ['user:42']);

        $this->assertSent('POST', '/v1/default/banks/b1/reflect');
        $this->assertSame([
            'query' => 'Tell me about Alice',
            'budget' => 'mid',
            'max_tokens' => 800,
            'tags' => ['user:42'],
            'include' => ['facts' => []],
        ], $this->http->lastJson());
        $this->assertStringContainsString('"include":{"facts":{}}', (string) $this->http->lastRequest()->getBody());

        $this->assertSame('Alice is a software engineer at Google.', $response->text);
        $this->assertSame('Alice is a software engineer at Google.', (string) $response);
        $this->assertSame('f1', $response->basedOn['memories'][0]['id'] ?? null);
        $this->assertSame(940, $response->usage['total_tokens'] ?? null);
    }

    public function test_reflect_can_return_structured_output(): void
    {
        $schema = ['type' => 'object', 'properties' => ['employer' => ['type' => 'string']], 'required' => ['employer']];

        $this->respond('POST', '/v1/default/banks/b1/reflect', [
            'text' => '{"employer": "Google"}',
            'structured_output' => ['employer' => 'Google'],
        ]);

        $response = $this->hindsight->reflect('b1', 'Where does Alice work?', responseSchema: $schema);

        $this->assertSame(['query' => 'Where does Alice work?', 'budget' => 'low', 'response_schema' => $schema], $this->http->lastJson());
        $this->assertSame(['employer' => 'Google'], $response->structuredOutput);
        $this->assertNull($response->structuredOutputError);
    }

    public function test_list_memories_sends_list_parameters_the_way_fastapi_reads_them(): void
    {
        $this->http->json(['items' => [], 'total' => 0, 'limit' => 20, 'offset' => 40]);

        $this->hindsight->listMemories('b1', type: 'world', search: 'Alice', tags: ['user:42', 'vip'], limit: 20, offset: 40);

        $this->assertSent('GET', '/v1/default/banks/b1/memories/list?type=world&q=Alice&tags=user%3A42&tags=vip&limit=20&offset=40');
    }

    public function test_get_and_clear_memories(): void
    {
        $this->http->json(['id' => 'f1', 'text' => 'Alice works at Google']);
        $this->assertSame('f1', $this->hindsight->getMemory('b1', 'f1')['id']);
        $this->assertSent('GET', '/v1/default/banks/b1/memories/f1');

        $this->respond('DELETE', '/v1/default/banks/b1/memories', ['success' => true, 'deleted_count' => 3]);
        $this->assertSame(3, $this->hindsight->clearMemories('b1', type: 'experience')['deleted_count']);
        $this->assertSent('DELETE', '/v1/default/banks/b1/memories?type=experience');
    }
}
