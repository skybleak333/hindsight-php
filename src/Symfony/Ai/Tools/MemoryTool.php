<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Symfony\Ai\Tools;

use Closure;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Base of the memory tools for Symfony AI agents. A Hindsight error does not end the agent's run:
 * the model is told that memory is unavailable and can answer without it.
 */
abstract class MemoryTool
{
    /**
     * @param  string|Closure(): string  $bank  the bank ID, or a closure that returns it for the current user
     * @param  list<string>|null  $tags  tags given to retained memories and required on recalled ones
     * @param  float|null  $timeout  seconds for each call; null uses the tool's default
     */
    public function __construct(
        protected readonly Hindsight $client,
        private readonly string|Closure $bank,
        protected readonly ?array $tags = null,
        protected readonly TagsMatch|string $tagsMatch = TagsMatch::AnyStrict,
        private readonly ?float $timeout = null,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * @param  Closure(Hindsight, string): string  $call
     */
    protected function call(Closure $call): string
    {
        $bankId = is_string($this->bank) ? $this->bank : ($this->bank)();
        $timeout = $this->timeout ?? $this->defaultTimeout();

        try {
            return $call($timeout === null ? $this->client : $this->client->withTimeout($timeout), $bankId);
        } catch (NotFoundException $exception) {
            if ($exception->isMissingBank($bankId)) {
                return $this->nothingStored();
            }

            $this->log($exception);

            return "Memory is unavailable: {$exception->getMessage()}";
        } catch (HindsightException $exception) {
            $this->log($exception);

            // Telling the model lets it answer without memory instead of ending the whole run.
            return "Memory is unavailable: {$exception->getMessage()}";
        }
    }

    abstract protected function defaultTimeout(): ?float;

    /**
     * The match mode only means something with tags, so without them the API default is sent.
     */
    protected function requestTagsMatch(): TagsMatch|string
    {
        return $this->tags === null ? TagsMatch::Any : $this->tagsMatch;
    }

    protected function nothingStored(): string
    {
        return 'There is nothing in memory yet.';
    }

    private function log(HindsightException $exception): void
    {
        $this->logger->warning('Hindsight memory tool failed: {message}', [
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
