<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai\Tools;

use Fedoseev\Hindsight\Budget;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Lets the agent search long-term memory when it decides it needs to.
 */
final class Recall extends MemoryTool
{
    private int $maxTokens = 2048;

    public function budget(Budget|string $budget, int $maxTokens = 2048): self
    {
        $this->budget = $budget;
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function description(): string
    {
        return 'Search long-term memory for facts relevant to a query: what the user told you before, '
            .'their preferences, and earlier decisions. Use it before answering questions that depend on the past.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to look for, as a short natural-language question.')->required(),
        ];
    }

    protected function defaultTimeout(): float
    {
        return 15.0;
    }

    protected function nothingStored(): string
    {
        return 'No relevant memories found.';
    }

    protected function defaultName(): string
    {
        return 'recall_memory';
    }

    protected function run(Request $request): string
    {
        $memories = $this->client()->recall(
            $this->bankId,
            $request->string('query')->toString(),
            budget: $this->budget,
            maxTokens: $this->maxTokens,
            tags: $this->tags,
            tagsMatch: $this->tagsMatch,
        );

        return $memories->isEmpty()
            ? 'No relevant memories found.'
            : "Relevant memories, most relevant first:\n".$memories->toPrompt();
    }
}
