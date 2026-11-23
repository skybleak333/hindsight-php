<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Mcp\Tools;

use Fedoseev\Hindsight\Laravel\Concerns\RetainsMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * Lets the client store something in long-term memory.
 */
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class Retain extends MemoryTool
{
    use RetainsMemory;

    protected string $title = 'Store in memory';

    public function description(): string
    {
        return 'Store information in long-term memory so it can be recalled in later conversations. '
            .'Use it for lasting facts, preferences and decisions, not for small talk.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('The information to remember, in full sentences.')->required(),
            'context' => $schema->string()->description('Where the information comes from, e.g. "the user said so in chat".'),
        ];
    }

    protected function defaultName(): string
    {
        return 'retain_memory';
    }

    protected function run(Request $request, string $bankId): string
    {
        $request->validate([
            'content' => ['required', 'string'],
            'context' => ['nullable', 'string'],
        ]);

        $context = $request->string('context')->toString();

        return $this->storeMemory(
            $bankId,
            $request->string('content')->toString(),
            $context === '' ? null : $context,
        );
    }
}
