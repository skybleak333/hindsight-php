<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Symfony\Ai\Tools;

use Closure;
use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool(
    'recall_memory',
    'Search long-term memory for facts relevant to a query: what the user told you before, '
    .'their preferences, and earlier decisions. Use it before answering questions that depend on the past.',
)]
final class Recall extends MemoryTool
{
    /**
     * @param  string|Closure(): string  $bank
     * @param  list<string>|null  $tags
     */
    public function __construct(
        Hindsight $client,
        string|Closure $bank,
        ?array $tags = null,
        TagsMatch|string $tagsMatch = TagsMatch::AnyStrict,
        private readonly Budget|string $budget = Budget::Mid,
        private readonly int $maxTokens = 2048,
        ?float $timeout = null,
        LoggerInterface $logger = new NullLogger,
    ) {
        parent::__construct($client, $bank, $tags, $tagsMatch, $timeout, $logger);
    }

    public function __invoke(
        #[Schema(description: 'What to look for, as a short natural-language question.')]
        string $query,
    ): string {
        return $this->call(function (Hindsight $client, string $bankId) use ($query): string {
            $memories = $client->recall(
                $bankId,
                $query,
                budget: $this->budget,
                maxTokens: $this->maxTokens,
                tags: $this->tags,
                tagsMatch: $this->requestTagsMatch(),
            );

            return $memories->isEmpty()
                ? 'No relevant memories found.'
                : "Relevant memories, most relevant first:\n".$memories->toPrompt();
        });
    }

    protected function defaultTimeout(): float
    {
        return 15.0;
    }

    protected function nothingStored(): string
    {
        return 'No relevant memories found.';
    }
}
