<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Tests\Support\OpenApiContract;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The contract checks guard every other test, so they need tests of their own.
 */
final class OpenApiContractTest extends TestCase
{
    public function test_it_is_pinned_to_hindsight_1_1_0(): void
    {
        $this->assertSame('1.1.0', OpenApiContract::instance()->version());
    }

    public function test_a_documented_request_passes(): void
    {
        OpenApiContract::instance()->assertRequest($this->json('POST', '/v1/default/banks/b1/memories', [
            'items' => [['content' => 'Alice works at Google', 'tags' => ['user:1']]],
            'async' => true,
        ]));

        OpenApiContract::instance()->assertRequest(new Request('GET', '/v1/default/banks/b1/memories/list?limit=10&tags=a&tags=b'));

        $this->addToAssertionCount(1);
    }

    public function test_a_body_that_breaks_the_schema_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('does not match the OpenAPI schema');

        OpenApiContract::instance()->assertRequest($this->json('POST', '/v1/default/banks/b1/memories', [
            'items' => [['text' => 'content is the field name, not text']],
        ]));
    }

    public function test_a_misspelled_field_fails(): void
    {
        $this->expectException(AssertionFailedError::class);

        OpenApiContract::instance()->assertRequest($this->json('POST', '/v1/default/banks/b1/memories/recall', [
            'query' => 'Where does Alice work?',
            'maxTokens' => 512,
        ]));
    }

    public function test_a_wrong_type_fails(): void
    {
        $this->expectException(AssertionFailedError::class);

        OpenApiContract::instance()->assertRequest($this->json('POST', '/v1/default/banks/b1/memories/recall', [
            'query' => 'Where does Alice work?',
            'max_tokens' => 'a lot',
        ]));
    }

    public function test_an_unknown_path_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('is not an operation');

        OpenApiContract::instance()->assertRequest(new Request('GET', '/v1/default/banks/b1/nothing-here'));
    }

    public function test_an_undocumented_query_parameter_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("no query parameter 'page'");

        OpenApiContract::instance()->assertRequest(new Request('GET', '/v1/default/banks?page=2'));
    }

    public function test_php_style_array_query_parameters_fail(): void
    {
        $this->expectException(AssertionFailedError::class);

        OpenApiContract::instance()->assertRequest(new Request('GET', '/v1/default/banks/b1/memories/list?tags%5B0%5D=a'));
    }

    public function test_responses_are_checked_too(): void
    {
        OpenApiContract::instance()->assertResponse('POST', '/v1/default/banks/b1/memories/recall', [
            'results' => [['id' => 'f1', 'text' => 'Alice works at Google']],
        ]);

        $this->expectException(AssertionFailedError::class);

        OpenApiContract::instance()->assertResponse('POST', '/v1/default/banks/b1/memories/recall', [
            'results' => [['text' => 'id is required']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(string $method, string $path, array $body): Request
    {
        return new Request($method, $path, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
