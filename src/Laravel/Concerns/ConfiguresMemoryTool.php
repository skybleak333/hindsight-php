<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Concerns;

use Fedoseev\Hindsight\Budget;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\TagsMatch;

/**
 * Setup shared by the memory tools for Laravel AI SDK agents and for Laravel MCP servers.
 *
 * @internal
 */
trait ConfiguresMemoryTool
{
    /** @var list<string>|null */
    protected ?array $tags = null;

    protected TagsMatch|string $tagsMatch = TagsMatch::Any;

    protected Budget|string $budget = Budget::Mid;

    protected ?Hindsight $client = null;

    protected ?float $timeout = null;

    /**
     * Limit the tool to memories with these tags. Retain also tags what it stores with them.
     *
     * Untagged memories are left out by default; pass TagsMatch::Any to include them.
     *
     * @param  list<string>  $tags
     */
    public function tags(array $tags, TagsMatch|string $match = TagsMatch::AnyStrict): static
    {
        $this->tags = $tags;
        $this->tagsMatch = $match;

        return $this;
    }

    /**
     * Give the tool another name, e.g. when there are memory tools for two banks.
     */
    public function named(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Seconds to wait for Hindsight before the tool reports that memory is unavailable.
     */
    public function timeout(float $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function using(Hindsight $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * How long the tool waits when no timeout() was set; null keeps the client's own timeout.
     */
    abstract protected function defaultTimeout(): ?float;

    protected function client(): Hindsight
    {
        $client = $this->client ??= app(Hindsight::class);
        $timeout = $this->timeout ?? $this->defaultTimeout();

        return $timeout === null ? $client : $client->withTimeout($timeout);
    }
}
