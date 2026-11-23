<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Symfony;

use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Responses\RecallResponse;
use Fedoseev\Hindsight\Symfony\Ai\HindsightMemory;
use Fedoseev\Hindsight\Testing\FakeServer;
use Fedoseev\Hindsight\Tests\Support\ArrayLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\MemoryInputProcessor;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final class HindsightMemoryTest extends TestCase
{
    private FakeServer $server;

    private Hindsight $client;

    protected function setUp(): void
    {
        $this->server = new FakeServer;
        $this->client = new Hindsight('http://hindsight.test', httpClient: $this->server);
    }

    public function test_the_agent_answers_with_recalled_memories_and_the_exchange_is_retained(): void
    {
        $this->server->seed('user-42', ['Is on the Pro plan', 'Prefers short answers']);
        $memory = new HindsightMemory($this->client, 'user-42');

        $sent = [];
        $platform = MockPlatformFactory::createPlatform(function ($model, $payload) use (&$sent): string {
            $sent[] = json_encode($payload);

            return 'It renews on the 1st.';
        });
        $agent = new Agent($platform, 'gpt-test', [new MemoryInputProcessor([$memory])], [$memory]);

        $answer = $agent->call(new MessageBag(
            Message::forSystem('You are a friendly support agent.'),
            Message::ofUser('When does my plan renew?'),
        ))->getContent();

        $this->assertSame('It renews on the 1st.', $answer);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Is on the Pro plan', $sent[0]);
        $this->assertStringContainsString('Prefers short answers', $sent[0]);

        $retained = $this->server->retained();
        $this->assertCount(1, $retained);
        $this->assertSame('user-42', $retained[0]['bank']);
        $this->assertSame("USER: When does my plan renew?\nASSISTANT: It renews on the 1st.", $retained[0]['item']['content']);
        $this->assertSame(HindsightMemory::DEFAULT_CONTEXT, $retained[0]['item']['context']);
    }

    public function test_a_streamed_answer_is_retained_once_it_is_complete(): void
    {
        $memory = new HindsightMemory($this->client, 'user-42');
        $platform = MockPlatformFactory::createPlatform(static fn () => new StreamResult((static function (): \Generator {
            yield new TextDelta('It renews ');
            yield new TextDelta('on the 1st.');
        })()));
        $agent = new Agent($platform, 'gpt-test', [new MemoryInputProcessor([$memory])], [$memory]);

        foreach ($agent->call(new MessageBag(Message::ofUser('When does my plan renew?')), ['stream' => true])->getContent() as $delta) {
            // read the stream to its end, as a controller sending it to the browser would
        }

        $this->assertCount(1, $this->server->retained());
        $this->assertSame("USER: When does my plan renew?\nASSISTANT: It renews on the 1st.", $this->server->retained()[0]['item']['content']);
    }

    public function test_the_formatter_controls_the_memory_sent_to_the_model(): void
    {
        $this->server->seed('policies', [
            ['text' => 'Old refund window', 'document_id' => 'refunds-v1'],
            ['text' => 'Approved refund window', 'document_id' => 'refunds-v2'],
        ]);
        $memory = new HindsightMemory($this->client, 'policies', retain: false, recallFormatter: function (RecallResponse $recall): string {
            $this->assertSame(['refunds-v1', 'refunds-v2'], [$recall->results[0]->documentId, $recall->results[1]->documentId]);

            return '- '.$recall->results[1]->text.' (https://policies.example/refunds/v2)';
        });
        $platform = MockPlatformFactory::createPlatform(function ($model, $payload): string {
            $prompt = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString('Approved refund window (https://policies.example/refunds/v2)', $prompt);
            $this->assertStringNotContainsString('Old refund window', $prompt);

            return 'See the approved policy.';
        });
        $agent = new Agent($platform, 'gpt-test', [new MemoryInputProcessor([$memory])]);

        $this->assertSame('See the approved policy.', $agent->call(new MessageBag(Message::ofUser('What is the refund window?')))->getContent());
    }

    #[DataProvider('omittedContexts')]
    public function test_a_null_or_blank_formatter_result_omits_memory(?string $context): void
    {
        $this->server->seed('policies', ['Old refund window']);
        $memory = new HindsightMemory($this->client, 'policies', recallFormatter: static fn (RecallResponse $recall): ?string => $context);

        $this->assertSame([], $memory->load(new Input('gpt-test', new MessageBag(Message::ofUser('What is the refund window?')))));
    }

    /** @return iterable<string, array{?string}> */
    public static function omittedContexts(): iterable
    {
        yield 'null' => [null];
        yield 'whitespace' => [" \n"];
    }

    public function test_a_formatter_error_stops_the_agent_before_the_model_call(): void
    {
        $this->server->seed('policies', ['Old refund window']);
        $memory = new HindsightMemory($this->client, 'policies', recallFormatter: static function (RecallResponse $recall): never {
            throw new ConnectionException('Could not verify the policy source.');
        });
        $platform = MockPlatformFactory::createPlatform(fn () => $this->fail('The model received a prompt after the formatter failed.'));
        $agent = new Agent($platform, 'gpt-test', [new MemoryInputProcessor([$memory])]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not verify the policy source.');

        $agent->call(new MessageBag(Message::ofUser('What is the refund window?')))->getContent();
    }

    public function test_the_answer_after_the_tool_calls_is_retained_once(): void
    {
        $memory = new HindsightMemory($this->client, 'user-42');
        $calls = 0;
        $platform = MockPlatformFactory::createPlatform(static function () use (&$calls): ToolCallResult|TextResult {
            return ++$calls === 1 ? new ToolCallResult([new ToolCall('call_1', 'clock', [])]) : new TextResult('It is noon.');
        });
        $agent = new Agent($platform, 'gpt-test', [new MemoryInputProcessor([$memory])], [$memory], toolbox: new Toolbox([new Clock]));

        $this->assertSame('It is noon.', $agent->call(new MessageBag(Message::ofUser('What time is it?')))->getContent());
        $this->assertSame(2, $calls);
        $this->assertCount(1, $this->server->retained());
        $this->assertSame("USER: What time is it?\nASSISTANT: It is noon.", $this->server->retained()[0]['item']['content']);
    }

    public function test_an_answer_with_reasoning_is_retained_without_it(): void
    {
        $memory = new HindsightMemory($this->client, 'user-42');

        $memory->processOutput(new Output('gpt-test', new MultiPartResult([new ThinkingResult('The plan renews monthly.', 'sig'), new TextResult('It renews on the 1st.')]), new MessageBag(Message::ofUser('When does my plan renew?'))));

        $this->assertSame("USER: When does my plan renew?\nASSISTANT: It renews on the 1st.", $this->server->retained()[0]['item']['content']);
    }

    public function test_it_recalls_for_the_latest_user_message(): void
    {
        $this->server->seed('user-42', ['Is on the Pro plan']);
        $memory = new HindsightMemory($this->client, 'user-42');

        $memories = $memory->load(new Input('gpt-test', new MessageBag(
            Message::ofUser('Hi'),
            Message::ofAssistant('Hello!'),
            Message::ofUser('When does my plan renew?'),
        )));

        $this->assertCount(1, $memories);
        $this->assertSame("From long-term memory:\n- Is on the Pro plan", $memories[0]->getContent());
        $this->assertSame('When does my plan renew?', $this->server->requests()[0]['body']['query']);
    }

    public function test_the_bank_can_come_from_a_closure(): void
    {
        $this->server->seed('user-7', ['Lives in Valencia']);
        $user = 'user-7';
        $memory = new HindsightMemory($this->client, function () use (&$user): string {
            return $user;
        });

        $this->assertCount(1, $memory->load(new Input('gpt-test', new MessageBag(Message::ofUser('Where do I live?')))));

        $user = 'user-8';
        $memory->processOutput($this->exchange('Hi', 'Hello!'));

        $this->assertSame('user-8', $this->server->retained()[0]['bank']);
    }

    public function test_a_new_user_without_a_bank_has_no_memories(): void
    {
        $memory = new HindsightMemory($this->client, 'user-new', throw: true);

        $this->assertSame([], $memory->load(new Input('gpt-test', new MessageBag(Message::ofUser('Hi')))));
    }

    public function test_tags_are_given_to_recall_and_retain(): void
    {
        $this->server->seed('team', [
            ['text' => 'Deploys on Fridays', 'tags' => ['project:api']],
            ['text' => 'Uses Vue', 'tags' => ['project:web']],
        ]);
        $memory = new HindsightMemory($this->client, 'team', tags: ['project:api']);

        $memories = $memory->load(new Input('gpt-test', new MessageBag(Message::ofUser('When do we deploy?'))));
        $memory->processOutput($this->exchange('When do we deploy?', 'On Fridays.'));

        $this->assertSame("From long-term memory:\n- Deploys on Fridays", $memories[0]->getContent());
        $this->assertSame(['project:api'], $this->server->retained()[0]['item']['tags']);
    }

    public function test_a_tool_call_or_an_empty_answer_is_not_retained(): void
    {
        $memory = new HindsightMemory($this->client, 'user-42');

        $memory->processOutput(new Output('gpt-test', new ToolCallResult([new ToolCall('call_1', 'clock')]), new MessageBag(Message::ofUser('What time is it?'))));
        $memory->processOutput($this->exchange('Hi', '  '));

        $this->assertSame([], $this->server->retained());
    }

    public function test_recall_and_retain_can_be_turned_off(): void
    {
        $this->server->seed('user-42', ['Is on the Pro plan']);
        $memory = new HindsightMemory($this->client, 'user-42', recall: false, retain: false);

        $this->assertSame([], $memory->load(new Input('gpt-test', new MessageBag(Message::ofUser('Hi')))));
        $memory->processOutput($this->exchange('Hi', 'Hello!'));

        $this->assertSame([], $this->server->requests());
    }

    public function test_an_unavailable_server_is_logged_and_the_agent_goes_on(): void
    {
        $this->server->seed('user-42', ['Is on the Pro plan']);
        $this->server->unavailable(null, 503);
        $logger = new ArrayLogger;
        $memory = new HindsightMemory($this->client, 'user-42', logger: $logger);

        $this->assertSame([], $memory->load(new Input('gpt-test', new MessageBag(Message::ofUser('Hi')))));
        $memory->processOutput($this->exchange('Hi', 'Hello!'));

        $this->assertCount(2, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('recall', $logger->records[0]['context']['operation']);
        $this->assertSame('retain', $logger->records[1]['context']['operation']);
    }

    public function test_errors_can_be_thrown(): void
    {
        $this->server->unavailable(null, 503);
        $memory = new HindsightMemory($this->client, 'user-42', throw: true);

        $this->expectException(HindsightException::class);

        $memory->processOutput($this->exchange('Hi', 'Hello!'));
    }

    private function exchange(string $question, string $answer): Output
    {
        return new Output('gpt-test', new TextResult($answer), new MessageBag(Message::ofUser($question)));
    }
}

#[AsTool('clock', 'Returns the current time')]
final class Clock
{
    public function __invoke(): string
    {
        return '12:00';
    }
}
