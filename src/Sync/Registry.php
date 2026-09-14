<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Sync;

use Carbon\CarbonImmutable;
use Maeandrew\UaBanks\Data\Bank;

/**
 * A complete, validated set of registry data: head offices and MFO aliases.
 */
final readonly class Registry
{
    /**
     * @param  array<string, Bank>  $banks  keyed by MFO
     * @param  array<string, string>  $aliases  regional directorate MFO => head office MFO
     * @param  array<string, array<string, mixed>>  $raw  original NBU records keyed by MFO (may be partial)
     * @param  list<string>  $warnings  non-critical problems found while mapping
     */
    public function __construct(
        public array $banks,
        public array $aliases,
        public CarbonImmutable $generatedAt,
        public array $raw = [],
        public array $warnings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->banks === [];
    }
}
