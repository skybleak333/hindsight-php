<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Exceptions\QueueDispatchException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Ai\Tools\ReadPage;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Recall;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Reflect;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Retain;
use Fedoseev\Hindsight\Laravel\Ai\Tools\SearchPages;
use Fedoseev\Hindsight\Laravel\Facades\Hindsight as HindsightFacade;
use Fedoseev\Hindsight\Laravel\Jobs\RetainMemory;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\SupportAgent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class ToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHindsight();
    }

    public function test_recall_returns_memories_for_the_model(): void
    {
        $this->queueRecall([
            ['id' => 'f1', 'text' => 'Alice is on the Pro plan', 'occurred_start' => '2026-09-01T00:00:00Z'],
            ['id' => 'f2', 'text' => 'Alice prefers email'],
        ]);

        $output = Recall::bank('b')->tags(['user:42'])->budget(Budget::Mid, maxTokens: 2000)->handle(new Request(['query' => 'Alice plan']));

        $this->assertSame("Relevant memories, most relevant first:\n- Alice is on the Pro plan (2026-09-01)\n- Alice prefers email", $output);
        $this->assertSame(['query' => 'Alice plan', 'budget' => 'mid', 'max_tokens' => 2000, 'tags' => ['user:42'], 'tags_match' => 'any_strict'], $this->sentJson(0));
    }

    public function test_recall_says_so_when_nothing_is_found(): void
    {
        $this->queueRecall([]);

        $this->assertSame('No relevant memories found.', (new Recall('b'))->handle(new Request(['query' => 'Bob'])));
    }

    public function test_retain_stores_what_the_model_asks_for(): void
    {
        $this->queueRetain();

        $output = Retain::bank('b')->tags(['user:42'])->handle(new Request(['content' => 'Alice moved to Berlin', 'context' => 'Alice said so in chat']));

        $this->assertSame('Stored in memory.', $output);
        $this->assertSame([
            'items' => [['content' => 'Alice moved to Berlin', 'context' => 'Alice said so in chat', 'tags' => ['user:42']]],
            'async' => true,
        ], $this->sentJson(0));
    }

    public function test_queued_retain_preserves_the_tool_configuration_and_skips_http_during_dispatch(): void
    {
        Queue::fake();
        config(['hindsight.queue.connection' => 'sync', 'hindsight.queue.queue' => 'default-memory']);
        $retain = Retain::bank('b')->tags(['user:42'])->timeout(4)->queue('database', 'agent-memory');

        $output = $retain->handle(new Request(['content' => 'Alice moved to Berlin', 'context' => 'chat']));

        $this->assertSame('Memory accepted for storage.', $output);
        $this->assertSame([], $this->http->requests);
        Queue::assertPushedOn('agent-memory', RetainMemory::class, function (RetainMemory $job): bool {
            $this->assertSame('database', $job->connection);
            $this->assertSame('b', $job->bankId);
            $this->assertSame('Alice moved to Berlin', $job->content);
            $this->assertSame('chat', $job->context);
            $this->assertSame(['user:42'], $job->memoryTags);
            $this->assertSame(4.0, $job->requestTimeout);

            return true;
        });
    }

    public function test_an_explicit_sync_queue_can_store_with_the_fake(): void
    {
        $fake = HindsightFacade::fake();

        $output = Retain::bank('b')->queue('sync')->handle(new Request(['content' => 'Alice prefers email']));

        $this->assertSame('Memory accepted for storage.', $output);
        $fake->assertRetained('b', fn (string $content): bool => $content === 'Alice prefers email');
    }

    public function test_the_tool_reports_a_failed_dispatch_without_telling_the_model_it_stored_the_memory(): void
    {
        Exceptions::fake();
        $cause = new RuntimeException('Queue connection refused');
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow($cause);

        $output = Retain::bank('b')->queue()->handle(new Request(['content' => 'Alice prefers email']));

        $this->assertSame('Memory is unavailable: The memory could not be queued.', $output);
        Exceptions::assertReported(fn (QueueDispatchException $exception): bool => $exception->getPrevious() === $cause);
    }

    #[DataProvider('incompatibleQueueOptions')]
    public function test_queue_rejects_incompatible_tool_options(bool $queueFirst, bool $customClient): void
    {
        $retain = Retain::bank('b');
        $configure = $customClient
            ? fn () => $retain->using($this->app->make(Hindsight::class))
            : $retain->synchronously(...);

        $this->expectException(LogicException::class);

        if ($queueFirst) {
            $retain->queue();
            $configure();
        } else {
            $configure();
            $retain->queue();
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function incompatibleQueueOptions(): iterable
    {
        yield 'queue then custom client' => [true, true];
        yield 'custom client then queue' => [false, true];
        yield 'queue then synchronous retain' => [true, false];
        yield 'synchronous retain then queue' => [false, false];
    }

    public function test_reflect_returns_the_answer(): void
    {
        $this->http->json(['text' => 'Alice is a Pro customer who prefers email.']);

        $output = Reflect::bank('b')->budget(Budget::Mid)->handle(new Request(['query' => 'Summarise Alice']));

        $this->assertSame('Alice is a Pro customer who prefers email.', $output);
        $this->assertSame(['query' => 'Summarise Alice', 'budget' => 'mid'], $this->sentJson(0));
    }

    public function test_errors_go_back_to_the_model_instead_of_ending_the_run(): void
    {
        Exceptions::fake();
        $this->http->json(['detail' => 'Not Found'], 404);

        $output = (new Recall('b'))->handle(new Request(['query' => 'Alice']));

        $this->assertSame('Memory is unavailable: Hindsight API error 404: Not Found', $output);
        Exceptions::assertReported(NotFoundException::class);
    }

    public function test_a_bank_nobody_wrote_to_means_no_memories(): void
    {
        Exceptions::fake();
        $this->http->json(['detail' => "Bank 'b' not found"], 404);
        $this->http->json(['detail' => "Bank 'b' not found"], 404);

        $this->assertSame('No relevant memories found.', (new Recall('b'))->handle(new Request(['query' => 'Alice'])));
        $this->assertSame('There is nothing in memory yet.', (new Reflect('b'))->handle(new Request(['query' => 'Alice'])));
        Exceptions::assertNothingReported();
    }

    public function test_search_pages_lists_pages_to_read(): void
    {
        $this->queueFor('GET', '/v1/default/banks/b/pages/search', ['results' => [
            ['id' => 'p1', 'name' => 'Alice', 'snippet' => 'Alice is on the Pro plan.', 'score' => 0.03],
        ], 'total' => 1]);

        $output = SearchPages::bank('b')->limit(3)->handle(new Request(['query' => 'pro plan']));

        $this->assertSame("Pages, best match first:\n- Alice (id: p1): Alice is on the Pro plan.", $output);
        $this->assertSame('q=pro%20plan&limit=3', $this->http->requests[0]->getUri()->getQuery());
    }

    public function test_read_page_returns_the_markdown_within_the_tags_of_the_tool(): void
    {
        $page = ['id' => 'p1', 'name' => 'Alice', 'type' => 'page', 'markdown' => 'Alice is on the Pro plan.'];
        $this->queueFor('GET', '/v1/default/banks/b/pages/p1', [...$page, 'tags' => ['user:42']]);
        $this->queueFor('GET', '/v1/default/banks/b/pages/p1', [...$page, 'tags' => ['user:7']]);
        $read = ReadPage::bank('b')->tags(['user:42']);

        $this->assertSame('Alice is on the Pro plan.', $read->handle(new Request(['page_id' => 'p1'])));
        $this->assertSame('There is no such page.', $read->handle(new Request(['page_id' => 'p1'])));
    }

    public function test_read_page_says_so_when_there_is_no_such_page(): void
    {
        Exceptions::fake();
        $this->http->json(['detail' => "Page 'nope' not found"], 404);

        $this->assertSame('There is no such page.', ReadPage::bank('b')->handle(new Request(['page_id' => 'nope'])));
        Exceptions::assertNothingReported();
    }

    public function test_tools_describe_themselves(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $recall = new Recall('b');
        $this->assertSame('recall_memory', $recall->name());
        $this->assertNotSame('', (string) $recall->description());
        $this->assertSame(['query'], (new ObjectSchema($recall->schema($schema)))->toSchema()['required']);

        $retain = new Retain('b');
        $this->assertSame('retain_memory', $retain->name());
        $this->assertSame(['content', 'context'], array_keys($retain->schema($schema)));
        $this->assertSame(['content'], (new ObjectSchema($retain->schema($schema)))->toSchema()['required']);

        $this->assertSame('reflect_memory', (new Reflect('b'))->name());

        $this->assertSame('customer_memory', Recall::bank('b')->named('customer_memory')->name());
        $this->assertSame('search_pages', SearchPages::bank('b')->name());
        $this->assertSame(['page_id'], (new ObjectSchema(ReadPage::bank('b')->schema($schema)))->toSchema()['required']);
    }

    public function test_an_agent_can_call_the_tools(): void
    {
        SupportAgent::fake([
            new ToolCall('call_1', 'recall_memory', ['query' => 'Alice plan']),
            'Alice is on the Pro plan.',
        ]);
        $this->queueRecall([['id' => 'f1', 'text' => 'Alice is on the Pro plan']]);

        $response = (new SupportAgent(tools: [Recall::bank('b')]))->prompt('Which plan is Alice on?');

        $this->assertSame('Alice is on the Pro plan.', $response->text);
        $this->assertSame(['POST /v1/default/banks/b/memories/recall'], $this->sentPaths());
        $this->assertSame('Alice plan', $this->sentJson(0)['query']);
    }
}
