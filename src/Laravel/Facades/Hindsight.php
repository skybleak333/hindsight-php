<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Fedoseev\Hindsight\Hindsight withTimeout(float $seconds)
 * @method static array<string, mixed> health()
 * @method static array<string, mixed> version()
 * @method static \Fedoseev\Hindsight\Responses\RetainResponse retain(string $bankId, string|list<array<string, mixed>> $content, \DateTimeInterface|string|null $timestamp = null, ?string $context = null, ?string $documentId = null, array<string, string>|null $metadata = null, list<array{text: string, type?: string|null}>|null $entities = null, list<string>|null $tags = null, string|null $updateMode = null, bool $async = false, string|null $operationId = null, ?string $strategy = null, array<string, mixed> $options = [])
 * @method static \Fedoseev\Hindsight\Responses\RetainResponse retainBatch(string $bankId, list<array<string, mixed>> $items, bool $async = false, ?string $operationId = null)
 * @method static \Fedoseev\Hindsight\Responses\RecallResponse recall(string $bankId, string $query, list<string>|null $types = null, \Fedoseev\Hindsight\Budget|string $budget = \Fedoseev\Hindsight\Budget::Mid, int $maxTokens = 4096, list<string>|null $tags = null, \Fedoseev\Hindsight\TagsMatch|string $tagsMatch = \Fedoseev\Hindsight\TagsMatch::Any, \DateTimeInterface|string|null $queryTimestamp = null, bool $trace = false, array<string, mixed> $options = [])
 * @method static \Fedoseev\Hindsight\Responses\ReflectResponse reflect(string $bankId, string $query, \Fedoseev\Hindsight\Budget|string $budget = \Fedoseev\Hindsight\Budget::Low, ?int $maxTokens = null, array<string, mixed>|null $responseSchema = null, list<string>|null $tags = null, \Fedoseev\Hindsight\TagsMatch|string $tagsMatch = \Fedoseev\Hindsight\TagsMatch::Any, bool $includeFacts = false, array<string, mixed> $options = [])
 * @method static array<string, mixed> listMemories(string $bankId, ?string $type = null, ?string $search = null, list<string>|null $tags = null, ?int $limit = null, ?int $offset = null, array<string, mixed> $query = [])
 * @method static array<string, mixed> getMemory(string $bankId, string $memoryId)
 * @method static array<string, mixed> clearMemories(string $bankId, ?string $type = null)
 * @method static array<string, mixed> listBanks(?string $search = null, ?int $limit = null, ?int $offset = null)
 * @method static array<string, mixed> createBank(string $bankId, string|null $name = null, string|null $reflectMission = null, array<string, mixed> $options = [])
 * @method static array<string, mixed> updateBank(string $bankId, ?string $name = null, ?string $reflectMission = null, array<string, mixed> $options = [])
 * @method static array<string, mixed> getBankStats(string $bankId)
 * @method static array<string, mixed> deleteBank(string $bankId)
 * @method static array<string, mixed> getBankConfig(string $bankId)
 * @method static array<string, mixed> updateBankConfig(string $bankId, array<string, mixed> $updates)
 * @method static array<string, mixed> resetBankConfig(string $bankId)
 * @method static array<string, mixed> listDocuments(string $bankId, ?string $search = null, list<string>|null $tags = null, ?int $limit = null, ?int $offset = null)
 * @method static array<string, mixed> getDocument(string $bankId, string $documentId)
 * @method static array<string, mixed> deleteDocument(string $bankId, string $documentId)
 * @method static array<string, mixed> createDirective(string $bankId, string $name, string $content, int $priority = 0, bool $isActive = true, list<string> $tags = [])
 * @method static array<string, mixed> listDirectives(string $bankId, list<string>|null $tags = null, ?bool $activeOnly = null, ?int $limit = null, ?int $offset = null)
 * @method static array<string, mixed> getDirective(string $bankId, string $directiveId)
 * @method static array<string, mixed> updateDirective(string $bankId, string $directiveId, array<string, mixed> $changes)
 * @method static mixed deleteDirective(string $bankId, string $directiveId)
 * @method static array<string, mixed> createMentalModel(string $bankId, string $name, string $sourceQuery, ?string $id = null, list<string> $tags = [], ?int $maxTokens = null, array<string, mixed> $options = [])
 * @method static array<string, mixed> listMentalModels(string $bankId, list<string>|null $tags = null, string|null $detail = null, ?int $limit = null, ?int $offset = null)
 * @method static array<string, mixed> getMentalModel(string $bankId, string $mentalModelId, ?string $detail = null)
 * @method static array<string, mixed> updateMentalModel(string $bankId, string $mentalModelId, array<string, mixed> $changes)
 * @method static mixed deleteMentalModel(string $bankId, string $mentalModelId)
 * @method static array<string, mixed> refreshMentalModel(string $bankId, string $mentalModelId)
 * @method static \Fedoseev\Hindsight\Responses\Operation getOperation(string $bankId, string $operationId)
 * @method static array<string, mixed> listOperations(string $bankId, string|null $status = null, ?int $limit = null, ?int $offset = null)
 * @method static array<string, mixed> cancelOperation(string $bankId, string $operationId)
 * @method static \Fedoseev\Hindsight\Responses\Operation waitForOperation(string $bankId, string $operationId, float $timeout = 60.0, float $interval = 0.5)
 * @method static mixed request(string $method, string $path, array<array-key, mixed>|object|null $body = null, array<string, mixed> $query = [])
 *
 * @see \Fedoseev\Hindsight\Hindsight
 */
final class Hindsight extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Fedoseev\Hindsight\Hindsight::class;
    }
}
