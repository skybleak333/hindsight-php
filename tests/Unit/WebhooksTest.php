<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

final class WebhooksTest extends ClientTestCase
{
    public function test_it_registers_and_lists_webhooks(): void
    {
        $path = '/v1/default/banks/support/webhooks';
        $hook = ['id' => 'hook-1', 'bank_id' => 'support', 'url' => 'https://example.test/events', 'event_types' => ['retain.completed'], 'enabled' => false];
        $page = ['items' => [$hook], 'total' => 3, 'limit' => 1, 'offset' => 2];
        $this->respond('POST', $path, $hook, 201);
        $this->respond('GET', $path, $page);

        $registered = $this->hindsight->createWebhook('support', $hook['url'], 'test-secret', $hook['event_types'], enabled: false, httpConfig: ['headers' => []]);
        $this->assertSame($hook, $registered);
        $this->assertSent('POST', $path);
        $this->assertSame([
            'url' => $hook['url'], 'secret' => 'test-secret', 'event_types' => ['retain.completed'], 'enabled' => false, 'http_config' => ['headers' => []],
        ], $this->http->lastJson());
        $this->assertStringContainsString('"headers":{}', (string) $this->http->lastRequest()->getBody());

        $this->assertSame($page, $this->hindsight->listWebhooks('support', limit: 1, offset: 2));
        $this->assertSent('GET', $path.'?limit=1&offset=2');
    }

    public function test_it_updates_and_deletes_a_webhook(): void
    {
        $path = '/v1/default/banks/support/webhooks/hook-1';
        $response = ['id' => 'hook-1', 'bank_id' => 'support', 'url' => 'https://example.test/events', 'event_types' => ['retain.completed'], 'secret' => null, 'enabled' => true];
        $this->respond('PATCH', $path, $response);
        $this->respond('DELETE', $path, ['success' => true]);

        $this->assertSame($response, $this->hindsight->updateWebhook('support', 'hook-1', ['secret' => null, 'enabled' => true]));
        $this->assertSent('PATCH', $path);
        $this->assertSame(['secret' => null, 'enabled' => true], $this->http->lastJson());

        $this->assertSame(['success' => true], $this->hindsight->deleteWebhook('support', 'hook-1'));
        $this->assertSent('DELETE', $path);
    }

    public function test_it_passes_delivery_cursors_through(): void
    {
        $path = '/v1/default/banks/support/webhooks/hook-1/deliveries';
        $page = ['items' => [], 'next_cursor' => null];
        $this->respond('GET', $path, $page);

        $this->assertSame($page, $this->hindsight->listWebhookDeliveries('support', 'hook-1', limit: 2, cursor: '2026-11-23T14:00:00+00:00'));
        $this->assertSent('GET', $path.'?limit=2&cursor=2026-11-23T14%3A00%3A00%2B00%3A00');
    }
}
