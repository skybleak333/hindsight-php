<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Testing;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An in-memory stand-in for the Hindsight API, behind HindsightFake.
 *
 * It answers retain, recall, reflect, operation status, health and version the way Hindsight 1.1 and 1.2 do,
 * with three simplifications: a retained item is stored as one memory with its text unchanged (no fact
 * extraction), recall returns every memory of the bank that passes the type and tag filters (no ranking, no
 * token budget), and async work is finished at once.
 *
 * @internal
 */
final class FakeServer implements ClientInterface
{
    private const BANK_PATH = '#^/v1/default/banks/([^/]+)/(memories/recall|memories|reflect|operations/([^/]+))$#';

    /** @var array<string, list<array<string, mixed>>> Memories by bank, as recall returns them. */
    private array $memories = [];

    /** @var array<string, array{bank: string, response: array<string, mixed>}> Async retains by operation id. */
    private array $operations = [];

    /** @var list<array{endpoint: string, bank: string|null, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<array{bank: string, item: array<string, mixed>}> */
    private array $retained = [];

    /** @var list<array{method: string, pattern: string, status: int, response: array<array-key, mixed>|Closure}> */
    private array $stubs = [];

    private Closure|string $reflection = '';

    private int $nextId = 1;

    /** How many more requests fail: null for none, PHP_INT_MAX until available() is called. */
    private ?int $failures = null;

    /** The status failing requests answer with, or null for a refused connection. */
    private ?int $failureStatus = null;

    /**
     * @param  list<string|array<string, mixed>>  $memories
     */
    public function seed(string $bankId, array $memories): void
    {
        $this->memories[$bankId] ??= [];

        foreach ($memories as $memory) {
            $memory = is_string($memory) ? ['text' => $memory] : $memory;

            if (! is_string($memory['text'] ?? null)) {
                throw new LogicException("A fake memory needs a 'text' string, got: ".json_encode($memory));
            }

            $this->memories[$bankId][] = ['id' => $this->id()] + $memory + ['type' => 'world'];
        }
    }

    public function unavailable(?int $times, ?int $status): void
    {
        $this->failures = $times ?? PHP_INT_MAX;
        $this->failureStatus = $status;
    }

    public function available(): void
    {
        $this->failures = null;
        $this->failureStatus = null;
    }

    public function reflectUsing(Closure|string $answer): void
    {
        $this->reflection = $answer;
    }

    /**
     * @param  array<array-key, mixed>|Closure  $response
     */
    public function respondTo(string $method, string $pattern, array|Closure $response, int $status): void
    {
        array_unshift($this->stubs, ['method' => strtoupper($method), 'pattern' => '/'.ltrim($pattern, '/'), 'status' => $status, 'response' => $response]);
    }

