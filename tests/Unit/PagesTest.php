<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\TagsMatch;
use Fedoseev\Hindsight\Tests\Support\OpenApiContract;

final class PagesTest extends ClientTestCase
{
    private const FOLDER = ['id' => 'f1', 'kind' => 'folder', 'name' => 'Customers', 'parent_id' => null, 'children' => []];

    private const PAGE = ['id' => 'p1', 'kind' => 'page', 'name' => 'Alice', 'parent_id' => 'f1', 'mental_model_id' => 'mm1', 'tags' => ['user:42'], 'children' => []];

    public function test_folders_and_the_tree(): void
    {
        $this->respond('POST', '/v1/default/banks/support/pages/folders', self::FOLDER, 201);
        $this->assertSame('f1', $this->hindsight->createPageFolder('support', 'Customers')['id']);
        $this->assertSent('POST', '/v1/default/banks/support/pages/folders');
        $this->assertSame(['name' => 'Customers'], $this->http->lastJson());

        $this->respond('POST', '/v1/default/banks/support/pages/folders', [...self::FOLDER, 'id' => 'f2', 'parent_id' => 'f1'], 201);
        $this->hindsight->createPageFolder('support', 'VIP', parentId: 'f1');
        $this->assertSame(['name' => 'VIP', 'parent_id' => 'f1'], $this->http->lastJson());

        $this->respond('GET', '/v1/default/banks/support/pages/tree', ['roots' => [[...self::FOLDER, 'children' => [self::PAGE]]]]);
        $tree = $this->hindsight->getPageTree('support', tags: ['user:42'], tagsMatch: TagsMatch::AnyStrict);
        $this->assertSame('Alice', $tree['roots'][0]['children'][0]['name']);
        $this->assertSent('GET', '/v1/default/banks/support/pages/tree?tags=user%3A42&tags_match=any_strict');
    }

    public function test_pages(): void
    {
        $this->respond('POST', '/v1/default/banks/support/pages', ['page_id' => 'p1', 'mental_model_id' => 'mm1', 'operation_id' => 'op-1'], 201);
        $created = $this->hindsight->createPage('support', 'Alice', 'What do we know about Alice?', parentId: 'f1', tags: ['user:42'], maxTokens: 1024);
        $this->assertSame('op-1', $created['operation_id']);
        $this->assertSent('POST', '/v1/default/banks/support/pages');
        $this->assertSame(['name' => 'Alice', 'source_query' => 'What do we know about Alice?', 'parent_id' => 'f1', 'tags' => ['user:42'], 'max_tokens' => 1024], $this->http->lastJson());

        $this->respond('GET', '/v1/default/banks/support/pages/p1', ['id' => 'p1', 'name' => 'Alice', 'type' => 'page', 'tags' => ['user:42'], 'markdown' => "---\nname: Alice\n---\nAlice is on the Pro plan."]);
        $this->assertStringEndsWith('Alice is on the Pro plan.', $this->hindsight->getPage('support', 'p1')['markdown']);
        $this->assertSent('GET', '/v1/default/banks/support/pages/p1');

        $this->respond('GET', '/v1/default/banks/support/pages/search', ['results' => [['id' => 'p1', 'name' => 'Alice', 'snippet' => 'Alice is on the Pro plan.', 'score' => 0.03]], 'total' => 1]);
        $this->assertSame(1, $this->hindsight->searchPages('support', 'pro plan', limit: 5)['total']);
        $this->assertSent('GET', '/v1/default/banks/support/pages/search?q=pro%20plan&limit=5');

        $this->respond('GET', '/v1/default/banks/support/pages/export', ['files' => [['path' => 'index.md', 'content' => '- [Alice](p1.md)']]]);
        $this->assertSame('index.md', $this->hindsight->exportPages('support')['files'][0]['path']);
        $this->assertSent('GET', '/v1/default/banks/support/pages/export');
    }

    public function test_nodes(): void
    {
        $this->respond('PATCH', '/v1/default/banks/support/pages/nodes/p1', [...self::PAGE, 'parent_id' => null]);
        $this->hindsight->updatePageNode('support', 'p1', ['name' => 'Alice Smith', 'parent_id' => null]);
        $this->assertSent('PATCH', '/v1/default/banks/support/pages/nodes/p1');
        // A null parent moves the node to the root, so it has to be sent.
        $this->assertSame(['name' => 'Alice Smith', 'parent_id' => null], $this->http->lastJson());

        $this->http->json(['success' => true]);
        $this->hindsight->deletePageNode('support', 'f1');
        $this->assertSent('DELETE', '/v1/default/banks/support/pages/nodes/f1');
    }

    public function test_pages_are_checked_against_the_document_that_has_them(): void
    {
        $this->assertSame('1.1.0', OpenApiContract::for('POST', '/v1/default/banks/support/memories')->version());
        $this->assertSame('1.2.1', OpenApiContract::for('GET', '/v1/default/banks/support/pages/tree')->version());
    }
}
