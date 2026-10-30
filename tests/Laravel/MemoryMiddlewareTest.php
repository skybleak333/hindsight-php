<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Closure;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\SupportAgent;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;

final class MemoryMiddlewareTest extends TestCase
{
    /** @var list<string|null> Instructions as each step sent them to the model. */
    private array $instructions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHindsight();
    }

    public function test_it_adds_relevant_memories_to_the_instructions_of_the_first_step(): void
    {
        SupportAgent::fake(['Your plan renews on 1 December.']);
        $this->queueRecall([
            ['id' => 'f1', 'text' => 'Alice is on the Pro plan', 'occurred_start' => '2026-09-01T00:00:00Z'],
            ['id' => 'f2', 'text' => 'Alice prefers short answers'],
        ]);
        $this->queueRetain();

        $response = $this->agent(Memory::bank('b'))->prompt('When does my plan renew?');

        $this->assertSame('Your plan renews on 1 December.', $response->text);
        $this->assertSame(['POST /v1/default/banks/b/memories/recall', 'POST /v1/default/banks/b/memories'], $this->sentPaths());
        $this->assertSame(['query' => 'When does my plan renew?', 'budget' => 'low', 'max_tokens' => 1024], $this->sentJson(0));
        $this->assertSame([
            "You are a support agent.\n\n".
            "# Relevant Memories\n".
            "The following information from long-term memory may be relevant:\n\n".
            "- Alice is on the Pro plan (2026-09-01)\n".
            '- Alice prefers short answers',
        ], $this->instructions);
    }

    public function test_it_leaves_the_instructions_alone_when_nothing_is_found(): void
    {
        SupportAgent::fake(['Hello!']);
        $this->queueRecall([]);
        $this->queueRetain();

        $this->agent(Memory::bank('b'))->prompt('Hi');

        $this->assertSame(['You are a support agent.'], $this->instructions);
    }

    public function test_it_retains_the_exchange_after_the_answer(): void
    {
        SupportAgent::fake(['Your plan renews on 1 December.']);
        $this->queueRecall([]);
        $this->queueRetain();

        $this->agent(Memory::bank('b'))->prompt('When does my plan renew?');

        $this->assertSame([
            'items' => [[
                'content' => "USER: When does my plan renew?\nASSISTANT: Your plan renews on 1 December.",
                'context' => 'Conversation between a user and an AI assistant',
            ]],
            'async' => true,
        ], $this->sentJson(1));
    }

    public function test_a_run_with_tool_calls_recalls_once_and_retains_the_final_answer(): void
    {
        SupportAgent::fake([
            new ToolCall('call_1', 'lookup_invoice', []),
            'Invoice 7 is paid.',
        ]);
        $this->queueRecall([['id' => 'f1', 'text' => 'Alice pays by card']]);
        $this->queueRetain();

        $this->agent(Memory::bank('b'), tools: [new Fixtures\LookupInvoice])->prompt('Is invoice 7 paid?');

        $this->assertSame(['POST /v1/default/banks/b/memories/recall', 'POST /v1/default/banks/b/memories'], $this->sentPaths());
        $this->assertCount(2, $this->instructions);
        $this->assertSame($this->instructions[0], $this->instructions[1], 'Later steps keep the memories of the first.');
        $this->assertSame("USER: Is invoice 7 paid?\nASSISTANT: Invoice 7 is paid.", $this->sentJson(1)['items'][0]['content']);
    }

    public function test_tags_scope_recall_and_label_retained_memories(): void
    {
        SupportAgent::fake(['Done.']);
        $this->queueRecall([]);
        $this->queueRetain();

        $this->agent(Memory::bank('b')->tags(['user:42'], 'any_strict')->recallBudget(Budget::Mid, maxTokens: 2048))->prompt('Hi');

        $this->assertSame(['query' => 'Hi', 'budget' => 'mid', 'max_tokens' => 2048, 'tags' => ['user:42'], 'tags_match' => 'any_strict'], $this->sentJson(0));
        $this->assertSame(['user:42'], $this->sentJson(1)['items'][0]['tags']);
    }

    public function test_a_conversation_document_is_appended_to(): void
    {
        SupportAgent::fake(['Done.']);
        $this->queueRecall([]);
        $this->queueRetain();

        $this->agent(Memory::bank('b')->document('conversation-9')->context('Support chat'))->prompt('Hi');

        $item = $this->sentJson(1)['items'][0];
        $this->assertSame('conversation-9', $item['document_id']);
        $this->assertSame('append', $item['update_mode']);
        $this->assertSame('Support chat', $item['context']);
    }

    public function test_recall_and_retain_can_be_switched_off(): void
    {
        SupportAgent::fake(['One.', 'Two.']);
        $this->queueRetain();
        $this->queueRecall([]);

        $this->agent(Memory::bank('b')->withoutRecall())->prompt('First');
        $this->agent(Memory::bank('b')->withoutRetain())->prompt('Second');

        $this->assertSame(['POST /v1/default/banks/b/memories', 'POST /v1/default/banks/b/memories/recall'], $this->sentPaths());
    }

    public function test_sync_retain_waits_for_the_server(): void
    {
        SupportAgent::fake(['Done.']);
        $this->queueRetain(async: false);

        $this->agent(Memory::bank('b')->withoutRecall()->retainSynchronously())->prompt('Hi');

        $this->assertFalse($this->sentJson(0)['async']);
    }

    public function test_the_agent_still_answers_when_hindsight_is_down(): void
    {
        Exceptions::fake();
        SupportAgent::fake(['I can still help.']);
        $down = new ConnectException('Connection refused', new Request('POST', 'http://hindsight.test'));
        $this->http->fail($down)->fail($down);

        $response = $this->agent(Memory::bank('b'))->prompt('Hi');

        $this->assertSame('I can still help.', $response->text);
        $this->assertSame(['You are a support agent.'], $this->instructions);
        Exceptions::assertReported(ConnectionException::class);
        Exceptions::assertReportedCount(2);
    }

    public function test_errors_can_be_thrown_instead(): void
    {
        SupportAgent::fake(['unused']);
        $this->http->fail(new ConnectException('Connection refused', new Request('POST', 'http://hindsight.test')));

        $this->expectException(ConnectionException::class);

        $this->agent(Memory::bank('b')->throwOnError())->prompt('Hi');
    }

    public function test_streamed_answers_are_retained_once_the_stream_is_read(): void
    {
        SupportAgent::fake(['Streaming works fine.']);
        $this->queueRecall([]);
        $this->queueRetain();

        $stream = $this->agent(Memory::bank('b'))->stream('Does streaming work?');

        foreach ($stream as $event) {
            // Read the whole stream, as a controller returning it would.
        }

        $this->assertSame("USER: Does streaming work?\nASSISTANT: Streaming works fine.", $this->sentJson(1)['items'][0]['content']);
    }

    public function test_memories_from_an_earlier_turn_are_not_retained_again(): void
    {
        SupportAgent::fake(['Pro.']);
        $this->queueRecall([['id' => 'f1', 'text' => 'Alice is on the Pro plan']]);
        $this->queueRetain();

        $this->agent(Memory::bank('b'))->prompt('Which plan am I on?');

        $this->assertStringNotContainsString('Relevant Memories', $this->sentJson(1)['items'][0]['content']);
    }

    /**
     * @param  list<mixed>  $tools
     */
    private function agent(Memory $memory, array $tools = []): SupportAgent
    {
        $capture = function (PendingStep $step, Closure $next): mixed {
            $this->instructions[] = $step->instructions;

            return $next($step);
        };

        return new SupportAgent([$memory, $capture], $tools);
    }
}