    /**
     * @return list<array{endpoint: string, bank: string|null, body: array<string, mixed>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<array{bank: string, item: array<string, mixed>}>
     */
    public function retained(): array
    {
        return $this->retained;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // A request that fails never reaches the fake server, so it is not recorded.
        if ($this->failures !== null && $this->failures > 0) {
            if ($this->failures !== PHP_INT_MAX) {
                $this->failures--;
            }

            if ($this->failureStatus === null) {
                throw new ConnectException('Connection refused (HindsightFake is unavailable)', $request);
            }

            return self::json(['detail' => 'HindsightFake is unavailable'], $this->failureStatus);
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        foreach ($this->stubs as $stub) {
            if ($stub['method'] === $method && self::matches($stub['pattern'], $path)) {
                $this->record('stub', null, $body);
                $response = $stub['response'] instanceof Closure ? ($stub['response'])($body, $request) : $stub['response'];

                return self::json(is_array($response) ? $response : [], $stub['status']);
            }
        }

        if ($method === 'GET' && $path === '/health') {
            $this->record('health', null, $body);

            return self::json(['status' => 'healthy', 'database' => 'connected']);
        }

        if ($method === 'GET' && $path === '/version') {
            $this->record('version', null, $body);

            return self::json(['api_version' => 'fake', 'features' => self::features()]);
        }

        if (preg_match(self::BANK_PATH, $path, $match) === 1) {
            $bankId = rawurldecode($match[1]);

            $response = match (true) {
                $method === 'POST' && $match[2] === 'memories' => $this->retain($bankId, $body),
                $method === 'POST' && $match[2] === 'memories/recall' => $this->recall($bankId, $body),
                $method === 'POST' && $match[2] === 'reflect' => $this->reflect($bankId, $body),
                $method === 'GET' && isset($match[3]) => $this->operation($bankId, rawurldecode($match[3])),
                default => null,
            };

            if ($response !== null) {
                return $response;
            }
        }

        throw new LogicException(
            "HindsightFake does not simulate {$method} {$path}. It answers retain, recall, reflect, operation status, "
            .'health and version. Stub other endpoints with respondTo().',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function retain(string $bankId, array $body): ResponseInterface
    {
        $async = ($body['async'] ?? false) === true;
        $items = is_array($body['items'] ?? null) ? array_values($body['items']) : [];
        $operationId = $async && is_string($body['operation_id'] ?? null) ? $body['operation_id'] : null;

        $this->record('retain', $bankId, $body);

        // Requests the server rejects before doing anything: 422 from its schema, 400 from its own checks.
        $invalid = self::invalidRetain($body, $items);

        if ($invalid !== null) {
            return $invalid;
        }

        // Like the server: a retry with the operation_id of an earlier async retain returns that operation and
        // stores nothing, and an id that belongs to another bank is a conflict.
        if ($operationId !== null && isset($this->operations[$operationId])) {
            $operation = $this->operations[$operationId];

            return $operation['bank'] === $bankId
                ? self::json($operation['response'])
                : self::json(['detail' => "Operation {$operationId} belongs to another bank"], 409);
        }

        $this->memories[$bankId] ??= [];

        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $documentId = is_string($item['document_id'] ?? null) ? $item['document_id'] : null;

            if ($documentId !== null && ($item['update_mode'] ?? 'replace') === 'replace') {
                $this->memories[$bankId] = array_values(array_filter(
                    $this->memories[$bankId],
                    static fn (array $memory): bool => ($memory['document_id'] ?? null) !== $documentId,
                ));
            }

            $this->retained[] = ['bank' => $bankId, 'item' => $item];
            $this->memories[$bankId][] = array_filter([
                'id' => $this->id(),
                'text' => self::text($item['content'] ?? ''),
                'type' => 'world',
                'context' => $item['context'] ?? null,
                'document_id' => $documentId,
                'tags' => $item['tags'] ?? null,
                // An empty map would encode as [] instead of {}.
                'metadata' => is_array($item['metadata'] ?? null) && $item['metadata'] !== [] ? (object) $item['metadata'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $response = ['success' => true, 'bank_id' => $bankId, 'items_count' => count($items), 'async' => $async];

        if ($async) {
            $operationId ??= self::uuid();
            $response['operation_id'] = $operationId;
            $this->operations[$operationId] = ['bank' => $bankId, 'response' => $response];
        }

        return self::json($response);
    }

    /**
     * Some checks of the server's request model (MemoryItem, RetainRequest) and of _validate_retain_update_modes,
     * Hindsight 1.2.0: operation_id, content, timestamp, tags, update_mode and metadata. The 422 body has
     * FastAPI's shape. Other fields are not checked.
     *
     * @param  array<string, mixed>  $body
     * @param  list<mixed>  $items
     */
    private static function invalidRetain(array $body, array $items): ?ResponseInterface
    {
        $errors = [];
        $operationId = $body['operation_id'] ?? null;

        if ($operationId !== null && (! is_string($operationId) || preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $operationId) !== 1)) {
            $errors[] = self::error(['body', 'operation_id'], 'value_error', 'Value error, operation_id must be a valid UUID', $operationId);
        }

        foreach ($items as $index => $item) {
            $item = is_array($item) ? $item : [];

            if (! array_key_exists('content', $item)) {
                $errors[] = self::error(['body', 'items', $index, 'content'], 'missing', 'Field required', $item);
            }

            $mode = $item['update_mode'] ?? null;

            if ($mode !== null && ! in_array($mode, ['replace', 'append'], true)) {
                $errors[] = self::error(['body', 'items', $index, 'update_mode'], 'literal_error', "Input should be 'replace' or 'append'", $mode);
            }

            $timestamp = $item['timestamp'] ?? null;

            if ($timestamp !== null && $timestamp !== '' && ! self::validTimestamp($timestamp)) {
                $errors[] = self::error(['body', 'items', $index, 'timestamp'], 'value_error', 'Value error, Invalid timestamp/event_date format', $timestamp);
            }

            foreach (is_array($item['tags'] ?? null) ? array_values($item['tags']) : [] as $position => $tag) {
                if (! is_string($tag)) {
                    $errors[] = self::error(['body', 'items', $index, 'tags', $position], 'string_type', 'Input should be a valid string', $tag);
                }
            }

            foreach (is_array($item['metadata'] ?? null) ? $item['metadata'] : [] as $key => $value) {
                if (! is_string($value)) {
                    $errors[] = self::error(['body', 'items', $index, 'metadata', $key], 'string_type', 'Input should be a valid string', $value);
                }
            }
        }

        if ($errors !== []) {
            return self::json(['detail' => $errors], 422);
        }

        foreach ($items as $item) {
            if (is_array($item) && ($item['update_mode'] ?? null) === 'append' && ! is_string($item['document_id'] ?? null)) {
                return self::json(['detail' => "update_mode='append' requires a document_id"], 400);
            }
        }

        return null;
    }

    /**
     * What MemoryItem.validate_timestamp accepts, for the ISO 8601 forms clients send: a date, or a date and time
     * with optional seconds, fraction and offset ("Z" or ±hh:mm), or "unset". Years 1 and 9999 are placeholders
     * and rejected. Python's fromisoformat() takes a few rarer forms too; the fake does not.
     */
    private static function validTimestamp(mixed $timestamp): bool
    {
        if (! is_string($timestamp)) {
            return false;
        }

        if (strtolower($timestamp) === 'unset') {
            return true;
        }

        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2})(?::(\d{2})(?::(\d{2})(?:[.,]\d{1,6})?)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?)?$/';

        if (preg_match($pattern, $timestamp, $parts) !== 1) {
            return false;
        }

        [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];

        return checkdate($month, $day, $year)
            && ! in_array($year, [1, 9999], true)
            && (int) ($parts[4] ?? 0) < 24 && (int) ($parts[5] ?? 0) < 60 && (int) ($parts[6] ?? 0) < 60;
    }

    /**
     * @param  list<int|string>  $location
     * @return array<string, mixed>
     */
    private static function error(array $location, string $type, string $message, mixed $input): array
    {
        return ['type' => $type, 'loc' => $location, 'msg' => $message, 'input' => $input];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function recall(string $bankId, array $body): ResponseInterface
    {
        $this->record('recall', $bankId, $body);

        if (! isset($this->memories[$bankId])) {
            return self::json(['detail' => "Bank '{$bankId}' not found"], 404);
        }

        $types = is_array($body['types'] ?? null) ? $body['types'] : [];
        $tags = self::strings($body['tags'] ?? null);
        $match = is_string($body['tags_match'] ?? null) ? $body['tags_match'] : 'any';

        $results = array_values(array_filter(
            $this->memories[$bankId],
            static fn (array $memory): bool => ($types === [] || in_array($memory['type'] ?? null, $types, true))
                && self::tagsMatch(self::strings($memory['tags'] ?? null), $tags, $match),
        ));

        return self::json(['results' => $results]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reflect(string $bankId, array $body): ResponseInterface
    {
        $this->record('reflect', $bankId, $body);

        $query = is_string($body['query'] ?? null) ? $body['query'] : '';
        $text = $this->reflection instanceof Closure ? ($this->reflection)($query, $bankId, $body) : $this->reflection;

        return self::json(['text' => is_string($text) ? $text : '']);
    }

    private function operation(string $bankId, string $operationId): ResponseInterface
    {
        $this->record('operation', $bankId, []);

        $known = ($this->operations[$operationId]['bank'] ?? null) === $bankId;

        return self::json([
            'operation_id' => $operationId,
            'id' => $operationId,
            'status' => $known ? 'completed' : 'not_found',
            'operation_type' => $known ? 'batch_retain' : null,
        ]);
    }

    /**
     * The server's rules: no tags means no filter, except "exact", where it means untagged memories only. The
     * "any" and "all" modes also let untagged memories through; the "_strict" modes and "exact" do not.
     *
     * @param  list<string>  $memoryTags
     * @param  list<string>  $tags
     */
    private static function tagsMatch(array $memoryTags, array $tags, string $match): bool
    {
        if ($match === 'exact') {
            // Set equality, as Postgres compares the arrays: order and repeats do not matter.
            $memoryTags = array_values(array_unique($memoryTags));
            $tags = array_values(array_unique($tags));
            sort($memoryTags);
            sort($tags);

            return $memoryTags === $tags;
        }

        if ($tags === []) {
            return true;
        }

        if ($memoryTags === []) {
            return in_array($match, ['any', 'all'], true);
        }

        return match ($match) {
            'any', 'any_strict' => array_intersect($tags, $memoryTags) !== [],
            'all', 'all_strict' => array_diff($tags, $memoryTags) === [],
            default => throw new LogicException("Unknown tags_match '{$match}'."),
        };
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    private static function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        // Content blocks: keep the text blocks, in order.
        $texts = [];

        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return implode("\n", $texts);
    }

    private static function matches(string $pattern, string $path): bool
    {
        $regex = '#^'.str_replace('\*', '[^/]+', preg_quote($pattern, '#')).'$#';

        return preg_match($regex, $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function record(string $endpoint, ?string $bankId, array $body): void
    {
        $this->requests[] = ['endpoint' => $endpoint, 'bank' => $bankId, 'body' => $body];
    }

    private function id(): string
    {
        return 'fake-'.$this->nextId++;
    }

    /**
     * @return array<string, bool>
     */
    private static function features(): array
    {
        $names = ['observations', 'mcp', 'worker', 'bank_config_api', 'bank_llm_health', 'file_upload_api',
            'document_export_api', 'document_import_api', 'audit_log', 'llm_trace', 'store_document_text'];

        return array_fill_keys($names, false);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function json(array $body, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
