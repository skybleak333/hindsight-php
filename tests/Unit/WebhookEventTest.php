<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Exceptions\InvalidWebhookException;
use Fedoseev\Hindsight\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    private const BODY = '{"event_id":"event-1","event":"retain.completed","bank_id":"support","operation_id":"op-1","status":"completed","timestamp":"2026-11-23T14:00:00Z","data":{"document_id":"doc-1"}}';

    private const SIGNED_AT = 1795442400;

    private const SIGNATURE = 't=1795442400,v1=b8a5885b0253b88ee9b7e82f1711c62d746715b5dc8881b46b0ae1306cc7eaec';

    public function test_it_returns_an_event_after_verifying_the_raw_bytes(): void
    {
        $event = WebhookEvent::verify(self::BODY, self::SIGNATURE, 'test-secret', now: self::SIGNED_AT + 300);

        $this->assertSame('event-1', $event->eventId);
        $this->assertSame('retain.completed', $event->type);
        $this->assertSame('support', $event->bankId);
        $this->assertSame('op-1', $event->operationId);
        $this->assertSame('completed', $event->status);
        $this->assertSame(['document_id' => 'doc-1'], $event->data);
    }

    #[DataProvider('invalidDeliveries')]
    public function test_it_rejects_invalid_deliveries(string $body, string $header, int $now): void
    {
        $this->expectException(InvalidWebhookException::class);

        WebhookEvent::verify($body, $header, 'test-secret', now: $now);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function invalidDeliveries(): iterable
    {
        yield 'edited raw body' => [self::BODY.' ', self::SIGNATURE, self::SIGNED_AT];
        yield 'expired signature' => [self::BODY, self::SIGNATURE, self::SIGNED_AT + 301];
        yield 'signature from the future' => [self::BODY, self::SIGNATURE, self::SIGNED_AT - 301];
        yield 'duplicate signature' => [self::BODY, self::SIGNATURE.','.self::SIGNATURE, self::SIGNED_AT];
        yield 'changed timestamp' => [self::BODY, str_replace('1795442400', '1795442401', self::SIGNATURE), self::SIGNED_AT];
        yield 'body without an event id' => [
            '{"data":{}}',
            't='.self::SIGNED_AT.',v1='.hash_hmac('sha256', self::SIGNED_AT.'.{"data":{}}', 'test-secret'),
            self::SIGNED_AT,
        ];
    }
}
