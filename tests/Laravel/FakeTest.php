<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Closure;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Ai\Memory;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Recall;
use Fedoseev\Hindsight\Laravel\Ai\Tools\Retain;
use Fedoseev\Hindsight\Laravel\Facades\Hindsight as HindsightFacade;
use Fedoseev\Hindsight\Testing\HindsightFake;
use Fedoseev\Hindsight\Tests\Laravel\Fixtures\SupportAgent;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Tools\Request;

/**
 * Hindsight::fake() as an application's tests would use it.
 */
final class FakeTest extends TestCase
{
    public function test_it_replaces_the_client_in_the_container(): void
    {
        $fake = HindsightFacade::fake();

        $this->assertInstanceOf(HindsightFake::class, $fake);
        $this->assertSame($fake, app(Hindsight::class));
        $this->assertSame($fake, app('hindsight'));
    }

    public function test_an_agent_with_memory(): void
    {
        HindsightFacade::fake(['user-1' => [['text' => 'Alice is on the Pro plan', 'tags' => ['user:1']]]]);
        SupportAgent::fake(['Your Pro plan renews on 1 December.']);

        $instructions = [];
        $capture = function (PendingStep $step, Closure $next) use (&$instructions): mixed {
            $instructions[] = $step->instructions;

            return $next($step);
        };

        (new SupportAgent([Memory::bank('user-1')->tags(['user:1']), $capture]))->prompt('When does my plan renew?');

        $this->assertStringContainsString('- Alice is on the Pro plan', (string) $instructions[0]);
        HindsightFacade::assertRecalled('user-1', fn (string $query, array $request): bool => $query === 'When does my plan renew?' && $request['tags'] === ['user:1']);
        HindsightFacade::assertRetained('user-1', fn (string $content, array $item): bool => $content === "USER: When does my plan renew?\nASSISTANT: Your Pro plan renews on 1 December."
            && $item['tags'] === ['user:1']);
    }

    public function test_the_first_conversation_of_a_new_user_is_not_an_error(): void
    {
        Exceptions::fake();
        HindsightFacade::fake();
        SupportAgent::fake(['Hello!']);

        (new SupportAgent([Memory::bank('new-user')]))->prompt('Hi');

        // The fake answers 404 for a bank nobody wrote to, as the server does, and the middleware treats it as empty.
        Exceptions::assertNothingReported();
        HindsightFacade::assertRetained('new-user', 1);
    }

    public function test_tools_use_the_fake(): void
    {
        $fake = HindsightFacade::fake(['user-1' => ['Alice prefers email']]);

        $this->assertSame("Relevant memories, most relevant first:\n- Alice prefers email", Recall::bank('user-1')->handle(new Request(['query' => 'contact'])));
        $this->assertSame('Stored in memory.', Retain::bank('user-1')->handle(new Request(['content' => 'Alice moved to Berlin'])));

        $fake->assertRetained('user-1', fn (string $content): bool => $content === 'Alice moved to Berlin');
    }
}
