<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Responses;

/**
 * Reads response fields without trusting their types, so a surprising value becomes null instead of a TypeError.
 *
 * @internal
 */
final class Fields
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function map(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $map = [];

        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<mixed>|null
     */
    public static function list(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values($value) : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        return array_values(array_filter(self::list($data, $key) ?? [], is_string(...)));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function objects(array $data, string $key): array
    {
        $objects = [];

        foreach (self::list($data, $key) ?? [] as $item) {
            if (is_array($item)) {
                $objects[] = self::map(['item' => $item], 'item') ?? [];
            }
        }

        return $objects;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, int>|null
     */
    public static function counts(array $data, string $key): ?array
    {
        $map = self::map($data, $key);

        return $map === null ? null : array_map(static fn (mixed $value): int => is_int($value) ? $value : 0, $map);
    }
}
