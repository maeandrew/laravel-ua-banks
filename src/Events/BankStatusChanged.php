<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Events;

use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Enums\BankStatus;

/**
 * The KSTAN code of a bank changed between two synchronizations.
 */
final class BankStatusChanged
{
    public function __construct(
        public readonly Bank $bank,
        public readonly BankStatus $oldStatus,
        public readonly BankStatus $newStatus,
        public readonly int $oldStatusCode,
        public readonly int $newStatusCode,
    ) {}
}
