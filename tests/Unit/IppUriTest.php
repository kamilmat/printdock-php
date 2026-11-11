<?php

declare(strict_types=1);

namespace PrintDock\Server\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrintDock\Server\Domain\IppUri;
use PrintDock\Server\Domain\Printer;
use PrintDock\Server\Domain\PrinterTransport;

/**
 * The `ipp` transport's address (sections 4.3 and 9.5).
 */
final class IppUriTest extends TestCase
{
    public function test_local_and_named_printers_pass(): void
    {
        self::assertSame(['scheme' => 'ipp', 'host' => '192.168.1.20', 'port' => 631, 'path' => '/ipp/print'], IppUri::check('ipp://192.168.1.20/ipp/print'));
        self::assertSame(['scheme' => 'ipps', 'host' => 'fd00::5', 'port' => 8443, 'path' => '/x'], IppUri::check('ipps://[fd00::5]:8443/x'));
        self::assertSame('/ipp/print', IppUri::check('ipp://brother-hl.local')['path']);
        self::assertSame('10.0.0.7', IppUri::check('ipp://10.0.0.7:631/ipp/print')['host']);
        self::assertSame('127.0.0.1', IppUri::check('ipp://127.0.0.1:8631/ipp/print')['host']);
        self::assertSame('fe80::1', IppUri::check('ipp://[fe80::1]/ipp/print')['host']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badUris(): iterable
    {
        yield 'socket address' => ['10.0.0.1:9100'];
        yield 'http' => ['http://10.0.0.1/ipp/print'];
        yield 'public IPv4' => ['ipp://8.8.8.8/ipp/print'];
        yield 'public IPv6' => ['ipps://[2001:4860::8888]/ipp/print'];
        yield 'mapped public' => ['ipp://[::ffff:8.8.8.8]/ipp/print'];
        yield 'user info' => ['ipp://user:pw@10.0.0.1/ipp/print'];
        yield 'query' => ['ipp://10.0.0.1/ipp/print?x=1'];
        yield 'fragment' => ['ipp://10.0.0.1/ipp/print#x'];
        yield 'space' => ['ipp://10.0.0.1/ipp/pr int'];
        yield 'port' => ['ipp://10.0.0.1:99999/ipp/print'];
        yield 'no host' => ['ipp:///ipp/print'];
        yield 'empty' => [''];
    }

    #[DataProvider('badUris')]
    public function test_other_addresses_are_refused(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IppUri::check($uri);
    }

    public function test_printer_objects_check_the_address(): void
    {
        $printer = Printer::fromArray(['printer_id' => 'a4', 'role' => 'a4', 'transport' => 'ipp', 'language' => 'driver',
            'dpi' => 300, 'media' => '210x297mm', 'address' => 'ipp://192.168.1.20/ipp/print'], true);
        self::assertSame(PrinterTransport::Ipp, $printer->transport);
        self::assertSame('ipp://192.168.1.20/ipp/print', $printer->toArray()['address']);

        $reported = Printer::fromArray(['printer_id' => 'a4', 'role' => 'a4', 'transport' => 'ipp', 'language' => 'driver',
            'dpi' => 300, 'media' => '210x297mm'], false);
        self::assertNull($reported->address, 'an agent reports its own printers without addresses');

        $this->expectException(\InvalidArgumentException::class);
        new Printer('a4', 'a4', PrinterTransport::Ipp, 'driver', 300, '210x297mm', 'ipp://203.0.113.9/ipp/print');
    }
}
