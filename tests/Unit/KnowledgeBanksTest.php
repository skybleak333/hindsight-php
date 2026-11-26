<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Exceptions\OperationFailedException;
use Fedoseev\Hindsight\TagsMatch;

final class KnowledgeBanksTest extends ClientTestCase
{
    // The existing fixtures predate the 1.3 routes. The integration suite checks the live schema.
    protected bool $checkContract = false;

    private const PATH = '/v1/default/knowledge-banks';

    public function test_it_manages_knowledge_banks_on_a_separate_path(): void
    {
        $bank = ['bank_id' => 'product-manuals', 'document_count' => 0];
        $this->http->json($bank);
        $this->assertSame($bank, $this->hindsight->createKnowledgeBank('product-manuals'));
        $this->assertSent('POST', self::PATH);
        $this->assertSame(['bank_id' => 'product-manuals'], $this->http->lastJson());

        $page = ['banks' => [$bank], 'total' => 2, 'limit' => 1, 'offset' => 1];
        $this->http->json($page);
        $this->assertSame($page, $this->hindsight->listKnowledgeBanks(search: 'product', limit: 1, offset: 1));
        $this->assertSent('GET', self::PATH.'?q=product&limit=1&offset=1');

        $this->http->json($bank);
        $this->assertSame($bank, $this->hindsight->getKnowledgeBank('product-manuals'));
        $this->assertSent('GET', self::PATH.'/product-manuals');

        $this->http->json(['deleted' => true]);
        $this->assertTrue($this->hindsight->deleteKnowledgeBank('product-manuals')['deleted']);
        $this->assertSent('DELETE', self::PATH.'/product-manuals');
    }

    public function test_it_ingests_markdown_synchronously_or_queues_it(): void
    {
        $items = [['document_id' => 'returns', 'text' => '# Returns', 'filename' => 'returns.md', 'tags' => ['approved']]];
        $complete = ['document_ids' => ['returns'], 'operation_ids' => [], 'unchanged_document_ids' => []];
        $this->http->json($complete);
        $this->assertSame($complete, $this->hindsight->ingestKnowledgeTexts('manuals', $items));
        $this->assertSent('POST', self::PATH.'/manuals/ingest');
        $this->assertSame(['items' => $items, 'async' => false], $this->http->lastJson());

        $queued = [...$complete, 'operation_ids' => ['op-1']];
        $this->http->json($queued, status: 202);
        $this->assertSame($queued, $this->hindsight->ingestKnowledgeTexts('manuals', $items, async: true));
        $this->assertSent('POST', self::PATH.'/manuals/ingest');
        $this->assertSame(['items' => $items, 'async' => true], $this->http->lastJson());
    }

    public function test_it_searches_with_document_filters_and_keeps_source_fields(): void
    {
        $result = ['results' => [[
            'document_id' => 'returns',
            'filename' => 'returns.md',
            'index' => 0,
            'text' => 'Fourteen days.',
            'document_url' => self::PATH.'/manuals/documents/returns/file',
        ]], 'trace' => null];
        $this->http->json($result);
        $this->assertSame($result, $this->hindsight->searchKnowledgeBank(
            'manuals', 'Returns?', maxTokens: 512, tags: ['approved'], tagsMatch: TagsMatch::AllStrict,
            documentIds: ['returns'], options: ['enable_reranking' => false],
        ));
        $this->assertSent('POST', self::PATH.'/manuals/search');
        $this->assertSame([
            'query' => 'Returns?', 'max_tokens' => 512, 'tags' => ['approved'],
            'tags_match' => 'all_strict', 'document_ids' => ['returns'], 'enable_reranking' => false,
        ], $this->http->lastJson());
    }

    public function test_it_lists_reads_and_deletes_source_documents(): void
    {
        $path = self::PATH.'/manuals/documents';
        $page = ['documents' => [['document_id' => 'returns 2', 'status' => 'ready']], 'total' => 1];
        $this->http->json($page);
        $this->assertSame($page, $this->hindsight->listKnowledgeDocuments(
            'manuals', search: 'returns', status: 'ready', mediaType: 'text', tags: ['a', 'b'], limit: 5, offset: 0,
        ));
        $this->assertSent('GET', $path.'?q=returns&status=ready&media_type=text&tags=a&tags=b&limit=5&offset=0');

        $detail = [...$page['documents'][0], 'passages' => [['index' => 0, 'text' => 'Fourteen days.']]];
        $this->http->json($detail);
        $this->assertSame($detail, $this->hindsight->getKnowledgeDocument('manuals', 'returns 2'));
        $this->assertSent('GET', $path.'/returns%202');

        $this->http->json(['deleted' => true]);
        $this->assertTrue($this->hindsight->deleteKnowledgeDocument('manuals', 'returns 2')['deleted']);
        $this->assertSent('DELETE', $path.'/returns%202');
    }

    public function test_it_polls_the_knowledge_bank_operation_to_completion(): void
    {
        $this->http
            ->json(['operation_id' => 'op-1', 'status' => 'processing'])
            ->json(['operation_id' => 'op-1', 'status' => 'completed']);
        $operation = $this->hindsight->waitForKnowledgeBankOperation('manuals', 'op-1', timeout: 5, interval: 0.001);

        $this->assertTrue($operation->isCompleted());
        $this->assertSame('op-1', $operation->id);
        $this->assertCount(2, $this->http->requests);
        foreach ($this->http->requests as $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(self::PATH.'/manuals/operations/op-1', $request->getUri()->getPath());
        }
    }

    public function test_it_reports_failed_knowledge_ingests(): void
    {
        $this->http->json(['operation_id' => 'op-1', 'status' => 'failed', 'error_message' => 'Indexing failed']);

        $this->expectException(OperationFailedException::class);
        $this->expectExceptionMessage('Indexing failed');

        $this->hindsight->waitForKnowledgeBankOperation('manuals', 'op-1');
    }
}
