<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Laravel\Facades\Hindsight;
use Fedoseev\Hindsight\Laravel\Mcp\Tools\Recall;
use Fedoseev\Hindsight\Laravel\Mcp\Tools\Reflect;
use Fedoseev\Hindsight\Laravel\Mcp\Tools\Retain;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\MemoryServer;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

final class McpToolsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), McpServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHindsight();
    }

    protected function tearDown(): void
    {
        MemoryServer::$memoryTools = [];

        parent::tearDown();
    }

    public function test_recall_returns_memories_to_the_client(): void
    {
        $this->queueRecall([
            ['id' => 'f1', 'text' => 'Alice is on the Pro plan', 'occurred_start' => '2026-09-01T00:00:00Z'],
            ['id' => 'f2', 'text' => 'Alice prefers email'],
        ]);
        $recall = $this->serve(Recall::bank('b')->tags(['user:42'])->budget(Budget::Mid, maxTokens: 2000));

        MemoryServer::tool($recall, ['query' => 'Alice plan'])
            ->assertOk()
            ->assertSee("Relevant memories, most relevant first:\n- Alice is on the Pro plan (2026-09-01)\n- Alice prefers email");

        $this->assertSame(['query' => 'Alice plan', 'budget' => 'mid', 'max_tokens' => 2000, 'tags' => ['user:42'], 'tags_match' => 'any_strict'], $this->sentJson(0));
    }

    public function test_the_bank_can_be_picked_for_each_call(): void
    {
        $this->queueRecall([]);
        $recall = $this->serve(Recall::bank(fn (Request $request): string => 'user-'.$request->user()->getAuthIdentifier()));

        MemoryServer::actingAs($this->user(7))->tool($recall, ['query' => 'Alice'])
            ->assertOk()
            ->assertSee('No relevant memories found.');

        $this->assertSame(['POST /v1/default/banks/user-7/memories/recall'], $this->sentPaths());
    }

    public function test_retain_stores_what_the_client_sends(): void
    {
        $this->queueRetain();
        $retain = $this->serve(Retain::bank('b')->tags(['user:42']));

        MemoryServer::tool($retain, ['content' => 'Alice moved to Berlin', 'context' => 'Alice said so in chat'])
            ->assertOk()
            ->assertSee('Stored in memory.');

        $this->assertSame([
            'items' => [['content' => 'Alice moved to Berlin', 'context' => 'Alice said so in chat', 'tags' => ['user:42']]],
            'async' => true,
        ], $this->sentJson(0));
    }

    public function test_reflect_returns_the_answer(): void
    {
        $this->http->json(['text' => 'Alice is a Pro customer who prefers email.']);
        $reflect = $this->serve(Reflect::bank('b')->budget(Budget::Mid));

        MemoryServer::tool($reflect, ['query' => 'Summarise Alice'])
            ->assertOk()
            ->assertSee('Alice is a Pro customer who prefers email.');

        $this->assertSame(['query' => 'Summarise Alice', 'budget' => 'mid'], $this->sentJson(0));
    }

    public function test_missing_arguments_are_rejected_before_hindsight_is_called(): void
    {
        $retain = $this->serve(Retain::bank('b'));

        MemoryServer::tool($retain, ['context' => 'chat'])->assertHasErrors(['The content field is required.']);

        $this->assertSame([], $this->http->requests);
    }

    public function test_a_bank_nobody_wrote_to_means_no_memories(): void
    {
        Exceptions::fake();
        $this->http->json(['detail' => "Bank 'b' not found"], 404);
        $this->http->json(['detail' => "Bank 'b' not found"], 404);
        [$recall, $reflect] = $this->serve(Recall::bank('b'), Reflect::bank('b'));

        MemoryServer::tool($recall, ['query' => 'Alice'])->assertOk()->assertSee('No relevant memories found.');
        MemoryServer::tool($reflect, ['query' => 'Alice'])->assertOk()->assertSee('There is nothing in memory yet.');

        Exceptions::assertNothingReported();
    }

    public function test_errors_reach_the_client_as_tool_errors_without_details(): void
    {
        Exceptions::fake();
        config(['app.debug' => false]);
        $this->http->json(['detail' => 'Not Found'], 404);
        $recall = $this->serve(Recall::bank('b'));

        MemoryServer::tool($recall, ['query' => 'Alice'])
            ->assertHasErrors(['Memory is unavailable right now.'])
            ->assertDontSee('404');

        Exceptions::assertReported(NotFoundException::class);
    }

    public function test_in_debug_mode_the_error_says_what_went_wrong(): void
    {
        Exceptions::fake();
        config(['app.debug' => true]);
        $this->http->json(['detail' => 'Not Found'], 404);
        $recall = $this->serve(Recall::bank('b'));

        MemoryServer::tool($recall, ['query' => 'Alice'])->assertHasErrors(['Memory is unavailable: Hindsight API error 404: Not Found']);
    }

    public function test_the_tools_use_the_fake(): void
    {
        Hindsight::fake(['user-1' => ['Alice is on the Pro plan']]);
        [$recall, $retain] = $this->serve(Recall::bank('user-1'), Retain::bank('user-1'));

        MemoryServer::tool($recall, ['query' => 'Which plan?'])->assertSee('Alice is on the Pro plan');
        MemoryServer::tool($retain, ['content' => 'Alice prefers email'])->assertSee('Stored in memory.');

        Hindsight::assertRecalled('user-1');
        Hindsight::assertRetained('user-1', fn (string $content): bool => $content === 'Alice prefers email');
    }

    public function test_tools_describe_themselves(): void
    {
        $recall = Recall::bank('b')->toArray();
        $this->assertSame('recall_memory', $recall['name']);
        $this->assertSame('Recall memory', $recall['title']);
        $this->assertSame(['query'], $recall['inputSchema']['required'] ?? null);
        $this->assertEquals(['readOnlyHint' => true, 'openWorldHint' => false], $this->hints($recall));

        $retain = Retain::bank('b')->toArray();
        $this->assertSame('retain_memory', $retain['name']);
        $this->assertSame(['content'], $retain['inputSchema']['required'] ?? null);
        $this->assertSame('Store in memory', $retain['title']);
        $this->assertEquals(['destructiveHint' => false, 'openWorldHint' => false], $this->hints($retain));

        $reflect = Reflect::bank('b')->toArray();
        $this->assertSame('reflect_memory', $reflect['name']);
        $this->assertSame('Reflect on memory', $reflect['title']);
        $this->assertEquals(['readOnlyHint' => true, 'openWorldHint' => false], $this->hints($reflect));

        $this->assertSame('customer_memory', Recall::bank('b')->named('customer_memory')->name());
    }

    /**
     * @return ($tools is array{Tool} ? Tool : list<Tool>)
     */
    private function serve(Tool ...$tools): Tool|array
    {
        MemoryServer::$memoryTools = array_values($tools);

        return count($tools) === 1 ? $tools[0] : array_values($tools);
    }

    /**
     * The annotation hints without the title, which newer laravel/mcp releases also copy into the annotations.
     *
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function hints(array $tool): array
    {
        return array_diff_key($tool['annotations'] ?? [], ['title' => true]);
    }

    private function user(int $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }
}
