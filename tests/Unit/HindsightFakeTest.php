<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Exceptions\ApiException;
use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Exceptions\OperationFailedException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Responses\OperationStatus;
use Fedoseev\Hindsight\TagsMatch;
use Fedoseev\Hindsight\Testing\FakeServer;
use Fedoseev\Hindsight\Testing\HindsightFake;
use Fedoseev\Hindsight\Tests\Support\ContractCheckingClient;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HindsightFakeTest extends TestCase
{
    public function test_recall_finds_seeded_memories_and_what_was_retained(): void
    {
        $fake = new HindsightFake(['user-1' => ['Alice is on the Pro plan']]);

        $fake->retain('user-1', 'Alice prefers short answers.');

        $this->assertSame(['Alice is on the Pro plan', 'Alice prefers short answers.'], $fake->recall('user-1', 'plan')->texts());
    }

    public function test_a_bank_nobody_wrote_to_answers_404_like_the_server(): void
    {
        $fake = new HindsightFake;

        try {
            $fake->recall('nobody', 'anything');
            $this->fail('Expected a 404.');
        } catch (NotFoundException $exception) {
            $this->assertSame(['detail' => "Bank 'nobody' not found"], $exception->body);
        }

        // Seeded with no memories: the bank exists and is empty.
        $this->assertTrue($fake->remember('empty')->recall('empty', 'anything')->isEmpty());
    }

    /**
     * @param  list<string>|null  $tags
     * @param  list<string>  $expected
     */
    #[DataProvider('tagFilters')]
    public function test_tag_filters_follow_the_server(?array $tags, TagsMatch $match, array $expected): void
    {
        $fake = new HindsightFake;
        $fake->retain('b', 'untagged');
        $fake->retain('b', 'tagged a', tags: ['a']);
        $fake->retain('b', 'tagged b', tags: ['b']);
        $fake->retain('b', 'tagged a and b', tags: ['a', 'b']);

        $this->assertSame($expected, $fake->recall('b', 'q', tags: $tags, tagsMatch: $match)->texts());
    }

    /**
     * The rules of tags.py in Hindsight 1.2.0 (build_tags_where_clause). The integration suite checks the same
     * table against a real server.
     *
     * @return iterable<string, array{0: list<string>|null, 1: TagsMatch, 2: list<string>}>
     */
    public static function tagFilters(): iterable
    {
        $all = ['untagged', 'tagged a', 'tagged b', 'tagged a and b'];

        yield 'no tags: no filter' => [null, TagsMatch::Any, $all];
        yield 'no tags, strict: still no filter' => [null, TagsMatch::AllStrict, $all];
        yield 'any keeps untagged' => [['a'], TagsMatch::Any, ['untagged', 'tagged a', 'tagged a and b']];
        yield 'all keeps untagged' => [['a', 'b'], TagsMatch::All, ['untagged', 'tagged a and b']];
        yield 'any_strict' => [['a'], TagsMatch::AnyStrict, ['tagged a', 'tagged a and b']];
        yield 'any_strict, two tags' => [['a', 'b'], TagsMatch::AnyStrict, ['tagged a', 'tagged b', 'tagged a and b']];
        yield 'all_strict' => [['a', 'b'], TagsMatch::AllStrict, ['tagged a and b']];
        yield 'exact' => [['a'], TagsMatch::Exact, ['tagged a']];
        yield 'exact, order does not matter' => [['b', 'a'], TagsMatch::Exact, ['tagged a and b']];
        yield 'exact without tags: untagged only' => [null, TagsMatch::Exact, ['untagged']];
    }

    public function test_recall_filters_by_type(): void
    {
        $fake = new HindsightFake(['b' => [['text' => 'a fact'], ['text' => 'an experience', 'type' => 'experience']]]);

        $this->assertSame(['an experience'], $fake->recall('b', 'q', types: ['experience'])->texts());
    }

    public function test_a_retain_with_a_document_id_replaces_the_document_unless_it_appends(): void
    {
        $fake = new HindsightFake;

        $fake->retain('b', 'first version', documentId: 'doc');
        $fake->retain('b', 'second version', documentId: 'doc');
        $this->assertSame(['second version'], $fake->recall('b', 'q')->texts());

        $fake->retain('b', 'more', documentId: 'doc', updateMode: 'append');
        $this->assertSame(['second version', 'more'], $fake->recall('b', 'q')->texts());
    }

    public function test_an_async_retain_repeated_with_the_same_operation_id_stores_nothing(): void
    {
        $fake = new HindsightFake;
        $id = '8e2d1f6a-3c4b-4d5e-9f60-7a8b9c0d1e2f';

        $first = $fake->retain('b', 'Prefers email.', async: true, operationId: $id);
        $retry = $fake->retain('b', 'Prefers email.', async: true, operationId: $id);

        $this->assertSame($id, $first->operationId);
        $this->assertSame($id, $retry->operationId);
        $this->assertSame(['Prefers email.'], $fake->recall('b', 'q')->texts());
        $fake->assertRetained('b', 1);

        try {
            $fake->retain('other-bank', 'x', async: true, operationId: $id);
            $this->fail('Expected a conflict.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->statusCode);
        }
    }

    public function test_async_operations_finish_at_once(): void
    {
        $fake = new HindsightFake;

        $response = $fake->retain('b', 'x', async: true);

        $this->assertNotNull($response->operationId);
        $this->assertSame(OperationStatus::Completed, $fake->waitForOperation('b', $response->operationId)->status);

        $this->expectException(OperationFailedException::class);
        $fake->waitForOperation('b', '0b6e2c6e-0000-4000-8000-000000000000');
    }

    public function test_reflect_answers_with_the_configured_text(): void
    {
        $fake = new HindsightFake(['b' => []]);

        $this->assertSame('', $fake->reflect('b', 'Who is Alice?')->text);

        $fake->reflectUsing('Alice is a customer.');
        $this->assertSame('Alice is a customer.', $fake->reflect('b', 'Who is Alice?')->text);

        $fake->reflectUsing(fn (string $query, string $bank): string => "{$bank}: {$query}");
        $this->assertSame('b: Who is Alice?', $fake->reflect('b', 'Who is Alice?')->text);
    }

    public function test_it_refuses_endpoints_it_does_not_simulate_unless_stubbed(): void
    {
        $fake = new HindsightFake;

        $fake->respondTo('GET', '/v1/default/banks/*/stats', ['bank_id' => 'stubbed']);
        $this->assertSame(['bank_id' => 'stubbed'], $fake->getBankStats('support'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HindsightFake does not simulate DELETE /v1/default/banks/support');
        $fake->deleteBank('support');
    }

    public function test_it_can_be_unavailable(): void
    {
        $fake = new HindsightFake(['b' => ['x']]);

        $fake->unavailable(times: 1);
        $this->assertThrows(ConnectionException::class, fn () => $fake->recall('b', 'q'));
        $this->assertSame(['x'], $fake->recall('b', 'q')->texts());

        $fake->unavailable(status: 503);
        $this->assertThrows(ApiException::class, fn () => $fake->retain('b', 'lost'));
        $this->assertThrows(ApiException::class, fn () => $fake->retain('b', 'lost'));

        $fake->available();
        $fake->assertNotRetained('b');
        $fake->assertRecalled('b', 1);
    }

    public function test_retain_assertions(): void
    {
        $fake = new HindsightFake;
        $fake->retainBatch('user-1', [
            ['content' => 'Prefers email.', 'tags' => ['user:1']],
            ['content' => 'Lives in Rotterdam.', 'tags' => ['user:1']],
        ]);

        $fake->assertRetained('user-1');
        $fake->assertRetained('user-1', 2);
        $fake->assertRetained('user-1', fn (string $content, array $item): bool => $content === 'Prefers email.' && $item['tags'] === ['user:1']);
        $fake->assertNotRetained('user-2');
        $fake->assertNotRetained('user-1', fn (string $content): bool => str_contains($content, 'phone'));
        $this->assertCount(1, $fake->retained('user-1', fn (string $content): bool => str_contains($content, 'Rotterdam')));

        $this->assertFails(fn () => $fake->assertRetained('user-2'), 'The expected memory was not retained in bank [user-2].');
        $this->assertFails(fn () => $fake->assertRetained('user-1', 3), 'Expected 3 memories retained in bank [user-1], got 2.');
        $this->assertFails(fn () => $fake->assertNothingRetained(), 'Memories were retained in: user-1.');
        (new HindsightFake)->assertNothingRetained();
    }

    public function test_recall_and_reflect_assertions(): void
    {
        $fake = new HindsightFake(['b' => []]);
        $fake->recall('b', 'When does my plan renew?', tags: ['user:1'], tagsMatch: TagsMatch::AnyStrict);
        $fake->reflect('b', 'Summarise');

        $fake->assertRecalled('b');
        $fake->assertRecalled('b', 1);
        $fake->assertRecalled('b', fn (string $query, array $request): bool => $query === 'When does my plan renew?' && $request['tags_match'] === 'any_strict');
        $fake->assertNotRecalled('other');
        $fake->assertReflected('b', fn (string $query): bool => $query === 'Summarise');
        $this->assertSame('When does my plan renew?', $fake->recalls('b')[0]['query']);

        $this->assertFails(fn () => $fake->assertRecalled('other'), 'Bank [other] was not recalled from as expected.');
        $this->assertFails(fn () => $fake->assertNotRecalled('b'), 'Bank [b] was recalled from unexpectedly.');
        $this->assertFails(fn () => $fake->assertReflected('b', 2), 'Expected bank [b] to be reflected on 2 times, got 1.');
    }

    public function test_every_request_and_answer_matches_the_openapi_document(): void
    {
        $client = new Hindsight('http://hindsight.test', httpClient: new ContractCheckingClient(new FakeServer));

        $client->health();
        $client->version();
        $client->retain('b', 'Prefers email.', context: 'chat', documentId: 'doc', metadata: ['channel' => 'chat'], tags: ['user:1']);
        $client->retain('b', [['type' => 'text', 'text' => 'In blocks.']], metadata: []);
        $async = $client->retain('b', 'Async.', async: true, operationId: '8e2d1f6a-3c4b-4d5e-9f60-7a8b9c0d1e2f');
        $client->retainBatch('b', [['content' => 'One.'], ['content' => 'Two.']], async: true);
        $recall = $client->recall('b', 'email', types: ['world'], tags: ['user:1'], tagsMatch: TagsMatch::Any);
        $client->reflect('b', 'Summarise', includeFacts: true);
        $client->getOperation('b', (string) $async->operationId);
        $client->getOperation('b', '0b6e2c6e-0000-4000-8000-000000000000');

        $this->assertSame(['Prefers email.', 'In blocks.', 'Async.', 'One.', 'Two.'], $recall->texts());
    }

    /**
     * @param  class-string<\Throwable>  $class
     */
    private function assertThrows(string $class, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($class, $exception);

            return;
        }

        $this->fail("Expected {$class}.");
    }

    private function assertFails(callable $assertion, string $message): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString($message, $failure->getMessage());

            return;
        }

        $this->fail("Expected the assertion to fail with: {$message}");
    }
}
