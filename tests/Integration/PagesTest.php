<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\Tests\Support\OpenApiContract;
use PHPUnit\Framework\TestCase;

final class PagesTest extends TestCase
{
    use UsesRealHindsight;

    private string $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectToHindsight();

        $version = (string) ($this->hindsight->version()['api_version'] ?? '');

        if (version_compare($version, '1.2.0', '<')) {
            $this->markTestSkipped("Pages came with Hindsight 1.2; this server is {$version}.");
        }

        $this->bank = $this->newBank();
        $this->hindsight->createBank($this->bank);
    }

    protected function tearDown(): void
    {
        $this->deleteBanks();

        parent::tearDown();
    }

    public function test_folders_and_pages(): void
    {
        $contract = OpenApiContract::v1_2();
        $pages = "/v1/default/banks/{$this->bank}/pages";

        $folder = $this->hindsight->createPageFolder($this->bank, 'Customers');
        $contract->assertServerResponse('POST', "{$pages}/folders", $this->recorder->lastBody(), 201);
        $this->assertSame('folder', $folder['kind']);

        $page = $this->hindsight->createPage($this->bank, 'Alice', 'What do we know about Alice?', parentId: $folder['id'], tags: ['user:42']);
        $contract->assertServerResponse('POST', $pages, $this->recorder->lastBody(), 201);

        $tree = $this->hindsight->getPageTree($this->bank);
        $contract->assertServerResponse('GET', "{$pages}/tree", $this->recorder->lastBody());
        $this->assertSame([$folder['id']], array_column($tree['roots'], 'id'));
        $this->assertSame([$page['page_id']], array_column($tree['roots'][0]['children'], 'id'));

        $document = $this->hindsight->getPage($this->bank, $page['page_id']);
        $contract->assertServerResponse('GET', "{$pages}/{$page['page_id']}", $this->recorder->lastBody());
        $this->assertSame('Alice', $document['name']);

        $this->hindsight->searchPages($this->bank, 'Alice', limit: 5);
        $contract->assertServerResponse('GET', "{$pages}/search", $this->recorder->lastBody());

        $bundle = $this->hindsight->exportPages($this->bank);
        $contract->assertServerResponse('GET', "{$pages}/export", $this->recorder->lastBody());
        $this->assertContains('index.md', array_column($bundle['files'], 'path'));

        $moved = $this->hindsight->updatePageNode($this->bank, $page['page_id'], ['name' => 'Alice Smith', 'parent_id' => null]);
        $contract->assertServerResponse('PATCH', "{$pages}/nodes/{$page['page_id']}", $this->recorder->lastBody());
        $this->assertSame('Alice Smith', $moved['name']);
        $this->assertNull($moved['parent_id'] ?? null);

        $this->hindsight->deletePageNode($this->bank, $folder['id']);
        $this->assertSame([$page['page_id']], array_column($this->hindsight->getPageTree($this->bank)['roots'], 'id'));
    }
}
