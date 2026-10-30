<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Responses;

/**
 * A background operation such as an async retain or a mental model refresh.
 */
final readonly class Operation
{
    /**
     * @param  array<string, mixed>  $raw  The response exactly as the API returned it.
     */
    public function __construct(
        public string $id,
        public OperationStatus $status,
        public ?string $type,
        public ?string $errorMessage,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $completedAt,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Fields::string($data, 'operation_id') ?? Fields::string($data, 'id') ?? '',
            status: OperationStatus::tryFrom(Fields::string($data, 'status') ?? '') ?? OperationStatus::Unknown,
            type: Fields::string($data, 'operation_type') ?? Fields::string($data, 'task_type'),
            errorMessage: Fields::string($data, 'error_message'),
            createdAt: Fields::string($data, 'created_at'),
            updatedAt: Fields::string($data, 'updated_at'),
            completedAt: Fields::string($data, 'completed_at'),
            raw: $data,
        );
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function isCompleted(): bool
    {
        return $this->status === OperationStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === OperationStatus::Failed;
    }
}
