<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * The PDF `options` of section 9.2: validation when a job is created, defaults, and the downgrade for agents
 * that predate `fit: shrink` / `rotate: auto`.
 *
 * A job that does not set `fit` or `rotate` takes them from its printer, otherwise `shrink` and `auto`: labels
 * print at real size when they fit (couriers need barcodes at real size) and turn to match the media. The stored
 * options keep what was asked for; {@see forAgent()} only changes what one agent receives, so an agent that did
 * not list `capabilities.layout` gets `page` / `0`, the behaviour it always had, and never a value it rejects.
 */
final class PdfOptions
{
    public const FITS = ['page', 'shrink', 'none'];
    public const ROTATIONS = [0, 90, 180, 270, 'auto'];
    public const DEFAULT_FIT = 'shrink';
    public const DEFAULT_ROTATE = 'auto';

    /** Values of `capabilities.layout` (section 4.1). */
    public const LAYOUT_SHRINK = 'shrink';
    public const LAYOUT_AUTO_ROTATE = 'auto_rotate';

    /**
     * @param array<string, mixed>|null $options as the host passed them
     *
     * @return array<string, mixed> validated, with `fit` and `rotate` always present; unknown keys are kept
     *
     * @throws \InvalidArgumentException for a bad `copies`, `fit` or `rotate`
     */
    public static function resolve(?array $options, ?Printer $printer): array
    {
        $options ??= [];
        if (array_key_exists('copies', $options)) {
            $options['copies'] = self::copies($options['copies']);
        }
        $options['fit'] = self::fit($options['fit'] ?? null) ?? $printer->fit ?? self::DEFAULT_FIT;
        $options['rotate'] = self::rotate($options['rotate'] ?? null) ?? $printer->rotate ?? self::DEFAULT_ROTATE;

        return $options;
    }

    /**
     * What an agent receives: `shrink` becomes `page` and `auto` becomes `0` unless the agent announced them.
     *
     * @param array<string, mixed> $options
     * @param list<string>         $layout  the agent's `capabilities.layout`
     *
     * @return array<string, mixed>
     */
    public static function forAgent(array $options, array $layout): array
    {
        if ('shrink' === ($options['fit'] ?? null) && !in_array(self::LAYOUT_SHRINK, $layout, true)) {
            $options['fit'] = 'page';
        }
        if ('auto' === ($options['rotate'] ?? null) && !in_array(self::LAYOUT_AUTO_ROTATE, $layout, true)) {
            $options['rotate'] = 0;
        }

        return $options;
    }

    /**
     * A count sent as text (`"2"`) is read as the number, as earlier versions accepted it.
     *
     * @throws \InvalidArgumentException unless a positive integer
     */
    public static function copies(mixed $value): int
    {
        if (is_string($value) && 1 === preg_match('/^[1-9][0-9]{0,8}$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('options.copies must be a positive integer');
        }

        return $value;
    }

    /** @throws \InvalidArgumentException unless null or one of {@see FITS} */
    public static function fit(mixed $value): ?string
    {
        if (null !== $value && !in_array($value, self::FITS, true)) {
            throw new \InvalidArgumentException('fit must be page, shrink or none');
        }

        return $value;
    }

    /**
     * An angle sent as text (`"90"`) is read as the number, as agents always accepted it.
     *
     * @throws \InvalidArgumentException unless null or one of {@see ROTATIONS}
     */
    public static function rotate(mixed $value): int|string|null
    {
        if (is_string($value) && in_array($value, ['0', '90', '180', '270'], true)) {
            $value = (int) $value;
        }
        if (null !== $value && !in_array($value, self::ROTATIONS, true)) {
            throw new \InvalidArgumentException('rotate must be 0, 90, 180, 270 or auto');
        }

        return $value;
    }
}
