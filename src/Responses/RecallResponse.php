<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Responses;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, RecallResult>
 */
final readonly class RecallResponse implements Countable, IteratorAggregate
{
    /**
     * @param  list<RecallResult>  $results  Most relevant first.
     * @param  array<string, mixed>|null  $entities  Entity states for entities mentioned in the results.
     * @param  array<string, mixed>|null  $chunks  Source chunks keyed by chunk ID, when requested.
     * @param  array<string, mixed>|null  $sourceFacts  Source facts of observation results, when requested.
     * @param  array<string, mixed>|null  $trace  Retrieval trace, when requested.
     * @param  array<string, mixed>  $raw  The response exactly as the API returned it.
     */
    public function __construct(
        public array $results,
        public ?array $entities,
        public ?array $chunks,
        public ?array $sourceFacts,
        public ?array $trace,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            results: array_map(RecallResult::fromArray(...), Fields::objects($data, 'results')),
            entities: Fields::map($data, 'entities'),
            chunks: Fields::map($data, 'chunks'),
            sourceFacts: Fields::map($data, 'source_facts'),
            trace: Fields::map($data, 'trace'),
            raw: $data,
        );
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_map(static fn (RecallResult $result): string => $result->text, $this->results);
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    /**
     * The memories as a bulleted list for a prompt, each with the date it happened when known.
     */
    public function toPrompt(): string
    {
        return implode("\n", array_map(static function (RecallResult $result): string {
            $date = $result->occurredStart !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $result->occurredStart, $match) === 1
                ? " ({$match[0]})"
                : '';

            return "- {$result->text}{$date}";
        }, $this->results));
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * @return Traversable<int, RecallResult>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }
}
