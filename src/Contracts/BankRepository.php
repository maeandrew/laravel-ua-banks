<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maeandrew\UaBanks\Data\Bank;

/**
 * Read access to the bank registry. All MFO / EDRPOU arguments are already normalized
 * (6 and 8 digits respectively) by the manager.
 */
interface BankRepository
{
    /**
     * Head office by its own MFO (aliases are NOT resolved here).
     */
    public function find(string $mfo): ?Bank;

    public function findByEdrpou(string $edrpou): ?Bank;

    /**
     * @return Collection<string, Bank> keyed by MFO, sorted by MFO
     */
    public function all(): Collection;

    /**
     * Regional directorate MFO => head office MFO.
     *
     * @return array<string, string>
     */
    public function aliases(): array;

    public function lastSyncedAt(): ?CarbonImmutable;

    /**
     * True when there is no registry data at all.
     */
    public function isEmpty(): bool;
}
