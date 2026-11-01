<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * A print agent (one station) as the server knows it: identity, key hash, settings, printers and what it last said.
 *
 * Which printers a claim may name depends on who owns the configuration: the server's list in `server` mode,
 * the list the agent reported in its last `hello` in `agent` mode (section 4.3).
 *
 * `empty_claim_at` is when the last claim came back empty without waiting, for the printers in
 * `empty_claim_printers`: a station that claims again sooner than {@see claimCooldownS()} after it, for none but
 * those printers, ignores `Retry-After` and gets 429 ({@see claimRateLimitLeft()}). Offering a printer that has
 * just become free is not limited. New work for the station, a scan request and a `hello` clear it.
 *
 * @phpstan-type AgentData array{
 *     id: string, name: string, key_id: string, key_hash: string, revoked: bool,
 *     settings: AgentSettings, printers: list<Printer>, reported_printers: list<Printer>, config_version: string,
 *     created_at: \DateTimeImmutable, last_hello_at: ?\DateTimeImmutable, last_claim_at: ?\DateTimeImmutable,
 *     last_seen_at: ?\DateTimeImmutable, hello_count: int, claim_count: int, last_hello: ?HelloReport,
 *     empty_claim_at?: ?\DateTimeImmutable, empty_claim_printers?: list<string>
 * }
 */
final class Agent
{
    /**
     * @param list<Printer> $printers         configured by the server
     * @param list<Printer> $reportedPrinters reported by the agent
     */
    private function __construct(
        private readonly AgentId $id,
        private string $name,
        private readonly string $keyId,
        private readonly string $keyHash,
        private bool $revoked,
        private AgentSettings $settings,
        private array $printers,
        private array $reportedPrinters,
        private string $configVersion,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $lastHelloAt,
        private ?\DateTimeImmutable $lastClaimAt,
        private ?\DateTimeImmutable $lastSeenAt,
        private int $helloCount,
        private int $claimCount,
        private ?HelloReport $lastHello,
    ) {
    }

