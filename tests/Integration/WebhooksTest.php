<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Integration;

use Fedoseev\Hindsight\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    use UsesRealHindsight;

    public function test_a_retain_delivery_is_signed_and_retried_with_the_same_event_id(): void
    {
        if (getenv('HINDSIGHT_TEST_WEBHOOKS') !== '1') {
            $this->markTestSkipped('The test server must allow the loopback webhook receiver.');
        }

        $this->connectToHindsight();
        $bank = $this->newBank();
        $secret = bin2hex(random_bytes(32));
        $capture = tempnam(sys_get_temp_dir(), 'hindsight-webhook-');
        $this->assertIsString($capture);
        $receiver = null;

        try {
            [$receiver, $url] = $this->startReceiver($capture);
            $this->hindsight->createBank($bank);
            $hook = $this->hindsight->createWebhook($bank, $url, $secret, ['retain.completed'], enabled: false);
            $id = $hook['id'];

            $this->assertFalse($hook['enabled']);
            $this->assertSame([$id], array_column($this->hindsight->listWebhooks($bank)['items'], 'id'));
            $this->assertTrue($this->hindsight->updateWebhook($bank, $id, ['enabled' => true])['enabled']);

            $retain = $this->hindsight->retain($bank, 'Alice prefers email support.', documentId: 'customer-note', async: true);
            $this->assertNotNull($retain->operationId);
            $delivery = $this->waitForDelivery($bank, $id);
            $this->assertSame(2, $delivery['attempts']);
            $this->assertSame(204, $delivery['last_response_status']);

            $lines = file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($lines);
            $this->assertCount(2, $lines);
            $deliveries = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
            $this->assertNotSame($deliveries[0]['signature'], $deliveries[1]['signature']);

            foreach ($deliveries as $received) {
                $body = base64_decode($received['body'], true);
                $this->assertIsString($body);
                $event = WebhookEvent::verify($body, $received['signature'], $secret);
                $this->assertSame($delivery['event_id'], $event->eventId);
                $this->assertSame($event->eventId, $received['event_id']);
                $this->assertSame($delivery['id'], $received['delivery_id']);
                $this->assertSame('retain.completed', $event->type);
                $this->assertSame($bank, $event->bankId);
                $this->assertSame($retain->operationId, $event->operationId);
                $this->assertSame('customer-note', $event->data['document_id']);
            }

            $this->assertTrue($this->hindsight->deleteWebhook($bank, $id)['success']);
            $this->assertSame([], $this->hindsight->listWebhooks($bank)['items']);
        } finally {
            if (is_resource($receiver)) {
                proc_terminate($receiver);
                proc_close($receiver);
            }
            unlink($capture);
            $this->deleteBanks();
        }
    }

    /** @return array{resource, string} */
    private function startReceiver(string $capture): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->assertIsString($address);

        $receiver = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__.'/../Fixtures/webhook-receiver.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            env_vars: ['HINDSIGHT_TEST_WEBHOOK_CAPTURE' => $capture] + getenv(),
        );
        $this->assertIsResource($receiver);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @stream_socket_client('tcp://'.$address, timeout: 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return [$receiver, 'http://'.$address];
            }
            usleep(20_000);
        }

        proc_terminate($receiver);
        proc_close($receiver);
        $this->fail('The loopback webhook receiver did not start.');
    }

    /** @return array<string, mixed> */
    private function waitForDelivery(string $bank, string $webhookId): array
    {
        $deadline = hrtime(true) + 45_000_000_000;

        do {
            $page = $this->hindsight->listWebhookDeliveries($bank, $webhookId, limit: 5);
            $delivery = $page['items'][0] ?? [];
            if (($delivery['status'] ?? null) === 'completed') {
                return $delivery;
            }
            usleep(200_000);
        } while (hrtime(true) < $deadline);

        $this->fail('Webhook delivery did not complete: '.json_encode($delivery, JSON_THROW_ON_ERROR));
    }
}
