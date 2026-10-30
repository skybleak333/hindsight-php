<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Exceptions\OperationFailedException;
use Fedoseev\Hindsight\Exceptions\TimeoutException;
use Fedoseev\Hindsight\Responses\OperationStatus;

final class OperationsTest extends ClientTestCase
{
    public function test_get_operation(): void
    {
        $this->respond('GET', '/v1/default/banks/b1/operations/op-1', [
            'operation_id' => 'op-1', 'status' => 'processing', 'operation_type' => 'retain', 'created_at' => '2026-10-30T10:00:00Z',
        ]);

        $operation = $this->hindsight->getOperation('b1', 'op-1');

        $this->assertSent('GET', '/v1/default/banks/b1/operations/op-1');
        $this->assertSame('op-1', $operation->id);
        $this->assertSame(OperationStatus::Processing, $operation->status);
        $this->assertSame('retain', $operation->type);
        $this->assertFalse($operation->isFinished());
    }

    public function test_wait_for_operation_polls_until_it_completes(): void
    {
        $this->respond('GET', '/v1/default/banks/b1/operations/op-1', ['operation_id' => 'op-1', 'status' => 'pending']);
        $this->respond('GET', '/v1/default/banks/b1/operations/op-1', ['operation_id' => 'op-1', 'status' => 'processing']);
        $this->respond('GET', '/v1/default/banks/b1/operations/op-1', ['operation_id' => 'op-1', 'status' => 'completed', 'completed_at' => '2026-10-30T10:00:03Z']);

        $operation = $this->hindsight->waitForOperation('b1', 'op-1', timeout: 5, interval: 0.001);

        $this->assertCount(3, $this->http->requests);
        $this->assertTrue($operation->isCompleted());
        $this->assertSame('2026-10-30T10:00:03Z', $operation->completedAt);
    }

    public function test_wait_for_operation_throws_when_the_operation_fails(): void
    {
        $this->respond('GET', '/v1/default/banks/b1/operations/op-1', ['operation_id' => 'op-1', 'status' => 'failed', 'error_message' => 'LLM provider returned 429']);

        try {
            $this->hindsight->waitForOperation('b1', 'op-1', timeout: 5, interval: 0.001);
            $this->fail('Expected the failed operation to throw.');
        } catch (OperationFailedException $exception) {
            $this->assertSame('Hindsight operation op-1 failed: LLM provider returned 429', $exception->getMessage());
            $this->assertSame(OperationStatus::Failed, $exception->operation->status);
        }
    }

    public function test_wait_for_operation_gives_up_after_the_timeout(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->http->json(['operation_id' => 'op-1', 'status' => 'processing']);
        }

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Hindsight operation op-1 is still processing after 0.05 seconds.');

        $this->hindsight->waitForOperation('b1', 'op-1', timeout: 0.05, interval: 0.01);
    }

    public function test_list_and_cancel_operations(): void
    {
        $this->http->json(['operations' => [], 'total' => 0]);
        $this->hindsight->listOperations('b1', status: 'pending', limit: 5);
        $this->assertSent('GET', '/v1/default/banks/b1/operations?status=pending&limit=5');

        $this->respond('DELETE', '/v1/default/banks/b1/operations/op-1', ['success' => true, 'message' => 'cancelled', 'operation_id' => 'op-1']);
        $this->assertTrue($this->hindsight->cancelOperation('b1', 'op-1')['success']);
        $this->assertSent('DELETE', '/v1/default/banks/b1/operations/op-1');
    }

    public function test_an_unknown_status_from_a_newer_server_does_not_crash(): void
    {
        $this->checkContract = false;
        $this->http->json(['operation_id' => 'op-1', 'status' => 'paused']);

        $operation = $this->hindsight->getOperation('b1', 'op-1');

        $this->assertSame(OperationStatus::Unknown, $operation->status);
        $this->assertSame('paused', $operation->raw['status']);
        $this->assertFalse($operation->isFinished());
    }
}
