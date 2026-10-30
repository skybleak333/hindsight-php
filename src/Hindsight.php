<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight;

use DateTimeInterface;
use Fedoseev\Hindsight\Exceptions\OperationFailedException;
use Fedoseev\Hindsight\Exceptions\TimeoutException;
use Fedoseev\Hindsight\Responses\Operation;
use Fedoseev\Hindsight\Responses\OperationStatus;
use Fedoseev\Hindsight\Responses\RecallResponse;
use Fedoseev\Hindsight\Responses\ReflectResponse;
use Fedoseev\Hindsight\Responses\RetainResponse;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use stdClass;

/**
 * Client for the Hindsight HTTP API.
 *
 * Method names and arguments follow the official Python and TypeScript clients. Endpoints without a
 * dedicated method are reachable through request(). Methods that return arrays return the decoded
 * JSON with the API's own snake_case keys.
 *
 * @see https://hindsight.vectorize.io/api-reference
 */
final class Hindsight
{
    public const VERSION = '0.1.0';

    private readonly Transport $transport;

    /**
     * @param  string  $baseUrl  Where the API listens, e.g. http://localhost:8888 or https://api.hindsight.vectorize.io.
     * @param  string|null  $apiKey  Sent as a Bearer token. Not needed for a local server without auth.
     * @param  ClientInterface|null  $httpClient  Any PSR-18 client. Defaults to Guzzle with the given timeout.
     * @param  float  $timeout  Seconds to wait for a response when the default client is used. Synchronous retain and
     *                          reflect call an LLM on the server, so they take longer than a plain HTTP request.
     * @param  array<string, string>  $headers  Extra headers for every request.
     */
    public function __construct(
        string $baseUrl = 'http://localhost:8888',
        ?string $apiKey = null,
        ?ClientInterface $httpClient = null,
        float $timeout = 120.0,
        array $headers = [],
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $factory = new HttpFactory;

        $this->transport = new Transport(
            $baseUrl,
            $apiKey,
            $httpClient ?? new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => min(10.0, $timeout)]),
            $requestFactory ?? $factory,
            $streamFactory ?? $factory,
            $headers,
        );
    }

    // Monitoring

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->transport->json('GET', '/health');
    }

    /**
     * The server version and the features it has enabled.
     *
     * @return array<string, mixed>
     */
    public function version(): array
    {
        return $this->transport->json('GET', '/version');
    }

    // Memories

    /**
     * Store one memory. The server extracts facts from it with its LLM unless the bank or server runs without one.
     *
     * @param  string|list<array<string, mixed>>  $content  Text, or content blocks so images sit where they appear.
     * @param  DateTimeInterface|string|null  $timestamp  When it happened. Null means now; "unset" stores no time.
     * @param  array<string, string>|null  $metadata
     * @param  list<array{text: string, type?: string|null}>|null  $entities  Added to the entities the server extracts.
     * @param  list<string>|null  $tags  Used to scope recall and reflect, e.g. ["user:42"].
     * @param  string|null  $updateMode  For an existing document_id: "replace" (default) or "append".
     * @param  bool  $async  Return at once and process in the background; poll with waitForOperation().
     * @param  string|null  $operationId  Caller-chosen UUID that makes an async retry idempotent. Ignored when sync.
     * @param  array<string, mixed>  $options  Other item fields, e.g. ["observation_scopes" => "per_tag"].
     */
    public function retain(
        string $bankId,
        string|array $content,
        DateTimeInterface|string|null $timestamp = null,
        ?string $context = null,
        ?string $documentId = null,
        ?array $metadata = null,
        ?array $entities = null,
        ?array $tags = null,
        ?string $updateMode = null,
        bool $async = false,
        ?string $operationId = null,
        ?string $strategy = null,
        array $options = [],
    ): RetainResponse {
        $item = [
            'content' => $content,
            'timestamp' => $timestamp,
            'context' => $context,
            'metadata' => $metadata,
            'document_id' => $documentId,
            'entities' => $entities,
            'tags' => $tags,
            'strategy' => $strategy,
            'update_mode' => $updateMode,
        ];

        return $this->retainBatch($bankId, [[...array_filter($item, static fn (mixed $value): bool => $value !== null), ...$options]], $async, $operationId);
    }

    /**
     * Store several memories in one request.
     *
     * @param  list<array<string, mixed>>  $items  Each with "content" and any MemoryItem field. Keys may be
     *                                             snake_case as in the API or camelCase, e.g. "documentId".
     */
    public function retainBatch(string $bankId, array $items, bool $async = false, ?string $operationId = null): RetainResponse
    {
        $body = [
            'items' => array_map(self::memoryItem(...), array_values($items)),
            'async' => $async,
        ];

        // The API reads operation_id for async retains only, so a sync call never sends it.
        if ($async && $operationId !== null) {
            $body['operation_id'] = $operationId;
        }

        return RetainResponse::fromArray($this->transport->json('POST', Transport::path('/v1/default/banks/%s/memories', $bankId), $body));
    }

    /**
     * Find the memories most relevant to a query.
     *
     * @param  list<string>|null  $types  Any of "world", "experience" and "observation". Null means all.
     * @param  int  $maxTokens  Upper bound for the size of the returned memories.
     * @param  list<string>|null  $tags  Only memories with these tags, matched as $tagsMatch says.
     * @param  array<string, mixed>  $options  Other request fields, e.g. ["include" => ["chunks" => ["max_tokens" => 2000]]].
     */
    public function recall(
        string $bankId,
        string $query,
        ?array $types = null,
        Budget|string $budget = Budget::Mid,
        int $maxTokens = 4096,
        ?array $tags = null,
        TagsMatch|string $tagsMatch = TagsMatch::Any,
        DateTimeInterface|string|null $queryTimestamp = null,
        bool $trace = false,
        array $options = [],
    ): RecallResponse {
        $body = [
            'query' => $query,
            'budget' => Transport::value($budget),
            'max_tokens' => $maxTokens,
            'types' => $types,
            'tags' => $tags,
            'tags_match' => self::tagsMatch($tagsMatch),
            'query_timestamp' => Transport::value($queryTimestamp),
            'trace' => $trace ?: null,
        ];

        return RecallResponse::fromArray($this->transport->json(
            'POST',
            Transport::path('/v1/default/banks/%s/memories/recall', $bankId),
            [...self::withoutNulls($body), ...$options],
        ));
    }

    /**
     * Answer a question by reasoning over the bank's memories, mental models and directives with the server's LLM.
     *
     * @param  array<string, mixed>|null  $responseSchema  JSON Schema for a structured answer in $structuredOutput.
     * @param  list<string>|null  $tags
     * @param  bool  $includeFacts  Return the memories the answer is based on in $basedOn.
     * @param  array<string, mixed>  $options  Other request fields, e.g. ["fact_types" => ["world"]].
     */
    public function reflect(
        string $bankId,
        string $query,
        Budget|string $budget = Budget::Low,
        ?int $maxTokens = null,
        ?array $responseSchema = null,
        ?array $tags = null,
        TagsMatch|string $tagsMatch = TagsMatch::Any,
        bool $includeFacts = false,
        array $options = [],
    ): ReflectResponse {
        $body = [
            'query' => $query,
            'budget' => Transport::value($budget),
            'max_tokens' => $maxTokens,
            'response_schema' => $responseSchema,
            'tags' => $tags,
            'tags_match' => self::tagsMatch($tagsMatch),
            'include' => $includeFacts ? ['facts' => new stdClass] : null,
        ];

        return ReflectResponse::fromArray($this->transport->json(
            'POST',
            Transport::path('/v1/default/banks/%s/reflect', $bankId),
            [...self::withoutNulls($body), ...$options],
        ));
    }

    /**
     * Page through stored memories.
     *
     * @param  list<string>|null  $tags
     * @param  array<string, mixed>  $query  Other query parameters, e.g. ["document_id" => "doc-1"].
     * @return array<string, mixed>
     */
    public function listMemories(
        string $bankId,
        ?string $type = null,
        ?string $search = null,
        ?array $tags = null,
        ?int $limit = null,
        ?int $offset = null,
        array $query = [],
    ): array {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/memories/list', $bankId), query: [
            'type' => $type, 'q' => $search, 'tags' => $tags, 'limit' => $limit, 'offset' => $offset, ...$query,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMemory(string $bankId, string $memoryId): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/memories/%s', $bankId, $memoryId));
    }

    /**
     * Delete every memory in the bank, or only those of one type. The bank itself stays.
     *
     * @return array<string, mixed>
     */
    public function clearMemories(string $bankId, ?string $type = null): array
    {
        return $this->transport->json('DELETE', Transport::path('/v1/default/banks/%s/memories', $bankId), query: ['type' => $type]);
    }

    // Banks

    /**
     * @return array<string, mixed>
     */
    public function listBanks(?string $search = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->transport->json('GET', '/v1/default/banks', query: ['q' => $search, 'limit' => $limit, 'offset' => $offset]);
    }

    /**
     * Create a bank, or update it if it exists. Banks are also created on first use with default settings.
     *
     * @param  array<string, mixed>  $options  Other fields, e.g. ["disposition_empathy" => 4, "enable_observations" => false].
     * @return array<string, mixed>
     */
    public function createBank(string $bankId, ?string $name = null, ?string $mission = null, array $options = []): array
    {
        return $this->transport->json('PUT', Transport::path('/v1/default/banks/%s', $bankId), [
            ...self::withoutNulls(['name' => $name, 'mission' => $mission]),
            ...$options,
        ]);
    }

    /**
     * Change only the given fields of a bank.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function updateBank(string $bankId, ?string $name = null, ?string $mission = null, array $options = []): array
    {
        return $this->transport->json('PATCH', Transport::path('/v1/default/banks/%s', $bankId), [
            ...self::withoutNulls(['name' => $name, 'mission' => $mission]),
            ...$options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getBankProfile(string $bankId): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/profile', $bankId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getBankStats(string $bankId): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/stats', $bankId));
    }

    /**
     * Delete the bank with all its memories, documents, mental models and directives.
     *
     * @return array<string, mixed>
     */
    public function deleteBank(string $bankId): array
    {
        return $this->transport->json('DELETE', Transport::path('/v1/default/banks/%s', $bankId));
    }

    /**
     * The bank's effective configuration and the fields it overrides.
     *
     * @return array<string, mixed>
     */
    public function getBankConfig(string $bankId): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/config', $bankId));
    }

    /**
     * @param  array<string, mixed>  $updates  Configuration fields, e.g. ["retain_chunk_size" => 2000].
     * @return array<string, mixed>
     */
    public function updateBankConfig(string $bankId, array $updates): array
    {
        return $this->transport->json('PATCH', Transport::path('/v1/default/banks/%s/config', $bankId), ['updates' => (object) $updates]);
    }

    /**
     * Remove the bank's overrides so it uses the server defaults again.
     *
     * @return array<string, mixed>
     */
    public function resetBankConfig(string $bankId): array
    {
        return $this->transport->json('DELETE', Transport::path('/v1/default/banks/%s/config', $bankId));
    }

    // Documents

    /**
     * @param  list<string>|null  $tags
     * @return array<string, mixed>
     */
    public function listDocuments(string $bankId, ?string $search = null, ?array $tags = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/documents', $bankId), query: [
            'q' => $search, 'tags' => $tags, 'limit' => $limit, 'offset' => $offset,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(string $bankId, string $documentId): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/documents/%s', $bankId, $documentId));
    }

    /**
     * Delete a document and the memories extracted from it.
     *
     * @return array<string, mixed>
     */
    public function deleteDocument(string $bankId, string $documentId): array
    {
        return $this->transport->json('DELETE', Transport::path('/v1/default/banks/%s/documents/%s', $bankId, $documentId));
    }

    // Directives

    /**
     * Add a rule that reflect always follows for this bank.
     *
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public function createDirective(string $bankId, string $name, string $content, int $priority = 0, bool $isActive = true, array $tags = []): array
    {
        return $this->transport->json('POST', Transport::path('/v1/default/banks/%s/directives', $bankId), [
            'name' => $name, 'content' => $content, 'priority' => $priority, 'is_active' => $isActive, 'tags' => $tags,
        ]);
    }

    /**
     * @param  list<string>|null  $tags
     * @return array<string, mixed>
     */
    public function listDirectives(string $bankId, ?array $tags = null, ?bool $activeOnly = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/directives', $bankId), query: [
            'tags' => $tags, 'active_only' => $activeOnly, 'limit' => $limit, 'offset' => $offset,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDirective(string $bankId, string $directiveId): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/directives/%s', $bankId, $directiveId));
    }

    /**
     * @param  array<string, mixed>  $changes  Any of name, content, priority, is_active and tags.
     * @return array<string, mixed>
     */
    public function updateDirective(string $bankId, string $directiveId, array $changes): array
    {
        return $this->transport->json('PATCH', Transport::path('/v1/default/banks/%s/directives/%s', $bankId, $directiveId), $changes);
    }

    public function deleteDirective(string $bankId, string $directiveId): mixed
    {
        return $this->transport->send('DELETE', Transport::path('/v1/default/banks/%s/directives/%s', $bankId, $directiveId));
    }

    // Mental models

    /**
     * Create a summary that the server keeps up to date as new memories arrive. Built in the background.
     *
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $options  Other fields, e.g. ["trigger" => [...]].
     * @return array<string, mixed>  Contains mental_model_id and the operation_id of the first build.
     */
    public function createMentalModel(
        string $bankId,
        string $name,
        string $sourceQuery,
        ?string $id = null,
        array $tags = [],
        ?int $maxTokens = null,
        array $options = [],
    ): array {
        return $this->transport->json('POST', Transport::path('/v1/default/banks/%s/mental-models', $bankId), [
            ...self::withoutNulls([
                'name' => $name,
                'source_query' => $sourceQuery,
                'id' => $id,
                'tags' => $tags === [] ? null : $tags,
                'max_tokens' => $maxTokens,
            ]),
            ...$options,
        ]);
    }

    /**
     * @param  list<string>|null  $tags
     * @param  string|null  $detail  "metadata", "content" or "full".
     * @return array<string, mixed>
     */
    public function listMentalModels(string $bankId, ?array $tags = null, ?string $detail = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/mental-models', $bankId), query: [
            'tags' => $tags, 'detail' => $detail, 'limit' => $limit, 'offset' => $offset,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMentalModel(string $bankId, string $mentalModelId, ?string $detail = null): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/mental-models/%s', $bankId, $mentalModelId), query: ['detail' => $detail]);
    }

    /**
     * @param  array<string, mixed>  $changes  Any of name, source_query, max_tokens, tags and trigger.
     * @return array<string, mixed>
     */
    public function updateMentalModel(string $bankId, string $mentalModelId, array $changes): array
    {
        return $this->transport->json('PATCH', Transport::path('/v1/default/banks/%s/mental-models/%s', $bankId, $mentalModelId), $changes);
    }

    public function deleteMentalModel(string $bankId, string $mentalModelId): mixed
    {
        return $this->transport->send('DELETE', Transport::path('/v1/default/banks/%s/mental-models/%s', $bankId, $mentalModelId));
    }

    /**
     * Rebuild a mental model in the background.
     *
     * @return array<string, mixed>  Contains the operation_id to wait for.
     */
    public function refreshMentalModel(string $bankId, string $mentalModelId): array
    {
        return $this->transport->json('POST', Transport::path('/v1/default/banks/%s/mental-models/%s/refresh', $bankId, $mentalModelId));
    }

    // Operations

    public function getOperation(string $bankId, string $operationId): Operation
    {
        return Operation::fromArray($this->transport->json('GET', Transport::path('/v1/default/banks/%s/operations/%s', $bankId, $operationId)));
    }

    /**
     * @param  string|null  $status  e.g. "pending", "processing", "completed" or "failed".
     * @return array<string, mixed>
     */
    public function listOperations(string $bankId, ?string $status = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->transport->json('GET', Transport::path('/v1/default/banks/%s/operations', $bankId), query: [
            'status' => $status, 'limit' => $limit, 'offset' => $offset,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelOperation(string $bankId, string $operationId): array
    {
        return $this->transport->json('DELETE', Transport::path('/v1/default/banks/%s/operations/%s', $bankId, $operationId));
    }

    /**
     * Poll an operation until it completes.
     *
     * @throws OperationFailedException when it fails, is cancelled or is unknown to the server.
     * @throws TimeoutException when it is still running after $timeout seconds.
     */
    public function waitForOperation(string $bankId, string $operationId, float $timeout = 60.0, float $interval = 0.5): Operation
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $operation = $this->getOperation($bankId, $operationId);

            if ($operation->status === OperationStatus::Completed) {
                return $operation;
            }

            if ($operation->isFinished()) {
                throw new OperationFailedException($operation);
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new TimeoutException($operation, $timeout);
            }

            usleep((int) (min($interval, $remaining) * 1_000_000));
        }
    }

    // Anything else

    /**
     * Call any endpoint, e.g. request('GET', '/v1/default/banks/my-bank/tags').
     *
     * @param  array<array-key, mixed>|object|null  $body
     * @param  array<string, mixed>  $query
     * @return mixed  The decoded JSON, the raw text for a non-JSON body, or null for an empty one.
     */
    public function request(string $method, string $path, array|object|null $body = null, array $query = []): mixed
    {
        return $this->transport->send(strtoupper($method), '/'.ltrim($path, '/'), $body, $query);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function memoryItem(array $item): array
    {
        $normalized = [];

        foreach ($item as $key => $value) {
            $key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key));

            $normalized[$key] = match ($key) {
                'timestamp' => Transport::value($value),
                'metadata' => is_array($value) ? (object) $value : $value,
                default => $value,
            };
        }

        return $normalized;
    }

    private static function tagsMatch(TagsMatch|string $tagsMatch): ?string
    {
        $value = $tagsMatch instanceof TagsMatch ? $tagsMatch->value : $tagsMatch;

        // Leave the API default implicit so the request stays as small as the caller's intent.
        return $value === TagsMatch::Any->value ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }
}
