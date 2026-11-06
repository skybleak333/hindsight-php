<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Jobs;

use DateTimeInterface;
use Fedoseev\Hindsight\Exceptions\ApiException;
use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Uuid;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Stores a memory from a queue worker, with retries, so a memory written while the Hindsight API is unavailable
 * arrives later instead of being lost.
 *
 *     RetainMemory::dispatch('user-42', 'Prefers email over phone.', tags: ['user:42']);
 *
 * Retries cannot store a memory twice. The job gets an operation ID when it is dispatched and sends it with
 * every attempt; Hindsight treats an async retain with an operation ID it has seen as the same operation, even
 * when the earlier attempt reached the server and only the answer was lost. The job also sends the time it was
 * dispatched as the memory's timestamp, so a memory that waited in the queue is not dated by the moment a
 * worker got to it.
 *
 * A refused connection, a timeout, 408, 429 and 5xx are temporary: the attempt is released back to the queue
 * with the backoff from config/hindsight.php, without reporting, and only a failure on the last attempt is
 * reported. Other errors (a wrong API key, a rejected request, a malformed URL) are reported and fail the job
 * at once, because a retry would not change them. On the sync driver, or when handle() is called directly,
 * there is no later attempt, so every error is thrown to the caller.
 *
 * The job succeeds once Hindsight has accepted the retain. Fact extraction then runs on the server; if it
 * fails there, for example because the server's LLM provider is down, the server retries and marks the
 * operation failed. Find those with listOperations(status: 'failed') and run them again with retryOperation().
 */
final class RetainMemory implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds one attempt waits for Hindsight. The work itself runs on the server after it answers. */
    public const DEFAULT_TIMEOUT = 10.0;

    public const DEFAULT_TRIES = 6;

    /** Seconds before each retry; the last step repeats, so six attempts span 2,170 seconds. */
    public const DEFAULT_BACKOFF = [10, 60, 300, 900];

    public string $bankId;

    public string $content;

    public ?string $context;

    public ?string $documentId;

    /**
     * The memory's tags. Not $tags, to keep it apart from tags(), which labels the job in Horizon.
     *
     * @var list<string>|null
     */
    public ?array $memoryTags;

    /** @var array<string, string>|null */
    public ?array $metadata;

    public ?string $updateMode;

    /** Sent with every attempt, so the server recognises a retry. */
    public string $operationId;

    /** When the memory was dispatched, in ISO 8601. */
    public string $timestamp;

    /**
     * Seconds one attempt waits for Hindsight. Not named $timeout: a worker reads that property as the job's own
     * time limit.
     */
    public float $requestTimeout;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    /** Laravel encrypts the job's payload in the queue when this is true (hindsight.queue.encrypt). */
    public bool $shouldBeEncrypted;

    /**
     * @param  list<string>|null  $tags
     * @param  array<string, string>|null  $metadata
     * @param  string|null  $updateMode  For an existing document: "replace" (the server's default) or "append".
     * @param  float  $timeout  Seconds one attempt waits for Hindsight.
     */
    public function __construct(
        string $bankId,
        string $content,
        ?string $context = null,
        ?string $documentId = null,
        ?array $tags = null,
        ?array $metadata = null,
        ?string $updateMode = null,
        DateTimeInterface|string|null $timestamp = null,
        float $timeout = self::DEFAULT_TIMEOUT,
    ) {
        $this->bankId = $bankId;
        $this->content = $content;
        $this->context = $context;
        $this->documentId = $documentId;
        $this->memoryTags = $tags;
        $this->metadata = $metadata;
        $this->updateMode = $updateMode;
        $this->requestTimeout = $timeout;
        $this->operationId = Uuid::v4();
        $this->timestamp = match (true) {
            $timestamp instanceof DateTimeInterface => $timestamp->format(DateTimeInterface::ATOM),
            is_string($timestamp) => $timestamp,
            default => now()->format(DateTimeInterface::ATOM),
        };

        $tries = config('hindsight.queue.tries');
        $backoff = config('hindsight.queue.backoff');
        $backoff = is_int($backoff) ? [$backoff] : (is_array($backoff) ? array_values(array_filter($backoff, is_numeric(...))) : []);

        $this->tries = is_numeric($tries) && (int) $tries > 0 ? (int) $tries : self::DEFAULT_TRIES;
        // An empty list would make Laravel retry at once, in a tight loop.
        $this->backoff = $backoff === [] ? self::DEFAULT_BACKOFF : array_map(static fn (mixed $seconds): int => max(0, (int) $seconds), $backoff);
        $this->shouldBeEncrypted = (bool) config('hindsight.queue.encrypt', false);

        $connection = config('hindsight.queue.connection');
        $queue = config('hindsight.queue.queue');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(): void
    {
        try {
            // Resolved here, not injected, so a malformed URL fails the job like any other error a retry cannot fix.
            Container::getInstance()->make(Hindsight::class)->withTimeout($this->requestTimeout)->retain(
                $this->bankId,
                $this->content,
                timestamp: $this->timestamp,
                context: $this->context,
                documentId: $this->documentId,
                metadata: $this->metadata,
                tags: $this->memoryTags,
                updateMode: $this->updateMode,
                async: true,
                operationId: $this->operationId,
            );
        } catch (ConnectionException $exception) {
            $this->retryLater($exception);
        } catch (ApiException $exception) {
            if (in_array($exception->statusCode, [408, 429], true) || $exception->statusCode >= 500) {
                $this->retryLater($exception);
            } else {
                $this->failNow($exception);
            }
        } catch (HindsightException $exception) {
            $this->failNow($exception);
        }
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['hindsight', "hindsight:{$this->bankId}"];
    }

    private function retryLater(HindsightException $exception): void
    {
        // The last attempt throws, so the worker reports the exception and records the job as failed.
        if (! $this->runsOnAQueue() || $this->attempts() >= $this->tries) {
            throw $exception;
        }

        // Earlier attempts go back to the queue without an exception, so an outage is not reported once per attempt.
        // Laravel's own rule (Worker::calculateBackoff): one step per attempt, then the last step again.
        $this->release($this->backoff[$this->attempts() - 1] ?? end($this->backoff) ?: 0);
    }

    private function failNow(HindsightException $exception): void
    {
        if (! $this->runsOnAQueue()) {
            throw $exception;
        }

        // A job failed with fail() is recorded but not reported, so report it: a wrong API key should reach the
        // error tracker, not only the failed_jobs table.
        report($exception);

        $this->fail($exception);
    }

    /**
     * Whether a later attempt is possible: not on the sync driver, which runs the job once while it is
     * dispatched, and not when handle() is called directly.
     */
    private function runsOnAQueue(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }
}
