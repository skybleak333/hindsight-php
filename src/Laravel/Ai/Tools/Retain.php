<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai\Tools;

use Fedoseev\Hindsight\Laravel\Concerns\RetainsMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Lets the agent store something in long-term memory when it decides it is worth keeping.
 */
final class Retain extends MemoryTool
{
    use RetainsMemory;

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

    protected function run(Request $request): string
    {
        $context = $request->string('context')->toString();

        return $this->storeMemory(
            $this->bankId,
            $request->string('content')->toString(),
            $context === '' ? null : $context,
        );
    }
}
