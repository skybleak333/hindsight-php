<?php

declare(strict_types=1);

$path = (string) getenv('HINDSIGHT_TEST_WEBHOOK_CAPTURE');
clearstatcache(true, $path);
$first = filesize($path) === 0;

$delivery = [
    'body' => base64_encode((string) file_get_contents('php://input')),
    'signature' => $_SERVER['HTTP_X_HINDSIGHT_SIGNATURE_V2'] ?? '',
    'event_id' => $_SERVER['HTTP_X_HINDSIGHT_EVENT_ID'] ?? '',
    'delivery_id' => $_SERVER['HTTP_X_HINDSIGHT_DELIVERY_ID'] ?? '',
];
file_put_contents($path, json_encode($delivery, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);

http_response_code($first ? 503 : 204);
