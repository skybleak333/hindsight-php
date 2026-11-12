<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Symfony;

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Symfony\Ai\Tools\Recall;
use Fedoseev\Hindsight\Symfony\Ai\Tools\Reflect;
use Fedoseev\Hindsight\Symfony\Ai\Tools\Retain;
use Fedoseev\Hindsight\Testing\FakeServer;
use Fedoseev\Hindsight\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\ChainFactory;
use Symfony\AI\Agent\Toolbox\ToolFactory\MemoryToolFactory;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\TagsMatch;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final class MemoryToolsTest extends TestCase
{
    private FakeServer $server;

    private Hindsight $client;

    protected function setUp(): void
    {
        $this->server = new FakeServer;
        $this->client = new Hindsight('http://hindsight.test', httpClient: $this->server);
    }

    public function test_the_toolbox_describes_the_tools(): void
    {
        $tools = (new Toolbox([
            new Recall($this->client, 'user-42'),
            new Retain($this->client, 'user-42'),
            new Reflect($this->client, 'user-42'),
        ]))->getTools();

        $this->assertSame(['recall_memory', 'retain_memory', 'reflect_memory'], array_map(static fn ($tool): string => $tool->getName(), $tools));

        $recall = $tools[0]->getParameters();
        $this->assertSame(['query'], $recall['required'] ?? null);
        $this->assertSame('What to look for, as a short natural-language question.', $recall['properties']['query']['description'] ?? null);

        $retain = $tools[1]->getParameters();
        $this->assertContains('content', $retain['required'] ?? []);
        $this->assertNotContains('context', $retain['required'] ?? []);
        $this->assertSame('Where the information comes from, e.g. "the user said so in chat".', $retain['properties']['context']['description'] ?? null);
    }

    public function test_an_agent_recalls_through_the_tool_and_answers_with_it(): void
    {
        $this->server->seed('user-42', ['Is on the Pro plan']);
        $sent = [];
        $platform = MockPlatformFactory::createPlatform(function ($model, $payload) use (&$sent): ToolCallResult|TextResult {
            $sent[] = json_encode($payload);

            return count($sent) === 1
                ? new ToolCallResult([new ToolCall('call_1', 'recall_memory', ['query' => 'Which plan is the user on?'])])
                : new TextResult('You are on the Pro plan.');
        });
        $agent = new Agent($platform, 'gpt-test', toolbox: new Toolbox([new Recall($this->client, 'user-42')]));

        $answer = $agent->call(new MessageBag(Message::ofUser('Which plan am I on?')))->getContent();

        $this->assertSame('You are on the Pro plan.', $answer);
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Relevant memories, most relevant first:\n- Is on the Pro plan', $sent[1]);
        $this->assertSame('Which plan is the user on?', $this->server->requests()[0]['body']['query']);
        $this->assertSame('mid', $this->server->requests()[0]['body']['budget']);
    }

    public function test_recall_sends_its_options(): void
    {
        $this->server->seed('team', [['text' => 'Deploys on Fridays', 'tags' => ['project:api']]]);

        (new Recall($this->client, 'team', tags: ['project:api'], tagsMatch: TagsMatch::All, budget: Budget::High, maxTokens: 512))('When do we deploy?');
        (new Recall($this->client, 'team'))('When do we deploy?');

        $requests = $this->server->requests();
        $this->assertSame(['project:api'], $requests[0]['body']['tags']);
        $this->assertSame('all', $requests[0]['body']['tags_match']);
        $this->assertSame('high', $requests[0]['body']['budget']);
        $this->assertSame(512, $requests[0]['body']['max_tokens']);
        // Without tags the match mode means nothing, so it is left to the API default.
        $this->assertArrayNotHasKey('tags_match', $requests[1]['body']);
    }

    public function test_a_second_bank_gets_its_own_tool_name(): void
    {
        $this->server->seed('user-42', ['Prefers short answers']);
        $this->server->seed('team', ['Deploys on Fridays']);
        $teamRecall = new Recall($this->client, 'team');
        $factory = (new MemoryToolFactory())->addTool($teamRecall, 'recall_team_memory', 'Search the team\'s shared memory.');
        $toolbox = new Toolbox(
            [new Recall($this->client, 'user-42'), $teamRecall],
            new ChainFactory([$factory, new ReflectionToolFactory()]),
        );

        $this->assertSame(['recall_memory', 'recall_team_memory'], array_map(static fn ($tool): string => $tool->getName(), $toolbox->getTools()));
        $this->assertStringContainsString('Prefers short answers', (string) $toolbox->execute(new ToolCall('1', 'recall_memory', ['query' => 'x']))->getResult());
        $this->assertStringContainsString('Deploys on Fridays', (string) $toolbox->execute(new ToolCall('2', 'recall_team_memory', ['query' => 'x']))->getResult());
    }

    public function test_recall_without_memories(): void
    {
        $recall = new Recall($this->client, 'user-new');

        $this->assertSame('No relevant memories found.', $recall('Anything?'));

        $this->server->seed('user-new', []);
        $this->assertSame('No relevant memories found.', $recall('Anything?'));
    }

    public function test_retain_stores_the_content_with_its_context_and_tags(): void
    {
        $user = 'user-7';
        $retain = new Retain($this->client, function () use (&$user): string {
            return $user;
        }, tags: ['app:support']);

        $this->assertSame('Stored in memory.', $retain('The user prefers short answers.', 'the user said so in chat'));
        $this->assertSame('Stored in memory.', $retain('The user lives in Valencia.'));
        $this->assertSame('Stored in memory.', (new Retain($this->client, 'user-7', async: false))('The user has a dog.'));

        $retained = $this->server->retained();
        $this->assertCount(3, $retained);
        $this->assertSame('user-7', $retained[0]['bank']);
        $this->assertSame('The user prefers short answers.', $retained[0]['item']['content']);
        $this->assertSame('the user said so in chat', $retained[0]['item']['context']);
        $this->assertSame(['app:support'], $retained[0]['item']['tags']);
        $this->assertArrayNotHasKey('context', $retained[1]['item']);

        $retains = array_values(array_filter($this->server->requests(), static fn (array $request): bool => $request['endpoint'] === 'retain'));
        $this->assertTrue($retains[0]['body']['async']);
        $this->assertFalse($retains[2]['body']['async'] ?? false);
    }

    public function test_reflect_answers_from_memory(): void
    {
        $this->server->seed('user-42', ['Is on the Pro plan', 'Prefers short answers']);
        $this->server->reflectUsing('The user is on the Pro plan and likes short answers.');

        $this->assertSame('The user is on the Pro plan and likes short answers.', (new Reflect($this->client, 'user-42'))('What do you know about me?'));
        $this->assertSame('low', $this->server->requests()[0]['body']['budget']);
    }

    public function test_reflect_on_a_bank_nobody_wrote_to(): void
    {
        $this->server->respondTo('POST', '/v1/default/banks/user-new/reflect', ['detail' => "Bank 'user-new' not found"], 404);

        $this->assertSame('There is nothing in memory yet.', (new Reflect($this->client, 'user-new'))('What do you know about me?'));
    }

    public function test_an_unavailable_server_is_reported_to_the_model_and_logged(): void
    {
        $this->server->unavailable(null, 503);
        $logger = new ArrayLogger;

        $result = (new Recall($this->client, 'user-42', logger: $logger))('Anything?');

        $this->assertStringStartsWith('Memory is unavailable: ', $result);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
    }
}
