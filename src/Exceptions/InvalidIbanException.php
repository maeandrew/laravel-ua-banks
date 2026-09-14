<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Exceptions;

use InvalidArgumentException;
use Maeandrew\UaBanks\Enums\IbanError;

final class InvalidIbanException extends InvalidArgumentException implements UaBanksException
{
    public function __construct(
        public readonly IbanError $reason,
        public readonly string $input,
    ) {
        parent::__construct($reason->message());
    }

    public function reason(): IbanError
    {
        return $this->reason;
    }
}
