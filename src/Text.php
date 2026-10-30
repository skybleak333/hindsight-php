<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight;

/**
 * @internal
 */
final class Text
{
    /**
     * Shorten UTF-8 text to at most $characters characters, without needing ext-mbstring.
     */
    public static function limit(string $text, int $characters): string
    {
        if (preg_match('/^.{0,'.$characters.'}/su', $text, $match) !== 1 || $match[0] === $text) {
            return $text;
        }

        return rtrim($match[0]).'...';
    }
}
