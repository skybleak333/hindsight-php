<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use RuntimeException;

/** A webhook delivery failed verification or did not contain a valid event. */
final class InvalidWebhookException extends RuntimeException implements HindsightException {}
