<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use RuntimeException;

/**
 * The application could not dispatch a retain to its queue.
 */
final class QueueDispatchException extends RuntimeException implements HindsightException {}
