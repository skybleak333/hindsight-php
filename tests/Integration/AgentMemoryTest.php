<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Closure;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Recall;
use Fedoseev\Hindsight\Laravel\HindsightServiceProvider;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\SupportAgent;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Orchestra\Testbench\TestCase;

/**
 * A Laravel AI SDK agent with a faked model and a real Hindsight server.
 */
final class AgentMemoryTest extends TestCase
{
    use UsesRealHindsight;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, HindsightServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Hindsight::class, $this->connectToHindsight());
    }

    protected function tearDown(): void
    {
        $this->deleteBanks();

        parent::tearDown();
    }

    public function test_an_agent_remembers_what_the_user_said_in_an_earlier_conversation(): void
    {
        $bank = $this->newBank();
        SupportAgent::fake(['Noted, teal it is.', 'Your favourite colour is teal.']);

        // First conversation: the exchange is retained after the answer.
        (new SupportAgent([Memory::bank($bank)->retainSynchronously()]))->prompt('My favourite colour is teal.');

        // Second conversation, new agent instance: the memory comes back in the instructions.
        $instructions = null;
        $capture = function (PendingStep $step, Closure $next) use (&$instructions): mixed {
            $instructions = $step->instructions;

            return $next($step);
        };

        (new SupportAgent([Memory::bank($bank)->withoutRetain(), $capture]))->prompt('What is my favourite colour?');

        $this->assertIsString($instructions);
        $this->assertStringContainsString('# Relevant Memories', $instructions);
        $this->assertStringContainsString('teal', $instructions);
    }

    public function test_the_recall_tool_reads_the_real_bank(): void
    {
        $bank = $this->newBank();
        $this->hindsight->retain($bank, 'The customer Erin is on the Enterprise plan.');

        SupportAgent::fake([
            new ToolCall('call_1', 'recall_memory', ['query' => 'Which plan is Erin on?']),
            'Erin is on the Enterprise plan.',
        ]);

        $toolOutput = null;
        $capture = function (PendingStep $step, Closure $next) use (&$toolOutput): mixed {
            foreach ($step->messages as $message) {
                if ($message instanceof \Laravel\Ai\Messages\ToolResultMessage) {
                    $toolOutput = (string) $message->toolResults->first()?->result;
                }
            }

            return $next($step);
        };

        (new SupportAgent([$capture], [Recall::bank($bank)]))->prompt('Which plan is Erin on?');

        $this->assertIsString($toolOutput);
        $this->assertStringContainsString('Enterprise plan', $toolOutput);
    }
}
