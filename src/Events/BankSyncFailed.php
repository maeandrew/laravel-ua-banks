<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Events;

use Throwable;

final class BankSyncFailed
{
    public function __construct(
        public readonly Throwable $exception,
        public readonly string $driver,
    ) {}
}
