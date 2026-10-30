<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

/**
 * Checks requests and responses against the official Hindsight OpenAPI document.
 *
 * The document is pinned in tests/Fixtures and was downloaded from the
 * vectorize-io/hindsight repository at the matching release tag.
 */
final class OpenApiContract
{
    private const SPEC_ID = 'https://hindsight.test/openapi.json';

    private static ?self $instance = null;

    /** @var array<string, mixed> The document as arrays, for finding operations. Schemas are validated from the registered copy. */
    private array $spec;

    private Validator $validator;

    private function __construct(string $path)
    {
        $json = (string) file_get_contents($path);
        $this->spec = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        // Decoded to objects so that empty JSON objects stay objects when the document is registered.
        $document = json_decode($json, flags: JSON_THROW_ON_ERROR);

        // The server's models ignore unknown fields, so a misspelled option would be dropped
        // without an error. Rejecting fields the schema does not name catches that here.
        foreach (get_object_vars($document->components->schemas) as $schema) {
            if (isset($schema->properties) && ! isset($schema->additionalProperties)) {
                $schema->additionalProperties = false;
            }
        }

        $this->validator = new Validator;
        $this->validator->setMaxErrors(5);
        $this->validator->resolver()?->registerRaw($document, self::SPEC_ID);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(__DIR__.'/../Fixtures/openapi-1.1.0.json');
    }

    public function version(): string
    {
        return $this->spec['info']['version'];
    }

    /**
     * Assert that the request names a documented operation and that its query and body match the schema.
     */
    public function assertRequest(RequestInterface $request): void
    {
        $method = strtolower($request->getMethod());
        [$template, $operation, $pointer] = $this->operation($method, $request->getUri()->getPath());

        $label = strtoupper($method).' '.$template;

        $this->assertQuery($label, $operation, $pointer, $request->getUri()->getQuery());

        $body = (string) $request->getBody();

        if (! isset($operation['requestBody']['content']['application/json']['schema'])) {
            Assert::assertSame('', $body, "{$label} does not take a request body.");

            return;
        }

        if ($body === '') {
            Assert::assertFalse($operation['requestBody']['required'] ?? false, "{$label} requires a request body.");

            return;
        }

        Assert::assertSame('application/json', $request->getHeaderLine('Content-Type'), "{$label} body must be JSON.");

        $this->assertMatchesSchema(
            "{$label} request body",
            json_decode($body, flags: JSON_THROW_ON_ERROR),
            $pointer.'/requestBody/content/application~1json/schema',
        );
    }

    /**
     * Assert that a response body is what the API documents for the given operation and status.
     */
    public function assertResponse(string $method, string $path, mixed $body, int $status = 200): void
    {
        [$template, $operation, $pointer] = $this->operation(strtolower($method), $path);

        Assert::assertTrue(
            isset($operation['responses'][(string) $status]['content']['application/json']['schema']),
            "{$method} {$template} documents no JSON body for status {$status}.",
        );

        // Arrays from fixtures are re-encoded; objects (a raw body decoded without assoc) are checked as they are.
        $data = is_array($body) ? json_decode(json_encode($body, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR) : $body;

        $this->assertMatchesSchema("{$method} {$template} response", $data, "{$pointer}/responses/{$status}/content/application~1json/schema");
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string} The path template, the operation and its JSON pointer.
     */
    private function operation(string $method, string $path): array
    {
        $candidates = [];

        foreach ($this->spec['paths'] as $template => $operations) {
            $pattern = '#^'.preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', preg_quote($template, '#')).'$#';

            if (preg_match($pattern, $path) === 1 && isset($operations[$method])) {
                $candidates[$template] = substr_count($template, '{');
            }
        }

        Assert::assertNotEmpty($candidates, strtoupper($method)." {$path} is not an operation in the Hindsight OpenAPI document.");

        // A literal segment such as /memories/list wins over a template such as /memories/{memory_id}.
        asort($candidates);
        $template = (string) array_key_first($candidates);

        return [$template, $this->spec['paths'][$template][$method], '#/paths/'.self::escape($template).'/'.$method];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function assertQuery(string $label, array $operation, string $pointer, string $query): void
    {
        $declared = [];

        foreach ($operation['parameters'] ?? [] as $index => $parameter) {
            if ($parameter['in'] === 'query') {
                $declared[$parameter['name']] = [$parameter, "{$pointer}/parameters/{$index}/schema"];
            }
        }

        $values = [];

        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $values[rawurldecode($name)][] = rawurldecode($value);
        }

        foreach ($values as $name => $given) {
            Assert::assertArrayHasKey($name, $declared, "{$label} has no query parameter '{$name}'.");

            [$parameter, $schemaPointer] = $declared[$name];
            $listPointer = $this->listItemsPointer($parameter['schema'] ?? [], $schemaPointer);

            Assert::assertTrue($listPointer !== null || count($given) === 1, "{$label} query parameter '{$name}' is not a list.");

            foreach ($given as $value) {
                $this->assertMatchesSchema("{$label} query parameter '{$name}'", $this->castQueryValue($value), $listPointer ?? $schemaPointer);
            }
        }

        foreach ($declared as $name => [$parameter]) {
            if ($parameter['required'] ?? false) {
                Assert::assertArrayHasKey($name, $values, "{$label} requires the query parameter '{$name}'.");
            }
        }
    }

    /**
     * Where the item schema of a list parameter lives, or null when the parameter is not a list.
     *
     * @param  array<string, mixed>  $schema
     */
    private function listItemsPointer(array $schema, string $pointer): ?string
    {
        if (($schema['type'] ?? null) === 'array') {
            return "{$pointer}/items";
        }

        foreach ($schema['anyOf'] ?? [] as $index => $option) {
            if (($option['type'] ?? null) === 'array') {
                return "{$pointer}/anyOf/{$index}/items";
            }
        }

        return null;
    }

    /**
     * Query strings carry text only; give the validator the scalar the server would parse.
     */
    private function castQueryValue(string $value): mixed
    {
        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => $value,
        };
    }

    private function assertMatchesSchema(string $label, mixed $data, string $pointer): void
    {
        $result = $this->validator->validate($data, self::SPEC_ID.$pointer);

        $errors = $result->isValid() ? [] : (new ErrorFormatter)->formatFlat($result->error());

        Assert::assertTrue($result->isValid(), "{$label} does not match the OpenAPI schema:\n".implode("\n", $errors)."\nGot: ".json_encode($data));
    }

    /**
     * Escape a key for a JSON pointer (RFC 6901), then for the URI fragment it goes into.
     */
    private static function escape(string $key): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $key));
    }
}
