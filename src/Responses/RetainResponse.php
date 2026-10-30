<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Responses;

final readonly class RetainResponse
{
    /**
     * @param  list<string>  $operationIds  Set when an async batch was split into several strategy groups.
     * @param  array<string, int>|null  $usage  LLM token usage, reported for synchronous retains only.
     * @param  array<string, mixed>  $raw  The response exactly as the API returned it.
     */
    public function __construct(
        public bool $success,
        public string $bankId,
        public int $itemsCount,
        public bool $async,
        public ?string $operationId,
        public array $operationIds,
        public ?array $usage,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: Fields::bool($data, 'success') ?? false,
            bankId: Fields::string($data, 'bank_id') ?? '',
            itemsCount: Fields::int($data, 'items_count') ?? 0,
            async: Fields::bool($data, 'async') ?? false,
            operationId: Fields::string($data, 'operation_id'),
            operationIds: Fields::strings($data, 'operation_ids'),
            usage: Fields::counts($data, 'usage'),
            raw: $data,
        );
    }
}
