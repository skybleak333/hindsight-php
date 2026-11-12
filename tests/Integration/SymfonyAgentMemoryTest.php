<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\Symfony\Ai\HindsightMemory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Memory\MemoryInputProcessor;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final class SymfonyAgentMemoryTest extends TestCase
{
    use UsesRealHindsight;

    protected function setUp(): void
    {
        $this->connectToHindsight();
    }

    protected function tearDown(): void
    {
        $this->deleteBanks();
    }

    public function test_an_agent_remembers_what_the_user_said_in_an_earlier_conversation(): void
    {
        $bank = $this->newBank();

        // First conversation: the exchange is retained after the answer.
        $memory = new HindsightMemory($this->hindsight, $bank, async: false, throw: true);
        $agent = new Agent(MockPlatformFactory::createPlatform('Noted, teal it is.'), 'gpt-test', [new MemoryInputProcessor([$memory])], [$memory]);
        $agent->call(new MessageBag(Message::ofUser('My favourite colour is teal.')))->getContent();

        // Second conversation, new agent: the memory comes back in the prompt.
        $sent = [];
        $platform = MockPlatformFactory::createPlatform(function ($model, $payload) use (&$sent): string {
            $sent[] = json_encode($payload);

            return 'Your favourite colour is teal.';
        });
        $memory = new HindsightMemory($this->hindsight, $bank, retain: false, throw: true);
        $agent = new Agent($platform, 'gpt-test', [new MemoryInputProcessor([$memory])], [$memory]);
        $agent->call(new MessageBag(
            Message::forSystem('You are a friendly support agent.'),
            Message::ofUser('What is my favourite colour?'),
        ))->getContent();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('From long-term memory', $sent[0]);
        $this->assertStringContainsString('teal', $sent[0]);
    }

    public function test_the_first_conversation_of_a_new_user_is_not_an_error(): void
    {
        $memory = new HindsightMemory($this->hindsight, $this->newBank(), async: false, throw: true);
        $agent = new Agent(MockPlatformFactory::createPlatform('Welcome!'), 'gpt-test', [new MemoryInputProcessor([$memory])], [$memory]);

        $this->assertSame('Welcome!', $agent->call(new MessageBag(Message::ofUser('Hi, I am new here.')))->getContent());
    }
}
