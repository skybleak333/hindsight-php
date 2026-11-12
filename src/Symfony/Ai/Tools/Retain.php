<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Symfony\Ai\Tools;

use Closure;
use Fedoseev\Hindsight\Hindsight;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool(
    'retain_memory',
    'Store information in long-term memory so it can be recalled in later conversations. '
    .'Use it for lasting facts, preferences and decisions, not for small talk.',
)]
final class Retain extends MemoryTool
{
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

    public function __invoke(
        #[Schema(description: 'The information to remember, in full sentences.')]
        string $content,
        #[Schema(description: 'Where the information comes from, e.g. "the user said so in chat".')]
        ?string $context = null,
    ): string {
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
