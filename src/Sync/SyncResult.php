<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Sync;

use Maeandrew\UaBanks\Data\Bank;

/**
 * Difference between the stored registry and freshly downloaded data.
 */
final readonly class SyncResult
{
    /**
     * @param  array<string, Bank>  $added  keyed by MFO
     * @param  array<string, array{old: Bank, new: Bank, changes: list<string>}>  $updated  keyed by MFO
     * @param  array<string, Bank>  $removed  banks that disappeared from the source in this run
     * @param  array<string, array{old: Bank, new: Bank}>  $statusChanges  KSTAN changes, keyed by MFO
     * @param  list<string>  $warnings
     */
    public function __construct(
        public Registry $registry,
        public array $added,
        public array $updated,
        public array $removed,
        public array $statusChanges,
        public array $warnings,
        public bool $firstSync,
        public bool $dryRun,
        public int $aliasesChanged = 0,
    ) {}

    public function hasChanges(): bool
    {
        return $this->added !== [] || $this->updated !== [] || $this->removed !== [] || $this->aliasesChanged > 0;
    }
}
