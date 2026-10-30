<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Ai\Tools;

use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Shared setup of the memory tools: which bank, which tags, which client.
 */
abstract class MemoryTool implements Tool
{
    protected ?string $name = null;

    /** @var list<string>|null */
    protected ?array $tags = null;

    protected TagsMatch|string $tagsMatch = TagsMatch::Any;

    protected Budget|string $budget = Budget::Mid;

    protected ?Hindsight $client = null;

    final public function __construct(protected readonly string $bankId) {}

    public static function bank(string $bankId): static
    {
        return new static($bankId);
    }

    /**
     * Limit the tool to memories with these tags. Retain also tags what it stores with them.
     *
     * @param  list<string>  $tags
     */
    public function tags(array $tags, TagsMatch|string $match = TagsMatch::Any): static
    {
        $this->tags = $tags;
        $this->tagsMatch = $match;

        return $this;
    }

    /**
     * Give the tool another name, e.g. when an agent has memory tools for two banks.
     */
    public function named(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function using(Hindsight $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function name(): string
    {
        return $this->name ?? $this->defaultName();
    }

    public function handle(Request $request): string
    {
        try {
            return $this->run($request);
        } catch (HindsightException $exception) {
            report($exception);

            // Telling the model lets it answer without memory instead of ending the whole run.
            return "Memory is unavailable: {$exception->getMessage()}";
        }
    }

    abstract protected function defaultName(): string;

    abstract protected function run(Request $request): string;

    protected function client(): Hindsight
    {
        return $this->client ??= app(Hindsight::class);
    }
}
