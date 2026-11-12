<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Neuron\Tools;

use Closure;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Reflect extends MemoryTool
{
    protected string $name = 'reflect_memory';

    protected ?string $description = 'Answer a question by reasoning over everything in long-term memory. Slower than recall; '
        .'use it for summaries and for questions that need several memories combined.';

    /**
     * @param  string|Closure(): string  $bank
     * @param  list<string>|null  $tags
     */
    public function __construct(
        Hindsight $client,
        string|Closure $bank,
        ?array $tags = null,
        TagsMatch|string $tagsMatch = TagsMatch::AnyStrict,
        private readonly Budget|string $budget = Budget::Low,
        ?float $timeout = null,
        LoggerInterface $logger = new NullLogger,
    ) {
        parent::__construct($client, $bank, $tags, $tagsMatch, $timeout, $logger);
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('query', PropertyType::STRING, 'The question to answer from memory.', required: true),
        ];
    }

    public function __invoke(string $query): string
    {
        return $this->call(fn (Hindsight $client, string $bankId): string => $client->reflect(
            $bankId,
            $query,
            budget: $this->budget,
            tags: $this->tags,
            tagsMatch: $this->requestTagsMatch(),
        )->text);
    }

    protected function defaultTimeout(): float
    {
        // Reflect runs an agent loop with the server's LLM, which takes longer than a search.
        return 60.0;
    }
}
