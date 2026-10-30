<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Responses;

use Stringable;

/**
 * One memory returned by recall.
 */
final readonly class RecallResult implements Stringable
{
    /**
     * @param  string|null  $type  "world", "experience" or "observation".
     * @param  list<string>  $tags
     * @param  list<mixed>|null  $entities
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $scores
     * @param  array<string, mixed>  $raw  The result exactly as the API returned it.
     */
    public function __construct(
        public string $id,
        public string $text,
        public ?string $type,
        public ?string $context,
        public ?string $occurredStart,
        public ?string $occurredEnd,
        public ?string $mentionedAt,
        public ?string $documentId,
        public ?string $chunkId,
        public array $tags,
        public ?array $entities,
        public ?array $metadata,
        public ?array $scores,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Fields::string($data, 'id') ?? '',
            text: Fields::string($data, 'text') ?? '',
            type: Fields::string($data, 'type'),
            context: Fields::string($data, 'context'),
            occurredStart: Fields::string($data, 'occurred_start'),
            occurredEnd: Fields::string($data, 'occurred_end'),
            mentionedAt: Fields::string($data, 'mentioned_at'),
            documentId: Fields::string($data, 'document_id'),
            chunkId: Fields::string($data, 'chunk_id'),
            tags: Fields::strings($data, 'tags'),
            entities: Fields::list($data, 'entities'),
            metadata: Fields::map($data, 'metadata'),
            scores: Fields::map($data, 'scores'),
            raw: $data,
        );
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
