<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Testing;

use Closure;
use Fedoseev\Hindsight\Hindsight;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A Hindsight client for tests: an in-memory server instead of HTTP, and assertions about what was sent.
 *
 *     $hindsight = Hindsight::fake(['user-1' => ['Alice is on the Pro plan']]);   // in Laravel, via the facade
 *     $hindsight = new HindsightFake(['user-1' => ['Alice is on the Pro plan']]); // anywhere else
 *
 * Recall finds the memories you seed and everything retained during the test. The fake follows the server's
 * rules where they decide what a test sees: a bank nobody wrote to answers 404, tag filters work like
 * Hindsight's, a retain with a document_id replaces that document, and an async retain repeated with the same
 * operation_id stores nothing. These retains are rejected with the server's status: 422 for an operation_id that
 * is not a UUID, a timestamp that is not ISO 8601 (or "unset"), a tag or metadata value that is not a string,
 * an unknown update_mode or a missing content, and 400 for an append without a document_id. Other invalid
 * fields are not checked. It does not extract facts (a retained item is one memory with its text as
 * sent), does not rank, and finishes async work at once. Reflect answers with the text set by reflectUsing().
 */
final class HindsightFake extends Hindsight
{
    private readonly FakeServer $server;

    /**
     * @param  array<string, list<string|array<string, mixed>>>  $memories  Memories by bank. A memory is its text, or a
     *                                                                      recall result such as ['text' => '…', 'tags' => ['user:1']].
     */
    public function __construct(array $memories = [])
    {
        $this->server = new FakeServer;

        foreach ($memories as $bankId => $bankMemories) {
            $this->server->seed((string) $bankId, $bankMemories);
        }

        parent::__construct('http://hindsight.test', httpClient: $this->server);
    }

    /**
     * Add memories that recall will find. A bank seeded with no memories exists but is empty.
     *
     * @param  string|array<string, mixed>  ...$memories
     */
    public function remember(string $bankId, string|array ...$memories): self
    {
        $this->server->seed($bankId, array_values($memories));

        return $this;
    }

    /**
     * Make requests fail, as if Hindsight were down: with a refused connection, or with $status (e.g. 503).
     * Without $times, every request fails until available() is called. Failed requests are not recorded.
     */
    public function unavailable(?int $times = null, ?int $status = null): self
    {
        $this->server->unavailable($times, $status);

        return $this;
    }

    public function available(): self
    {
        $this->server->available();

        return $this;
    }

    /**
     * What reflect answers: a fixed text, or a closure that receives the query, the bank ID and the request body.
     *
     * @param  string|Closure(string, string, array<string, mixed>): string  $answer
     */
    public function reflectUsing(string|Closure $answer): self
    {
        $this->server->reflectUsing($answer);

        return $this;
    }

    /**
     * Answer an endpoint the fake does not simulate, e.g. respondTo('GET', '/v1/default/banks/support/stats', [...]).
     * A "*" in the path matches one segment, such as any bank ID. The response is an array, or a closure that
     * receives the request body.
     *
     * @param  array<array-key, mixed>|Closure(array<string, mixed>): array<array-key, mixed>  $response
     */
    public function respondTo(string $method, string $path, array|Closure $response, int $status = 200): self
    {
        $this->server->respondTo($method, $path, $response, $status);

        return $this;
    }

    /**
     * Assert that a memory was stored in the bank. The callback receives the content and the whole item
     * (with the API's snake_case keys, e.g. tags and document_id) and returns whether it is the one. An integer
     * asserts how many items were stored.
     *
     * @param  (Closure(mixed, array<string, mixed>): bool)|int|null  $callback
     */
    public function assertRetained(string $bankId, Closure|int|null $callback = null): void
    {
        $count = count($this->retained($bankId, $callback instanceof Closure ? $callback : null));

        if (is_int($callback)) {
            PHPUnit::assertSame($callback, $count, "Expected {$callback} memories retained in bank [{$bankId}], got {$count}.");

            return;
        }

        PHPUnit::assertTrue($count > 0, "The expected memory was not retained in bank [{$bankId}].");
    }