    /**
     * @param list<Printer> $printers
     */
    public static function register(
        AgentId $id,
        string $name,
        AgentKey $key,
        AgentSettings $settings,
        array $printers,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $name,
            $key->keyId(),
            $key->hash(),
            false,
            $settings,
            $printers,
            [],
            Time::iso($now),
            $now,
            null,
            null,
            null,
            0,
            0,
            null,
        );
    }

    /**
     * @param AgentData $d
     */
    public static function fromData(array $d): self
    {
        $agent = new self(
            AgentId::fromString($d['id']),
            $d['name'],
            $d['key_id'],
            $d['key_hash'],
            $d['revoked'],
            $d['settings'],
            $d['printers'],
            $d['reported_printers'],
            $d['config_version'],
            $d['created_at'],
            $d['last_hello_at'],
            $d['last_claim_at'],
            $d['last_seen_at'],
            $d['hello_count'],
            $d['claim_count'],
            $d['last_hello'],
        );
        $agent->emptyClaimAt = $d['empty_claim_at'] ?? null;
        $agent->emptyClaimPrinters = null === $agent->emptyClaimAt ? [] : ($d['empty_claim_printers'] ?? []);

        return $agent;
    }

    /**
     * @return AgentData
     */
    public function toData(): array
    {
        return [
            'id' => $this->id->value, 'name' => $this->name, 'key_id' => $this->keyId, 'key_hash' => $this->keyHash,
            'revoked' => $this->revoked, 'settings' => $this->settings, 'printers' => $this->printers,
            'reported_printers' => $this->reportedPrinters, 'config_version' => $this->configVersion,
            'created_at' => $this->createdAt, 'last_hello_at' => $this->lastHelloAt,
            'last_claim_at' => $this->lastClaimAt, 'last_seen_at' => $this->lastSeenAt,
            'hello_count' => $this->helloCount, 'claim_count' => $this->claimCount, 'last_hello' => $this->lastHello,
            'empty_claim_at' => $this->emptyClaimAt, 'empty_claim_printers' => $this->emptyClaimPrinters,
        ];
    }

    private ?\DateTimeImmutable $emptyClaimAt = null;

    /** @var list<string> */
    private array $emptyClaimPrinters = [];

    /** Constant-time check of the key's secret against the stored hash. */
    public function authenticatesWith(AgentKey $key): bool
    {
        return $key->matches($this->keyHash);
    }

    public function id(): AgentId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function settings(): AgentSettings
    {
        return $this->settings;
    }

    public function configVersion(): string
    {
        return $this->configVersion;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastHelloAt(): ?\DateTimeImmutable
    {
        return $this->lastHelloAt;
    }

    public function lastClaimAt(): ?\DateTimeImmutable
    {
        return $this->lastClaimAt;
    }

    public function emptyClaimAt(): ?\DateTimeImmutable
    {
        return $this->emptyClaimAt;
    }

    /** The shortest gap after an empty claim: half the poll interval, at least half a second. */
    public function claimCooldownS(): float
    {
        return max(0.5, $this->settings->pollIntervalS * 0.5);
    }

    /** @return list<string> the printers of the last empty claim; empty when there is none */
    public function emptyClaimPrinters(): array
    {
        return $this->emptyClaimPrinters;
    }

    /**
     * Seconds until a claim for `$printerIds` is welcome; 0 when it is: no empty claim, long enough ago, or the
     * claim offers a printer the empty one did not (it has just become free).
     *
     * @param list<string> $printerIds
     */
    public function claimRateLimitLeft(\DateTimeImmutable $now, array $printerIds): float
    {
        if (null === $this->emptyClaimAt || [] !== array_diff($printerIds, $this->emptyClaimPrinters)) {
            return 0.0;
        }

        return max(0.0, $this->claimCooldownS() - Time::secondsBetween($this->emptyClaimAt, $now));
    }

    /**
     * Remembers whether the claim just answered came back empty without waiting (`$emptyAt`, with the printers it
     * offered) or not (null).
     *
     * @param list<string> $printerIds
     */
    public function recordClaimResult(?\DateTimeImmutable $emptyAt, array $printerIds = []): void
    {
        $this->emptyClaimAt = $emptyAt;
        $this->emptyClaimPrinters = null === $emptyAt ? [] : $printerIds;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function helloCount(): int
    {
        return $this->helloCount;
    }

    public function claimCount(): int
    {
        return $this->claimCount;
    }

    public function lastHello(): ?HelloReport
    {
        return $this->lastHello;
    }

    /** @return list<Printer> */
    public function configuredPrinters(): array
    {
        return $this->printers;
    }

    /** @return list<Printer> */
    public function reportedPrinters(): array
    {
        return $this->reportedPrinters;
    }

    /** @return list<Printer> the printers a claim may name */
    public function effectivePrinters(): array
    {
        return ManagedBy::Agent === $this->settings->managedBy ? $this->reportedPrinters : $this->printers;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the requested ids this agent actually has, in request order, without duplicates
     */
    public function knownPrinterIds(array $ids): array
    {
        $known = array_map(static fn (Printer $p): string => $p->id, $this->effectivePrinters());

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => in_array($id, $known, true))));
    }

    public function findPrinterByRole(string $role): ?Printer
    {
        foreach ($this->effectivePrinters() as $printer) {
            if ($printer->role === $role) {
                return $printer;
            }
        }

        return null;
    }

    public function findPrinter(string $id): ?Printer
    {
        foreach ($this->effectivePrinters() as $printer) {
            if ($printer->id === $id) {
                return $printer;
            }
        }

        return null;
    }

    /** @return list<string> `capabilities.layout` from the last `hello`; empty before the first one */
    public function layoutCapabilities(): array
    {
        return $this->lastHello?->layout() ?? [];
    }

    public function hasPrinter(string $id): bool
    {
        return [] !== $this->knownPrinterIds([$id]);
    }

    /** True/false once the agent has said anything; null before its first `hello`. */
    public function announcesDiscovery(): ?bool
    {
        return $this->lastHello?->discovery;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function restore(): void
    {
        $this->revoked = false;
    }

    public function configure(AgentSettings $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Replaces the server-owned printer list and bumps `config_version` so agents reload it (section 4.2).
     *
     * @param list<Printer> $printers
     */
    public function replacePrinters(array $printers, \DateTimeImmutable $now): void
    {
        $this->printers = $printers;
        $this->bumpConfigVersion($now);
    }

    public function bumpConfigVersion(\DateTimeImmutable $now): void
    {
        $this->configVersion = Time::iso($now);
    }

    /**
     * @param list<Printer> $reportedPrinters ignored unless the agent owns its configuration
     */
    public function recordHello(HelloReport $report, array $reportedPrinters, \DateTimeImmutable $now): void
    {
        $this->lastHello = $report;
        $this->lastHelloAt = $now;
        $this->lastSeenAt = $now;
        $this->recordClaimResult(null);
        ++$this->helloCount;
        if (ManagedBy::Agent === $this->settings->managedBy) {
            $this->reportedPrinters = $reportedPrinters;
        }
    }

    public function recordActivity(AgentActivity $activity, \DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
        if (AgentActivity::Claim === $activity) {
            $this->lastClaimAt = $now;
            ++$this->claimCount;
        }
    }
}
