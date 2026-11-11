<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\Tests\Support\OpenApiContract;
use PHPUnit\Framework\TestCase;

/**
 * Creating a page needs the server's LLM, which CI runs without, so pages themselves are covered by the unit tests.
 */
final class PagesTest extends TestCase
{
    use UsesRealHindsight;

    private string $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectToHindsight();

        $version = (string) ($this->hindsight->version()['api_version'] ?? '');

        if (version_compare($version, '1.2.1', '<')) {
            $this->markTestSkipped("Pages came with Hindsight 1.2.1; this server is {$version}.");
        }

        $this->bank = $this->newBank();
        $this->hindsight->createBank($this->bank);
    }

    protected function tearDown(): void
    {
        $this->deleteBanks();

        parent::tearDown();
    }

    public function test_the_page_tree(): void
    {
        $contract = OpenApiContract::v1_2();
        $pages = "/v1/default/banks/{$this->bank}/pages";

        $customers = $this->hindsight->createPageFolder($this->bank, 'Customers');
        $contract->assertServerResponse('POST', "{$pages}/folders", $this->recorder->lastBody(), 201);
        $this->assertSame('folder', $customers['kind']);

        $vip = $this->hindsight->createPageFolder($this->bank, 'VIP', parentId: $customers['id']);

        $tree = $this->hindsight->getPageTree($this->bank);
        $contract->assertServerResponse('GET', "{$pages}/tree", $this->recorder->lastBody());
        $this->assertSame([$customers['id']], array_column($tree['roots'], 'id'));
        $this->assertSame([$vip['id']], array_column($tree['roots'][0]['children'], 'id'));

        $moved = $this->hindsight->updatePageNode($this->bank, $vip['id'], ['name' => 'Key accounts', 'parent_id' => null]);
        $contract->assertServerResponse('PATCH', "{$pages}/nodes/{$vip['id']}", $this->recorder->lastBody());
        $this->assertSame('Key accounts', $moved['name']);
        $this->assertNull($moved['parent_id'] ?? null);

        $this->hindsight->deletePageNode($this->bank, $customers['id']);
        $this->assertSame([$vip['id']], array_column($this->hindsight->getPageTree($this->bank)['roots'], 'id'));

        $this->assertSame(0, $this->hindsight->searchPages($this->bank, 'customers')['total']);
        $contract->assertServerResponse('GET', "{$pages}/search", $this->recorder->lastBody());

        $this->hindsight->exportPages($this->bank);
        $contract->assertServerResponse('GET', "{$pages}/export", $this->recorder->lastBody());
    }
}
