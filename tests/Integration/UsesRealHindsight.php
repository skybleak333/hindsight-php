<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Connects to a real Hindsight server, by default http://127.0.0.1:8888.
 *
 * CI starts one with HINDSIGHT_API_LLM_PROVIDER=none: retain stores chunks without fact extraction,
 * recall works, reflect answers 400. Every test gets throwaway banks that are deleted afterwards.
 */
trait UsesRealHindsight
{
    protected Hindsight $hindsight;

    /** Raw response bodies, for checking them against the OpenAPI document. */
    protected RecordingHttpClient $recorder;

    /** @var list<string> */
    private array $banks = [];

    protected function connectToHindsight(): Hindsight
    {
        $url = (string) (getenv('HINDSIGHT_TEST_URL') ?: 'http://127.0.0.1:8888');
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        // These tests create and delete banks. Never point them at a server with real memories by accident.
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && getenv('HINDSIGHT_TEST_ALLOW_REMOTE') !== '1') {
            $this->fail("Refusing to run integration tests against {$host}. Set HINDSIGHT_TEST_ALLOW_REMOTE=1 for a disposable remote server.");
        }

        $this->recorder = new RecordingHttpClient(new GuzzleClient(['timeout' => 60]));
        $this->hindsight = new Hindsight($url, apiKey: getenv('HINDSIGHT_TEST_API_KEY') ?: null, httpClient: $this->recorder);

        try {
            $this->hindsight->version();
        } catch (HindsightException $exception) {
            if (getenv('HINDSIGHT_TEST_REQUIRED') === '1') {
                throw $exception;
            }

            $this->markTestSkipped("No Hindsight server at {$url}: {$exception->getMessage()}");
        }

        return $this->hindsight;
    }

    protected function newBank(): string
    {
        return $this->banks[] = 'hindsight-php-test-'.bin2hex(random_bytes(6));
    }

    protected function deleteBanks(): void
    {
        foreach ($this->banks as $bank) {
            try {
                $this->hindsight->deleteBank($bank);
            } catch (HindsightException) {
                // Never created, or the server went away; nothing to clean up then.
            }
        }

        $this->banks = [];
    }
}
