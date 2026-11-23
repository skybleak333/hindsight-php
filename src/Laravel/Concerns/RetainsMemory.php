<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Concerns;

use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\QueueDispatchException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Jobs\RetainMemory;
use Illuminate\Contracts\Bus\Dispatcher;
use LogicException;
use Throwable;

/**
 * Storage shared by the Laravel AI SDK and Laravel MCP retain tools.
 *
 * @internal
 */
trait RetainsMemory
{
    private const QUEUE_AND_USING = 'A queued retain resolves its client from the container, so queue() cannot be combined with using().';

    private const QUEUE_AND_SYNC = 'A queued retain is always async: call either queue() or synchronously().';

    private bool $async = true;

    private bool $queued = false;

    private ?string $queueConnection = null;

    private ?string $queueName = null;

    /**
     * Wait until Hindsight has extracted the facts. By default it extracts them in the background.
     */
    public function synchronously(): self
    {
        if ($this->queued) {
            throw new LogicException(self::QUEUE_AND_SYNC);
        }

        $this->async = false;

        return $this;
    }

    /**
     * Store through Laravel's queue, so an unavailable Hindsight API can be retried by the worker.
     */
    public function queue(?string $connection = null, ?string $queue = null): self
    {
        if (! $this->async) {
            throw new LogicException(self::QUEUE_AND_SYNC);
        }

        if ($this->client !== null) {
            throw new LogicException(self::QUEUE_AND_USING);
        }

        $this->queued = true;
        $this->queueConnection = $connection;
        $this->queueName = $queue;

        return $this;
    }

    public function using(Hindsight $client): static
    {
        if ($this->queued) {
            throw new LogicException(self::QUEUE_AND_USING);
        }

        return parent::using($client);
    }

    protected function defaultTimeout(): ?float
    {
        return $this->async ? 15.0 : null;
    }

    private function storeMemory(string $bankId, string $content, ?string $context): string
    {
        if (! $this->queued) {
            $this->client()->retain($bankId, $content, context: $context, tags: $this->tags, async: $this->async);

            return 'Stored in memory.';
        }

        $job = new RetainMemory(
            $bankId,
            $content,
            context: $context,
            tags: $this->tags,
            timeout: $this->timeout ?? RetainMemory::DEFAULT_TIMEOUT,
        );

        if ($this->queueConnection !== null) {
            $job->onConnection($this->queueConnection);
        }

        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (HindsightException $exception) {
            // A synchronous Laravel connection can run the job in the current process.
            throw $exception;
        } catch (Throwable $exception) {
            throw new QueueDispatchException('The memory could not be queued.', previous: $exception);
        }

        return 'Memory accepted for storage.';
    }
}
