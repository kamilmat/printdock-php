<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * An update offer for `GET /release` (section 8). PAP/1 only fixes the shape; installing is the agent's business.
 */
final class Release
{
    public function __construct(
        public readonly string $version,
        public readonly string $url,
        public readonly string $sha256,
        public readonly ?string $requiredMin = null,
        public readonly ?string $signature = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'version' => $this->version,
            'required_min' => $this->requiredMin,
            'url' => $this->url,
            'sha256' => $this->sha256,
            'signature' => $this->signature,
        ], static fn (?string $v): bool => null !== $v);
    }
}
