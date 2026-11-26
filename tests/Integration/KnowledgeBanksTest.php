<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\TagsMatch;
use Fedoseev\Hindsight\Tests\Support\OpenApiContract;
use PHPUnit\Framework\TestCase;

final class KnowledgeBanksTest extends TestCase
{
    use UsesRealHindsight;

    public function test_it_indexes_searches_and_removes_a_source_document(): void
    {
        if (getenv('HINDSIGHT_TEST_KNOWLEDGE_BANKS') !== '1') {
            $this->markTestSkipped('Enable this test with a Hindsight 1.3 server.');
        }

        $this->connectToHindsight();
        $this->hindsight->request('GET', '/openapi.json');
        $spec = $this->recorder->lastBody();
        $this->assertIsObject($spec);
        $contract = OpenApiContract::fromDocument($spec);
        $bank = 'hindsight-php-knowledge-'.bin2hex(random_bytes(6));
        $created = $this->hindsight->createKnowledgeBank($bank);

        try {
            $this->assertLastExchange($contract);
            $this->assertSame($bank, $created['bank_id']);
            $banks = $this->hindsight->listKnowledgeBanks(search: $bank, limit: 5);
            $this->assertLastExchange($contract);
            $this->assertSame([$bank], array_column($banks['banks'], 'bank_id'));

            $queued = $this->hindsight->ingestKnowledgeTexts($bank, [[
                'document_id' => 'atlas-returns',
                'filename' => 'atlas-returns.md',
                'text' => 'Atlas kettles can be returned within fourteen days of delivery.',
                'tags' => ['approved'],
            ]], async: true);
            $this->assertLastExchange($contract, status: 202);
            $this->assertSame(['atlas-returns'], $queued['document_ids']);
            $this->assertCount(1, $queued['operation_ids']);
            $operation = $this->hindsight->waitForKnowledgeBankOperation($bank, $queued['operation_ids'][0], timeout: 60);
            $this->assertLastExchange($contract);
            $this->assertTrue($operation->isCompleted());

            $details = $this->hindsight->getKnowledgeDocument($bank, 'atlas-returns');
            $this->assertLastExchange($contract);
            $this->assertSame('ready', $details['status']);
            $this->assertSame('atlas-returns', $details['document_id']);
            $documents = $this->hindsight->listKnowledgeDocuments($bank, status: 'ready', tags: ['approved'], limit: 5);
            $this->assertLastExchange($contract);
            $this->assertSame(['atlas-returns'], array_column($documents['documents'], 'document_id'));

            $search = fn (): array => $this->hindsight->searchKnowledgeBank(
                $bank, 'How many days do I have to return an Atlas kettle?', maxTokens: 512, tags: ['approved'], tagsMatch: TagsMatch::AllStrict,
                documentIds: ['atlas-returns'], options: ['enable_reranking' => false],
            );
            $hits = $search();
            $this->assertLastExchange($contract);
            $this->assertNotEmpty($hits['results']);
            $this->assertSame('atlas-returns', $hits['results'][0]['document_id']);
            $this->assertSame('atlas-returns.md', $hits['results'][0]['filename']);
            $this->assertStringContainsString('fourteen days', $hits['results'][0]['text']);

            $revised = $this->hindsight->ingestKnowledgeTexts($bank, [[
                'document_id' => 'atlas-returns',
                'filename' => 'atlas-returns.md',
                'text' => 'Atlas kettles can be returned within thirty days of delivery.',
                'tags' => ['approved'],
            ]]);
            $this->assertLastExchange($contract);
            $this->assertSame(['atlas-returns'], $revised['document_ids']);
            $this->assertSame([], $revised['operation_ids']);
            $hits = $search();
            $this->assertLastExchange($contract);
            $this->assertCount(1, $hits['results']);
            $this->assertSame('atlas-returns', $hits['results'][0]['document_id']);
            $this->assertStringContainsString('thirty days', $hits['results'][0]['text']);

            $deleted = $this->hindsight->deleteKnowledgeDocument($bank, 'atlas-returns');
            $this->assertLastExchange($contract);
            $this->assertTrue($deleted['deleted']);
            $this->assertSame(0, $this->hindsight->getKnowledgeBank($bank)['document_count']);
            $this->assertLastExchange($contract);
        } finally {
            $deletedBank = $this->hindsight->deleteKnowledgeBank($bank);
        }

        $this->assertLastExchange($contract);
        $this->assertTrue($deletedBank['deleted']);
    }

    private function assertLastExchange(OpenApiContract $contract, int $status = 200): void
    {
        $request = $this->recorder->lastRequest();
        $contract->assertRequest($request);
        $contract->assertServerResponse($request->getMethod(), $request->getUri()->getPath(), $this->recorder->lastBody(), $status);
    }
}
