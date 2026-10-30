<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use InvalidArgumentException;

/**
 * The client was configured with values it cannot use, such as a malformed URL.
 */
final class ConfigurationException extends InvalidArgumentException implements HindsightException
{
}
