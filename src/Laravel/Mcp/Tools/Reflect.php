<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Mcp\Tools;

use Fedoseev\Hindsight\Budget;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Lets the client ask Hindsight to reason over everything in memory, for questions one search cannot answer.
 */
#[IsReadOnly]
#[IsOpenWorld(false)]
final class Reflect extends MemoryTool
{
    protected string $title = 'Reflect on memory';

    protected Budget|string $budget = Budget::Low;

    public function budget(Budget|string $budget): self
    {
        $this->budget = $budget;

        return $this;
    }

    public function description(): string
    {
        return 'Answer a question by reasoning over everything in long-term memory. Slower than recall; '
            .'use it for summaries and for questions that need several memories combined.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The question to answer from memory.')->required(),
        ];
    }

    protected function defaultTimeout(): float
    {
        // Reflect runs an agent loop with the server's LLM, which takes longer than a search.
        return 60.0;
    }

    protected function defaultName(): string
    {
        return 'reflect_memory';
    }

    protected function run(Request $request, string $bankId): string
    {
        $request->validate(['query' => ['required', 'string']]);

        return $this->client()->reflect(
            $bankId,
            $request->string('query')->toString(),
            budget: $this->budget,
            tags: $this->tags,
            tagsMatch: $this->tagsMatch,
        )->text;
    }
}
