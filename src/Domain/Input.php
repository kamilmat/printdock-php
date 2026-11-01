<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * Typed access to decoded JSON. Every failure is an InvalidArgumentException; the HTTP adapter turns it into
 * `400 bad_request`, an admin surface into whatever it answers for bad input.
 */
final class Input
{
    /**
     * Normalises the keys of a decoded JSON object to strings.
     *
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function toMap(array $data): array
    {
        $map = [];
        foreach ($data as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }

    /**
     * A decoded JSON document that must be an object (an empty list is the empty object).
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $decoded): array
    {
        if (!is_array($decoded) || (array_is_list($decoded) && [] !== $decoded)) {
            throw new \InvalidArgumentException('body must be a JSON object');
        }

        return self::toMap($decoded);
    }

    /** @param array<mixed> $data */
    public static function requiredString(array $data, string $key, int $maxLength = 1000): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(sprintf('%s is required and must be a non-empty string', $key));
        }
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s is too long', $key));
        }

        return $value;
    }

    /** @param array<mixed> $data */
    public static function optionalString(array $data, string $key, int $maxLength = 1000): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string', $key));
        }

        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }

    /** @param array<mixed> $data */
    public static function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('%s is required and must be an integer', $key));
        }

        return $value;
    }

    /** @param array<mixed> $data */
    public static function optionalInt(array $data, string $key, int $default): int
    {
        if (!array_key_exists($key, $data) || null === $data[$key]) {
            return $default;
        }

        return self::requiredInt($data, $key);
    }

    /** @param array<mixed> $data */
    public static function optionalFloat(array $data, string $key, float $default): float
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return $default;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a number', $key));
        }

        return (float) $value;
    }

    /** @param array<mixed> $data */
    public static function optionalBool(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return $default;
        }
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a boolean', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function requiredMap(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || (array_is_list($value) && [] !== $value)) {
            throw new \InvalidArgumentException(sprintf('%s is required and must be an object', $key));
        }

        return self::toMap($value);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function optionalMap(array $data, string $key): ?array
    {
        if (!array_key_exists($key, $data) || null === $data[$key]) {
            return null;
        }

        return self::requiredMap($data, $key);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<mixed>
     */
    public static function requiredList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('%s is required and must be a list', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<mixed>
     */
    public static function optionalList(array $data, string $key): array
    {
        if (!array_key_exists($key, $data) || null === $data[$key]) {
            return [];
        }

        return self::requiredList($data, $key);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $out = [];
        foreach (self::requiredList($data, $key) as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException(sprintf('%s must contain only strings', $key));
            }
            $out[] = $item;
        }

        return $out;
    }
}
