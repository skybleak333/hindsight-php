<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Recall;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Reflect;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Retain;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\SupportAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

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
        $this->assertSame(['query' => 'Alice plan', 'budget' => 'mid', 'max_tokens' => 2000, 'tags' => ['user:42']], $this->sentJson(0));
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
        $this->http->json(['detail' => 'Bank not found: b'], 404);

        $output = (new Recall('b'))->handle(new Request(['query' => 'Alice']));

        $this->assertSame('Memory is unavailable: Hindsight API error 404: Bank not found: b', $output);
        Exceptions::assertReported(NotFoundException::class);
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