    /**
     * @param  (Closure(mixed, array<string, mixed>): bool)|null  $callback
     */
    public function assertNotRetained(string $bankId, ?Closure $callback = null): void
    {
        PHPUnit::assertSame([], $this->retained($bankId, $callback), "An unexpected memory was retained in bank [{$bankId}].");
    }

    public function assertNothingRetained(): void
    {
        $banks = array_unique(array_column($this->server->retained(), 'bank'));

        PHPUnit::assertSame([], $banks, 'Memories were retained in: '.implode(', ', $banks).'.');
    }

    /**
     * Assert that the bank was searched. The callback receives the query and the request body. An integer
     * asserts how many times.
     *
     * @param  (Closure(string, array<string, mixed>): bool)|int|null  $callback
     */
    public function assertRecalled(string $bankId, Closure|int|null $callback = null): void
    {
        $this->assertSent('recall', $bankId, $callback, 'recalled from');
    }

    /**
     * @param  (Closure(string, array<string, mixed>): bool)|null  $callback
     */
    public function assertNotRecalled(string $bankId, ?Closure $callback = null): void
    {
        PHPUnit::assertSame([], $this->sent('recall', $bankId, $callback), "Bank [{$bankId}] was recalled from unexpectedly.");
    }

    /**
     * @param  (Closure(string, array<string, mixed>): bool)|int|null  $callback
     */
    public function assertReflected(string $bankId, Closure|int|null $callback = null): void
    {
        $this->assertSent('reflect', $bankId, $callback, 'reflected on');
    }

    /**
     * Every item stored in the bank during the test, as sent, with the API's keys. Retries that the server
     * would ignore (an async retain repeated with the same operation_id) are not included.
     *
     * @param  (Closure(mixed, array<string, mixed>): bool)|null  $callback
     * @return list<array<string, mixed>>
     */
    public function retained(string $bankId, ?Closure $callback = null): array
    {
        $items = [];

        foreach ($this->server->retained() as $retained) {
            if ($retained['bank'] === $bankId && ($callback === null || (bool) $callback($retained['item']['content'] ?? null, $retained['item']))) {
                $items[] = $retained['item'];
            }
        }

        return $items;
    }

    /**
     * The body of every recall request sent to the bank.
     *
     * @return list<array<string, mixed>>
     */
    public function recalls(string $bankId): array
    {
        return $this->sent('recall', $bankId, null);
    }

    /**
     * @param  (Closure(string, array<string, mixed>): bool)|int|null  $callback
     */
    private function assertSent(string $endpoint, string $bankId, Closure|int|null $callback, string $verb): void
    {
        $count = count($this->sent($endpoint, $bankId, $callback instanceof Closure ? $callback : null));

        if (is_int($callback)) {
            PHPUnit::assertSame($callback, $count, "Expected bank [{$bankId}] to be {$verb} {$callback} times, got {$count}.");

            return;
        }

        PHPUnit::assertTrue($count > 0, "Bank [{$bankId}] was not {$verb} as expected.");
    }

    /**
     * @param  (Closure(string, array<string, mixed>): bool)|null  $callback
     * @return list<array<string, mixed>>
     */
    private function sent(string $endpoint, string $bankId, ?Closure $callback): array
    {
        $bodies = [];

        foreach ($this->server->requests() as $request) {
            if ($request['endpoint'] !== $endpoint || $request['bank'] !== $bankId) {
                continue;
            }

            $query = is_string($request['body']['query'] ?? null) ? $request['body']['query'] : '';

            if ($callback === null || (bool) $callback($query, $request['body'])) {
                $bodies[] = $request['body'];
            }
        }

        return $bodies;
    }
}
