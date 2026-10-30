<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

/**
 * The API key is missing, wrong or not allowed to do this (401 or 403).
 */
final class AuthenticationException extends ApiException
{
}
