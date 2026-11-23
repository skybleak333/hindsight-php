<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight;

use Fedoseev\Hindsight\Exceptions\ConfigurationException;
use Fedoseev\Hindsight\Exceptions\InvalidWebhookException;
use JsonException;
use SensitiveParameter;

final readonly class WebhookEvent
{
    /** @param array<array-key, mixed> $data Event-specific fields. */
    private function __construct(
        public string $eventId,
        public string $type,
        public string $bankId,
        public string $operationId,
        public string $status,
        public string $timestamp,
        public array $data,
    ) {}

    /**
     * Verify X-Hindsight-Signature-V2 against the exact request bytes before decoding the event.
     * Deduplicate deliveries in the application using the verified eventId.
     */
    public static function verify(
        #[SensitiveParameter] string $body,
        string $signature,
        #[SensitiveParameter] string $secret,
        int $tolerance = 300,
        ?int $now = null,
    ): self {
        if ($secret === '' || $tolerance < 0) {
            throw new ConfigurationException('Webhook verification requires a secret and a non-negative tolerance.');
        }

        if (preg_match('/\At=([0-9]{1,19}),[ \t]*v1=([a-f0-9]{64})\z/', trim($signature, " \t"), $parts) !== 1
            || ($signedAt = filter_var($parts[1], FILTER_VALIDATE_INT)) === false) {
            throw new InvalidWebhookException('Invalid Hindsight webhook signature header.');
        }

        if (abs(($now ?? time()) - $signedAt) > $tolerance) {
            throw new InvalidWebhookException('Hindsight webhook signature timestamp is outside the allowed window.');
        }

        $expected = hash_hmac('sha256', $parts[1].'.'.$body, $secret);
        if (! hash_equals($expected, $parts[2])) {
            throw new InvalidWebhookException('Hindsight webhook signature does not match the request body.');
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidWebhookException('Hindsight webhook body is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload) || ! is_array($payload['data'] ?? null)
            || ($payload['data'] !== [] && array_is_list($payload['data']))) {
            throw new InvalidWebhookException('Hindsight webhook body must contain an event with a data object.');
        }

        $fields = [];
        foreach (['event_id', 'event', 'bank_id', 'operation_id', 'status', 'timestamp'] as $key) {
            $value = $payload[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidWebhookException("Hindsight webhook is missing a valid {$key} field.");
            }
            $fields[$key] = $value;
        }

        return new self($fields['event_id'], $fields['event'], $fields['bank_id'], $fields['operation_id'], $fields['status'], $fields['timestamp'], $payload['data']);
    }
}
