<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Exceptions;

use RuntimeException;

final class BankNotFoundException extends RuntimeException implements UaBanksException
{
    public function __construct(public readonly string $mfo)
    {
        parent::__construct(sprintf('No bank found for MFO "%s".', $mfo));
    }
}
