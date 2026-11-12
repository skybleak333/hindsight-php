<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Neuron\Tools;

use Closure;
use Fedoseev\Hindsight\Hindsight;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Retain extends MemoryTool
{
    protected string $name = 'retain_memory';

    protected ?string $description = 'Store information in long-term memory so it can be recalled in later conversations. '
        .'Use it for lasting facts, preferences and decisions, not for small talk.';

    /**
     * @param  string|Closure(): string  $bank
     * @param  list<string>|null  $tags
     * @param  bool  $async  store without waiting for Hindsight to extract the facts
     */
    public function __construct(
        Hindsight $client,
        string|Closure $bank,
        ?array $tags = null,
        private readonly bool $async = true,
        ?float $timeout = null,
        LoggerInterface $logger = new NullLogger,
    ) {
        parent::__construct($client, $bank, $tags, timeout: $timeout, logger: $logger);
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('content', PropertyType::STRING, 'The information to remember, in full sentences.', required: true),
            new ToolProperty('context', PropertyType::STRING, 'Where the information comes from, e.g. "the user said so in chat".'),
        ];
    }

    public function __invoke(string $content, ?string $context = null): string
    {
        return $this->call(function (Hindsight $client, string $bankId) use ($content, $context): string {
            $client->retain(
                $bankId,
                $content,
                context: $context === null || $context === '' ? null : $context,
                tags: $this->tags,
                async: $this->async,
            );

            return 'Stored in memory.';
        });
    }

    protected function defaultTimeout(): ?float
    {
        // A synchronous retain waits for fact extraction by the server's LLM.
        return $this->async ? 15.0 : null;
    }
}
