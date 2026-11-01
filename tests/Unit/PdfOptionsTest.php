<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrintDock\Server\Domain\PdfOptions;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\PrinterTransport;

/**
 * Section 9.2 options: what a job is stored with, and what an agent without `capabilities.layout` receives.
 */
final class PdfOptionsTest extends TestCase
{
    private function printer(?string $fit, int|string|null $rotate): Printer
    {
        return new Printer('p1', 'label', PrinterTransport::Socket, 'zpl', 203, '100x150mm', '10.0.0.1:9100', fit: $fit, rotate: $rotate);
    }

    public function test_the_job_wins_over_the_printer_and_the_printer_over_the_library_default(): void
    {
        self::assertSame(['fit' => 'shrink', 'rotate' => 'auto'], PdfOptions::resolve(null, null));
        self::assertSame(['fit' => 'none', 'rotate' => 180], PdfOptions::resolve(null, $this->printer('none', 180)));
        self::assertSame(['fit' => 'page', 'rotate' => 'auto'], PdfOptions::resolve(['fit' => 'page'], $this->printer(null, null)));
        self::assertSame(['rotate' => 90, 'fit' => 'shrink'], PdfOptions::resolve(['rotate' => '90'], null), 'an angle as text is read as the number');
        self::assertSame(['copies' => 3, 'x' => 1, 'rotate' => 0, 'fit' => 'page'], PdfOptions::resolve(['copies' => 3, 'x' => 1, 'rotate' => 0], $this->printer('page', 90)));
        self::assertSame(['copies' => 2, 'fit' => 'shrink', 'rotate' => 'auto'], PdfOptions::resolve(['copies' => '2'], null), 'copies as text is read as the number');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badOptions(): iterable
    {
        yield 'unknown fit' => [['fit' => 'stretch']];
        yield 'odd angle' => [['rotate' => 45]];
        yield 'odd angle as text' => [['rotate' => '45']];
        yield 'zero copies' => [['copies' => 0]];
        yield 'zero copies as text' => [['copies' => '0']];
        yield 'fractional copies as text' => [['copies' => '2.5']];
        yield 'copies as a float' => [['copies' => 2.0]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('badOptions')]
    public function test_bad_options_are_refused(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PdfOptions::resolve($options, null);
    }

    public function test_a_printer_with_a_bad_default_cannot_be_built(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Printer::fromArray(['printer_id' => 'p1', 'role' => 'a4', 'language' => 'driver', 'dpi' => 300, 'media' => '210x297mm', 'fit' => 'fill'], false);
    }

    public function test_a_printer_built_directly_reads_an_angle_given_as_text_like_from_array_does(): void
    {
        self::assertSame(90, $this->printer(null, '90')->rotate);
        self::assertEquals($this->printer(null, '90'), Printer::fromArray($this->printer(null, 90)->toArray(), true));
        $this->expectException(\InvalidArgumentException::class);
        $this->printer(null, '45');
    }

    public function test_printer_defaults_survive_an_array_round_trip(): void
    {
        $printer = $this->printer('shrink', 'auto');

        self::assertEquals($printer, Printer::fromArray($printer->toArray(), true));
    }

    public function test_only_announced_layout_features_reach_the_agent(): void
    {
        $options = ['copies' => 2, 'fit' => 'shrink', 'rotate' => 'auto'];

        self::assertSame(['copies' => 2, 'fit' => 'page', 'rotate' => 0], PdfOptions::forAgent($options, []));
        self::assertSame(['copies' => 2, 'fit' => 'shrink', 'rotate' => 0], PdfOptions::forAgent($options, ['shrink']));
        self::assertSame(['copies' => 2, 'fit' => 'page', 'rotate' => 'auto'], PdfOptions::forAgent($options, ['auto_rotate', 'future']));
        self::assertSame(['fit' => 'none', 'rotate' => 90], PdfOptions::forAgent(['fit' => 'none', 'rotate' => 90], []));
    }
}
