<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Closure;
use Fedoseev\Hindsight\Exceptions\ConfigurationException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\ChatAgent;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\RefundOrder;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\SupportAgent;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * When a run starts, when it ends, and what belongs to which run.
 */
final class MemoryRunBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHindsight();
    }

    public function test_a_step_that_asks_for_a_tool_is_not_retained_even_when_it_is_the_last_one(): void
    {
        // Real providers return preamble text with the tool call. On the last allowed step a tool that needs
        // approval pauses the run here, and the resumed run would retain the exchange a second time.
        $memory = Memory::bank('b')->withoutRecall();

        $this->runStep($memory, $this->step(0, isFinalStep: true), $this->response('Sure, I will issue the refund now.', [new ToolCall('call_1', 'refund_order', [])], FinishReason::ToolCalls));

        $this->assertSame([], $this->http->requests);
    }

    public function test_a_step_cut_off_by_the_token_limit_ends_the_run_and_is_retained(): void
    {
        // The loop runs no tools unless the model finished with tool calls, so this answer is final.
        $this->queueRetain();
        $memory = Memory::bank('b')->withoutRecall();

        $this->runStep($memory, $this->step(0), $this->response('Your plan renews on', [new ToolCall('call_1', 'lookup', [])], FinishReason::Length));

        $this->assertSame("USER: When does my plan renew?\nASSISTANT: Your plan renews on", $this->sentJson(0)['items'][0]['content']);
    }

    public function test_a_tool_waiting_for_approval_pauses_the_run_without_retaining_it(): void
    {
        // The fake model sends the tool call without text. The real shape, text plus a tool call, is covered
        // by test_a_step_that_asks_for_a_tool_is_not_retained_even_when_it_is_the_last_one.
        ChatAgent::fake([new ToolCall('call_1', 'refund_order', ['order' => 5])]);
        $this->queueRecall([]);

        $response = (new ChatAgent([Memory::bank('b')], [new RefundOrder]))->prompt('Refund order 5');

        $this->assertCount(1, $response->pendingApprovals);
        $this->assertSame(['POST /v1/default/banks/b/memories/recall'], $this->sentPaths());
    }

    public function test_memories_of_one_run_never_reach_another_run(): void
    {
        $memory = Memory::bank('b')->withoutRetain();
        $this->queueRecall([['id' => 'f1', 'text' => 'User A is allergic to nuts']]);

        $first = $this->runStep($memory, $this->step(0, invocationId: 'run-a'), $this->response('Noted.'));
        // Run B's first step never reached this middleware, e.g. an outer middleware answered it from a cache.
        $second = $this->runStep($memory, $this->step(1, invocationId: 'run-b'), $this->response('Hello.'));

        $this->assertStringContainsString('allergic to nuts', (string) $first);
        $this->assertSame('You are a support agent.', $second);
    }

    public function test_a_step_generated_twice_recalls_and_retains_once(): void
    {
        // e.g. an outer middleware that validates the answer and asks the model again.
        $this->queueRecall([['id' => 'f1', 'text' => 'Alice is on the Pro plan']]);
        $this->queueRetain();
        $memory = Memory::bank('b');
        $step = $this->step(0, invocationId: 'run-a');

        $first = $this->runStep($memory, $step, $this->response('First try.'));
        $second = $this->runStep($memory, $step, $this->response('Second try.'));

        $this->assertSame(['POST /v1/default/banks/b/memories/recall', 'POST /v1/default/banks/b/memories'], $this->sentPaths());
        $this->assertSame($first, $second);
    }

    public function test_recall_searches_for_the_new_message_not_the_conversation_history(): void
    {
        ChatAgent::fake(['Your plan renews on 1 December.']);
        $this->queueRecall([]);
        $this->queueRetain();

        (new ChatAgent([Memory::bank('b')]))->prompt('When does my plan renew?');

        $this->assertSame('When does my plan renew?', $this->sentJson(0)['query']);
        $this->assertSame("USER: When does my plan renew?\nASSISTANT: Your plan renews on 1 December.", $this->sentJson(1)['items'][0]['content']);
    }

    public function test_a_misconfigured_url_does_not_break_the_agent(): void
    {
        Exceptions::fake();
        $this->app->bind(Hindsight::class, fn (): Hindsight => new Hindsight('localhost:8888'));
        SupportAgent::fake(['Still here.']);

        $response = (new SupportAgent([Memory::bank('b')]))->prompt('Hi');

        $this->assertSame('Still here.', $response->text);
        Exceptions::assertReported(ConfigurationException::class);
    }

    /**
     * Run the middleware around one step, as the generation loop would, and return the instructions it sent.
     */
    private function runStep(Memory $memory, PendingStep $step, StepResponse $response): ?string
    {
        $sent = null;

        $result = $memory->handle($step, function (PendingStep $step) use ($response, &$sent): StepResult {
            $sent = $step->instructions;

            return new StepResult($response, $step);
        });

        $this->assertInstanceOf(StepResult::class, $result);
        $result->response();

        return $sent;
    }

    private function step(int $number, bool $isFinalStep = false, ?string $invocationId = null): PendingStep
    {
        return new PendingStep(
            number: $number,
            isFinalStep: $isFinalStep,
            provider: 'openai',
            model: 'gpt-test',
            instructions: 'You are a support agent.',
            messages: [new UserMessage('When does my plan renew?')],
            tools: [],
            schema: null,
            options: null,
            invocationId: $invocationId,
        );
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     */
    private function response(string $text, array $toolCalls = [], FinishReason $finishReason = FinishReason::Stop): StepResponse
    {
        return new StepResponse($text, $toolCalls, $finishReason, new TextUsage, new Meta('openai', 'gpt-test'));
    }
}
