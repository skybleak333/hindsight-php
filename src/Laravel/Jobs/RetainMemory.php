<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Jobs;

use DateTimeInterface;
use Fedoseev\Hindsight\Exceptions\ApiException;
use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Hindsight;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Stores a memory from a queue worker, with retries, so a Hindsight outage delays memories instead of losing them.
 *
 *     RetainMemory::dispatch('user-42', 'Prefers email over phone.', tags: ['user:42']);
 *
 * Retries cannot store a memory twice. The job gets an operation ID when it is dispatched and sends it with
 * every attempt; Hindsight treats an async retain with an operation ID it has seen as the same operation, even
 * when the earlier attempt reached the server and only the answer was lost. The job also records when the
 * memory was dispatched and sends that as its timestamp, so a memory that waited in the queue is not dated by
 * the moment a worker got to it.
 *
 * Connection failures, timeouts, 429 and 5xx answers are retried with the backoff from config/hindsight.php.
 * Other errors (a wrong API key, a rejected request) are reported and fail the job at once: retrying would not
 * change them.
 */
final class RetainMemory implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds one attempt waits for Hindsight. The work itself runs on the server after it answers. */
    public const DEFAULT_TIMEOUT = 10.0;

    public int $tries;

    /** @var int|list<int> */
    public int|array $backoff;

    /** Sent with every attempt, so the server recognises a retry. */
    public readonly string $operationId;

    /** When the memory was dispatched, in ISO 8601. */
    public readonly string $timestamp;

    /**
     * Seconds one attempt waits for Hindsight. Not named $timeout: a worker reads that property as the job's own
     * time limit.
     */
    public readonly float $requestTimeout;

    /**
     * @param  list<string>|null  $tags
     * @param  array<string, string>|null  $metadata
     * @param  string|null  $updateMode  For an existing document: "replace" (the server's default) or "append".
     */
    public function __construct(
        public readonly string $bankId,
        public readonly string $content,
        public readonly ?string $context = null,
        public readonly ?string $documentId = null,
        public readonly ?array $tags = null,
        public readonly ?array $metadata = null,
        public readonly ?string $updateMode = null,
        DateTimeInterface|string|null $timestamp = null,
        float $timeout = self::DEFAULT_TIMEOUT,
    ) {
        $this->operationId = (string) Str::uuid();
        $this->requestTimeout = $timeout;
        $this->timestamp = match (true) {
            $timestamp instanceof DateTimeInterface => $timestamp->format(DateTimeInterface::ATOM),
            is_string($timestamp) => $timestamp,
            default => now()->format(DateTimeInterface::ATOM),
        };

        $tries = config('hindsight.queue.tries', 5);
        $backoff = config('hindsight.queue.backoff', [10, 30, 60, 120]);

        $this->tries = is_numeric($tries) ? max(1, (int) $tries) : 5;
        $this->backoff = match (true) {
            is_int($backoff) => $backoff,
            is_array($backoff) => array_values(array_map(static fn (mixed $seconds): int => is_numeric($seconds) ? (int) $seconds : 0, $backoff)),
            default => [10, 30, 60, 120],
        };

        $connection = config('hindsight.queue.connection');
        $queue = config('hindsight.queue.queue');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(Hindsight $hindsight): void
    {
        try {
            $hindsight->withTimeout($this->requestTimeout)->retain(
                $this->bankId,
                $this->content,
                timestamp: $this->timestamp,
                context: $this->context,
                documentId: $this->documentId,
                metadata: $this->metadata,
                tags: $this->tags,
                updateMode: $this->updateMode,
                async: true,
                operationId: $this->operationId,
            );
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (ApiException $exception) {
            if ($exception->statusCode === 429 || $exception->statusCode >= 500) {
                throw $exception;
            }

            $this->failWith($exception);
        } catch (HindsightException $exception) {
            $this->failWith($exception);
        }
    }

    /**
     * A job failed with fail() is recorded as failed but not reported, so report it: a wrong API key should
     * reach the error tracker, not only the failed_jobs table.
     */
    private function failWith(HindsightException $exception): void
    {
        report($exception);

        $this->fail($exception);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['hindsight', "hindsight:{$this->bankId}"];
    }
}
