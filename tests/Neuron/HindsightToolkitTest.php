<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Neuron;

use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Neuron\HindsightToolkit;
use Fedoseev\Hindsight\Neuron\Tools\Recall;
use Fedoseev\Hindsight\Neuron\Tools\Reflect;
use Fedoseev\Hindsight\Neuron\Tools\Retain;
use Fedoseev\Hindsight\TagsMatch;
use Fedoseev\Hindsight\Testing\FakeServer;
use Fedoseev\Hindsight\Tests\Support\ArrayLogger;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

final class HindsightToolkitTest extends TestCase
{
    private FakeServer $server;

    private Hindsight $client;

    protected function setUp(): void
    {
        $this->server = new FakeServer;
        $this->client = new Hindsight('http://hindsight.test', httpClient: $this->server);
    }

    public function test_an_agent_recalls_through_the_toolkit_and_answers_with_it(): void
    {
        $this->server->seed('user-42', ['Is on the Pro plan']);
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('recall_memory', 'call_1', ['query' => 'Which plan is the user on?'])]),
            new AssistantMessage('You are on the Pro plan.'),
        );
        $agent = Agent::make()->setThreadId('thread-1')->setAiProvider($provider)->addTool(new HindsightToolkit($this->client, 'user-42'));

        $answer = $agent->chat(new UserMessage('Which plan am I on?'))->getMessage();

        $this->assertSame('You are on the Pro plan.', $answer?->getContent());
        $provider->assertToolsConfigured(['recall_memory', 'retain_memory', 'reflect_memory']);
        $this->assertStringContainsString('call recall_memory', (string) $provider->getRecorded()[0]->systemPrompt?->getContent());
        $this->assertStringContainsString(
            'Relevant memories, most relevant first:\n- Is on the Pro plan',
            (string) json_encode(array_map(static fn ($message): mixed => $message->jsonSerialize(), $provider->getRecorded()[1]->messages)),
        );
        $this->assertSame('Which plan is the user on?', $this->server->requests()[0]['body']['query']);
        $this->assertSame('mid', $this->server->requests()[0]['body']['budget']);
    }

    public function test_the_tools_describe_their_inputs(): void
    {
        $recall = (new Recall($this->client, 'user-42'))->getInputSchema();
        $retain = (new Retain($this->client, 'user-42'))->getInputSchema();

        $this->assertSame(['query'], $recall['required']);
        $this->assertSame('What to look for, as a short natural-language question.', $recall['properties']['query']['description']);
        $this->assertSame(['content'], $retain['required']);
        $this->assertArrayHasKey('context', $retain['properties']);
    }

    public function test_reflect_can_be_left_out_of_the_toolkit(): void
    {
        $tools = (new HindsightToolkit($this->client, 'user-42'))->exclude([Reflect::class])->tools();

        $this->assertSame(['recall_memory', 'retain_memory'], array_map(static fn ($tool): string => $tool->getName(), $tools));
    }

    public function test_retain_stores_through_neuron_tool_execution(): void
    {
        $retain = new Retain($this->client, fn (): string => 'user-7', tags: ['app:support']);
        $retain->setInputs(['content' => 'The user prefers short answers.']);
        $retain->execute();

        $this->assertSame('Stored in memory.', $retain->getResult());
        $retained = $this->server->retained();
        $this->assertSame('user-7', $retained[0]['bank']);
        $this->assertSame('The user prefers short answers.', $retained[0]['item']['content']);
        $this->assertSame(['app:support'], $retained[0]['item']['tags']);
        $this->assertArrayNotHasKey('context', $retained[0]['item']);
    }

    public function test_recall_sends_its_options(): void
    {
        $this->server->seed('team', [['text' => 'Deploys on Fridays', 'tags' => ['project:api']]]);

        $result = (new Recall($this->client, 'team', tags: ['project:api'], tagsMatch: TagsMatch::All, budget: Budget::High, maxTokens: 512))('When do we deploy?');
        (new Recall($this->client, 'team'))('When do we deploy?');

        $this->assertStringContainsString('Deploys on Fridays', $result);
        $requests = $this->server->requests();
        $this->assertSame('all', $requests[0]['body']['tags_match']);
        $this->assertSame(512, $requests[0]['body']['max_tokens']);
        $this->assertArrayNotHasKey('tags_match', $requests[1]['body']);
    }

    public function test_a_new_user_and_an_unavailable_server(): void
    {
        $this->assertSame('No relevant memories found.', (new Recall($this->client, 'user-new'))('Anything?'));

        $this->server->respondTo('POST', '/v1/default/banks/user-new/reflect', ['detail' => "Bank 'user-new' not found"], 404);
        $this->assertSame('There is nothing in memory yet.', (new Reflect($this->client, 'user-new'))('What do you know about me?'));

        $this->server->unavailable(null, 503);
        $logger = new ArrayLogger;
        $this->assertStringStartsWith('Memory is unavailable: ', (new Retain($this->client, 'user-42', logger: $logger))('Hi'));
        $this->assertSame('warning', $logger->records[0]['level']);
    }
}
