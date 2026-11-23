<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Fedoseev\Hindsight\Laravel\Facades\Hindsight;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;

final class RecallCommandTest extends TestCase
{
    public function test_it_prints_the_same_prompt_text_an_agent_receives(): void
    {
        $this->fakeHindsight();
        $this->queueRecall([
            ['id' => 'first', 'text' => 'Alice is on the <info>Pro</info> plan', 'occurred_start' => '2026-11-14T00:00:00Z'],
            ['id' => 'second', 'text' => 'Alice prefers email'],
        ]);

        $this->artisan('hindsight:recall', ['bank' => 'support', 'query' => 'What plan am I on?'])
            ->expectsOutput("- Alice is on the <info>Pro</info> plan (2026-11-14)\n- Alice prefers email")
            ->expectsOutput('2 results.')
            ->assertSuccessful();

        $this->assertSame(['POST /v1/default/banks/support/memories/recall'], $this->sentPaths());
        $this->assertSame('What plan am I on?', $this->sentJson(0)['query']);
        $this->assertSame('low', $this->sentJson(0)['budget']);
        $this->assertSame(1024, $this->sentJson(0)['max_tokens']);
    }

    public function test_it_applies_filters_and_retrieval_options(): void
    {
        $fake = Hindsight::fake(['support' => [
            ['text' => 'Alice is on the Pro plan', 'tags' => ['user:42', 'billing'], 'type' => 'world'],
            ['text' => 'Bob is on the Free plan', 'tags' => ['user:43', 'billing'], 'type' => 'world'],
            ['text' => 'Shared billing FAQ', 'type' => 'world'],
            ['text' => 'Alice called support', 'tags' => ['user:42', 'billing'], 'type' => 'experience'],
        ]]);

        $this->artisan('hindsight:recall', [
            'bank' => 'support',
            'query' => 'What plan am I on?',
            '--tags' => ['user:42', 'billing'],
            '--tags-match' => 'all_strict',
            '--type' => ['world', 'observation'],
            '--budget' => 'high',
            '--max-tokens' => '800',
        ])
            ->expectsOutput('- Alice is on the Pro plan')
            ->expectsOutput('1 result.')
            ->assertSuccessful();

        $this->assertSame(['query' => 'What plan am I on?', 'budget' => 'high', 'max_tokens' => 800,
            'types' => ['world', 'observation'], 'tags' => ['user:42', 'billing'], 'tags_match' => 'all_strict',
        ], $fake->recalls('support')[0]);
    }

    public function test_a_missing_bank_and_an_empty_result_are_normal(): void
    {
        Hindsight::fake(['support' => ['Shared billing FAQ']]);

        $this->artisan('hindsight:recall', ['bank' => 'new', 'query' => 'renewal'])
            ->expectsOutput('No memories yet (0 results).')
            ->assertSuccessful();

        $this->artisan('hindsight:recall', ['bank' => 'support', 'query' => 'renewal', '--tags' => ['user:42']])
            ->expectsOutput('No matching memories (0 results).')
            ->assertSuccessful();
    }

    public function test_a_generic_404_reports_the_error(): void
    {
        $this->fakeHindsight()->json(['detail' => 'Not Found'], 404);

        $this->artisan('hindsight:recall', ['bank' => 'support', 'query' => 'renewal'])
            ->expectsOutput('Hindsight API error 404: Not Found')
            ->assertFailed();
    }

    /** @param array<string, string|list<string>> $options */
    #[DataProvider('invalidOptions')]
    public function test_invalid_input_is_rejected_before_calling_hindsight(array $options): void
    {
        $http = $this->fakeHindsight();

        $this->artisan('hindsight:recall', $options + ['bank' => 'support', 'query' => 'renewal'])
            ->assertExitCode(Command::INVALID);

        $this->assertSame([], $http->requests);
    }

    /** @return iterable<string, array{array<string, string|list<string>>}> */
    public static function invalidOptions(): iterable
    {
        yield 'empty query' => [['query' => '  ']];
        yield 'unknown budget' => [['--budget' => 'bright']];
        yield 'unknown tag match' => [['--tags-match' => 'strict']];
        yield 'unknown fact type' => [['--type' => ['world', 'event']]];
        yield 'negative token limit' => [['--max-tokens' => '-1']];
        yield 'token limit past integer range' => [['--max-tokens' => str_repeat('9', 25)]];
    }
}
