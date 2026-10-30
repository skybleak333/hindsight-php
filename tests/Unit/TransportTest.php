<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Exceptions\ApiException;
use Fedoseev\Hindsight\Exceptions\AuthenticationException;
use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Exceptions\NotFoundException;
use Fedoseev\Hindsight\Exceptions\UnexpectedResponseException;
use Fedoseev\Hindsight\Exceptions\ValidationException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Tests\Support\ArrayLogger;
use Fedoseev\Hindsight\Tests\Support\FakeHttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class TransportTest extends ClientTestCase
{
    public function test_it_sends_the_api_key_and_identifies_itself(): void
    {
        $this->http->json(['api_version' => '1.1.0', 'features' => []]);

        $this->hindsight->version();

        $request = $this->http->lastRequest();
        $this->assertSent('GET', '/version');
        $this->assertSame('Bearer hsk_test', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('hindsight-php/'.Hindsight::VERSION, $request->getHeaderLine('User-Agent'));
        $this->assertFalse($request->hasHeader('Content-Type'));
    }

    public function test_it_sends_no_authorization_header_without_a_key(): void
    {
        $http = (new FakeHttpClient)->json(['status' => 'healthy']);

        (new Hindsight('http://localhost:8888', httpClient: $http))->health();

        $this->assertFalse($http->lastRequest()->hasHeader('Authorization'));
        $this->assertSame('http://localhost:8888/health', (string) $http->lastRequest()->getUri());
    }

    public function test_extra_headers_are_sent_with_every_request(): void
    {
        $http = (new FakeHttpClient)->json(['status' => 'healthy']);

        (new Hindsight('http://localhost:8888', httpClient: $http, headers: ['X-Tenant' => 'acme']))->health();

        $this->assertSame('acme', $http->lastRequest()->getHeaderLine('X-Tenant'));
    }

    public function test_a_base_url_with_a_path_prefix_is_kept(): void
    {
        $http = (new FakeHttpClient)->json(['results' => []]);

        (new Hindsight('https://example.com/hindsight/', httpClient: $http))->recall('b1', 'q');

        $this->assertSame('https://example.com/hindsight/v1/default/banks/b1/memories/recall', (string) $http->lastRequest()->getUri());
    }

    public function test_path_segments_are_encoded(): void
    {
        // Bank IDs made by the coding-agents plugin look like "coding-agent::my-repo".
        $this->respond('POST', '/v1/default/banks/coding-agent::my-repo/memories/recall', ['results' => []]);

        $this->hindsight->recall('coding-agent::my-repo', 'q');

        $this->assertSent('POST', '/v1/default/banks/coding-agent%3A%3Amy-repo/memories/recall');
    }

    public function test_non_ascii_text_is_sent_as_utf8(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories', ['success' => true, 'bank_id' => 'b1', 'items_count' => 1, 'async' => false]);

        $this->hindsight->retain('b1', 'Алиса любит чай 🍵');

        $this->assertStringContainsString('Алиса любит чай 🍵', (string) $this->http->lastRequest()->getBody());
    }

    public function test_invalid_utf8_does_not_break_the_request(): void
    {
        $this->respond('POST', '/v1/default/banks/b1/memories', ['success' => true, 'bank_id' => 'b1', 'items_count' => 1, 'async' => false]);

        // "Привет" cut in the middle of a two-byte character, as substr() on user input does.
        $this->hindsight->retain('b1', substr('Привет', 0, 5));

        $this->assertSame('Пр'."\u{FFFD}", $this->http->lastJson()['items'][0]['content'] ?? null);
    }

    public function test_parameters_the_server_ignored_are_logged(): void
    {
        // Hindsight 1.1 answers 200 for unknown fields, but names them in X-Ignored-Params.
        $logger = new ArrayLogger;
        $http = (new FakeHttpClient)->respond(new Response(200, ['Content-Type' => 'application/json', 'X-Ignored-Params' => 'max_token, min_score'], '{"results": []}'));

        (new Hindsight('http://hindsight.test', httpClient: $http, logger: $logger))->recall('b1', 'q', options: ['max_token' => 10, 'min_score' => 0.5]);

        $this->assertSame([[
            'level' => 'warning',
            'message' => 'Hindsight ignored unknown parameters: max_token, min_score (POST /v1/default/banks/b1/memories/recall)',
            'context' => ['parameters' => ['max_token', 'min_score'], 'method' => 'POST', 'path' => '/v1/default/banks/b1/memories/recall'],
        ]], $logger->records);
    }

    public function test_nothing_is_logged_when_every_parameter_was_used(): void
    {
        $logger = new ArrayLogger;
        $http = (new FakeHttpClient)->json(['results' => []]);

        (new Hindsight('http://hindsight.test', httpClient: $http, logger: $logger))->recall('b1', 'q');

        $this->assertSame([], $logger->records);
    }

    public function test_not_found_errors_carry_the_server_message(): void
    {
        $this->http->json(['detail' => 'Bank not found: missing'], 404);

        try {
            $this->hindsight->getBankProfile('missing');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $exception) {
            $this->assertSame('Hindsight API error 404: Bank not found: missing', $exception->getMessage());
            $this->assertSame(404, $exception->statusCode);
            $this->assertSame(['detail' => 'Bank not found: missing'], $exception->body);
            $this->assertInstanceOf(ApiException::class, $exception);
            $this->assertInstanceOf(HindsightException::class, $exception);
        }
    }

    public function test_validation_errors_list_the_fields(): void
    {
        $this->http->json(['detail' => [
            ['loc' => ['body', 'items', 0, 'content'], 'msg' => 'Field required', 'type' => 'missing'],
            ['loc' => ['body', 'async'], 'msg' => 'Input should be a valid boolean', 'type' => 'bool_parsing'],
        ]], 422);

        try {
            $this->hindsight->retain('b1', 'x');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Hindsight API error 422: body.items.0.content: Field required; body.async: Input should be a valid boolean',
                $exception->getMessage(),
            );
            $this->assertSame(['body.items.0.content' => 'Field required', 'body.async' => 'Input should be a valid boolean'], $exception->errors());
        }
    }

    public function test_auth_errors(): void
    {
        $this->http->json(['detail' => 'Invalid API key'], 401);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Hindsight API error 401: Invalid API key');

        $this->hindsight->listBanks();
    }

    public function test_a_proxy_error_page_is_reported_without_crashing(): void
    {
        $this->http->respond(new Response(502, ['Content-Type' => 'text/html'], '<html><body>Bad Gateway</body></html>'));

        try {
            $this->hindsight->health();
            $this->fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            $this->assertSame('Hindsight API error 502: Bad Gateway', $exception->getMessage());
            $this->assertSame('<html><body>Bad Gateway</body></html>', $exception->body);
        }
    }

    public function test_network_failures_become_connection_exceptions(): void
    {
        $this->http->fail(new ConnectException('cURL error 7: Failed to connect', new Request('GET', 'http://hindsight.test/health')));

        try {
            $this->hindsight->health();
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException $exception) {
            $this->assertSame('Could not reach Hindsight at http://hindsight.test: cURL error 7: Failed to connect', $exception->getMessage());
            $this->assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }
    }

    public function test_a_web_page_at_the_api_url_is_reported_clearly(): void
    {
        // e.g. HINDSIGHT_URL points at the Control Plane UI instead of the API.
        $this->http->respond(new Response(200, ['Content-Type' => 'text/html'], '<!doctype html><html><body>Hindsight Control Plane</body></html>'));

        try {
            $this->hindsight->listBanks();
            $this->fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $exception) {
            $this->assertStringStartsWith('Hindsight returned a non-JSON body: <!doctype html>', $exception->getMessage());
            $this->assertStringEndsWith('for GET /v1/default/banks; a JSON object was expected.', $exception->getMessage());
        }
    }

    public function test_request_reaches_endpoints_without_a_dedicated_method(): void
    {
        $this->respond('GET', '/v1/default/banks/b1/tags', ['items' => [], 'total' => 0, 'limit' => 100, 'offset' => 0]);

        $tags = $this->hindsight->request('GET', '/v1/default/banks/b1/tags', query: ['limit' => 100]);

        $this->assertSent('GET', '/v1/default/banks/b1/tags?limit=100');
        $this->assertSame(0, $tags['total']);
    }

    public function test_an_empty_success_body_returns_null(): void
    {
        $this->checkContract = false;
        $this->http->respond(new Response(204));

        $this->assertNull($this->hindsight->request('DELETE', '/v1/default/banks/b1/webhooks/w1'));
    }
}
