<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Events;

final class BanksSynced
{
    /**
     * @param  list<string>  $warnings  non-critical problems found in the source data
     */
    public function __construct(
        public readonly int $added,
        public readonly int $updated,
        public readonly int $removed,
        public readonly array $warnings = [],
    ) {}
}
