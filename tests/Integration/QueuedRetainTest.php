<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\HindsightServiceProvider;
use Fedoseev\Hindsight\Laravel\Jobs\RetainMemory;
use Orchestra\Testbench\TestCase;

/**
 * The RetainMemory job against a real server: retries of one job are one operation.
 */
final class QueuedRetainTest extends TestCase
{
    use UsesRealHindsight;

    protected function getPackageProviders($app): array
    {
        return [HindsightServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Hindsight::class, $this->connectToHindsight());
    }

    protected function tearDown(): void
    {
        $this->deleteBanks();

        parent::tearDown();
    }

    public function test_a_retried_job_stores_its_memory_once(): void
    {
        $bank = $this->newBank();
        $job = new RetainMemory($bank, 'Carol takes the 8:15 train to work.', tags: ['user:carol']);

        // The first attempt reaches the server; then the same job, after a trip through the queue, runs again.
        $job->handle($this->hindsight);
        unserialize(serialize($job))->handle($this->hindsight);
        $this->hindsight->waitForOperation($bank, $job->operationId, timeout: 60, interval: 0.25);

        $this->assertSame(1, $this->retainOperations($bank));
        $this->assertSame(1, $this->copies($bank, 'Carol takes the 8:15 train'));
    }

    public function test_without_an_operation_id_a_retry_stores_a_second_copy(): void
    {
        // What the job prevents: the same async retain sent twice without an operation ID is two operations.
        $bank = $this->newBank();

        foreach ([1, 2] as $attempt) {
            $retained = $this->hindsight->retain($bank, 'Dave takes the 9:40 bus to work.', async: true);
            $this->hindsight->waitForOperation($bank, (string) $retained->operationId, timeout: 60, interval: 0.25);
        }

        $this->assertSame(2, $this->retainOperations($bank));
        $this->assertSame(2, $this->copies($bank, 'Dave takes the 9:40 bus'));
    }

    public function test_the_memory_keeps_the_time_the_job_was_dispatched(): void
    {
        $bank = $this->newBank();
        $job = new RetainMemory($bank, 'Erin renewed her subscription.', timestamp: '2026-03-02T08:15:00+00:00');

        $job->handle($this->hindsight);
        $this->hindsight->waitForOperation($bank, $job->operationId, timeout: 60, interval: 0.25);

        $memory = $this->hindsight->recall($bank, 'subscription')->results[0];
        $this->assertStringStartsWith('2026-03-02', (string) ($memory->raw['mentioned_at'] ?? ''), json_encode($memory->raw, JSON_THROW_ON_ERROR));
    }

    private function retainOperations(string $bank): int
    {
        $operations = $this->hindsight->listOperations($bank, limit: 100)['operations'] ?? [];

        return count(array_filter(
            is_array($operations) ? $operations : [],
            static fn (mixed $operation): bool => is_array($operation) && ($operation['task_type'] ?? $operation['operation_type'] ?? null) === 'batch_retain',
        ));
    }

    private function copies(string $bank, string $text): int
    {
        $items = $this->hindsight->listMemories($bank, limit: 100)['items'] ?? [];

        return count(array_filter(
            is_array($items) ? $items : [],
            static fn (mixed $item): bool => is_array($item) && str_contains((string) ($item['text'] ?? ''), $text),
        ));
    }
}
