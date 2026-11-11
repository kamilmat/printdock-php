<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * A printer object of a station (section 4.3).
 *
 * Server-managed printers carry a transport and its address (`address`, `system_name` or `queue`); printers an
 * agent reports about itself carry neither address nor, optionally, transport. A job always names a concrete
 * `printer_id`; roles are only a way for the host to pick one when enqueueing.
 *
 * `fit` and `rotate` are the printer's defaults for PDF jobs that do not set them (section 9.2, {@see PdfOptions}).
 *
 * An `ipp` printer's `address` is its URI (`ipp://host[:port]/path` or `ipps://…`, {@see IppUri}); one that names an
 * IP address outside the local network is refused here, a host name is left to the agent, which checks what it
 * resolves to before connecting.
 */
final class Printer
{
    /** 0, 90, 180, 270, `auto` or null; an angle given as text (`"90"`) is stored as the number, as in {@see fromArray()}. */
    public readonly int|string|null $rotate;

    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly ?PrinterTransport $transport,
        public readonly string $language,
        public readonly int $dpi,
        public readonly string $media,
        public readonly ?string $address = null,
        public readonly ?string $systemName = null,
        public readonly ?string $queue = null,
        public readonly ?string $name = null,
        public readonly ?string $fit = null,
        int|string|null $rotate = null,
    ) {
        PdfOptions::fit($fit);
        $this->rotate = PdfOptions::rotate($rotate);
        if (PrinterTransport::Ipp === $transport && null !== $address) {
            IppUri::check($address);
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, bool $requireTransport): self
    {
        $id = Input::requiredString($data, 'printer_id', 128);
        $transport = null;
        if (null !== ($data['transport'] ?? null) || $requireTransport) {
            $raw = Input::requiredString($data, 'transport');
            $transport = PrinterTransport::tryFrom($raw)
                ?? throw new \InvalidArgumentException('transport must be socket, spooler, cups or ipp');
        }

        return new self(
            $id,
            Input::requiredString($data, 'role', 128),
            $transport,
            Input::requiredString($data, 'language', 32),
            Input::requiredInt($data, 'dpi'),
            Input::requiredString($data, 'media', 64),
            Input::optionalString($data, 'address', 255),
            Input::optionalString($data, 'system_name', 255),
            Input::optionalString($data, 'queue', 255),
            Input::optionalString($data, 'name', 255),
            PdfOptions::fit($data['fit'] ?? null),
            PdfOptions::rotate($data['rotate'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'printer_id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'transport' => $this->transport?->value,
            'address' => $this->address,
            'system_name' => $this->systemName,
            'queue' => $this->queue,
            'language' => $this->language,
            'dpi' => $this->dpi,
            'media' => $this->media,
            'fit' => $this->fit,
            'rotate' => $this->rotate,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
