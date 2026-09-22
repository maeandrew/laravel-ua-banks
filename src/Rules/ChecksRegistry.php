<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Rules;

use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Enums\BankStatus;

/**
 * @internal Shared configuration helpers for the validation rules.
 */
trait ChecksRegistry
{
    private function failsOnMissingRegistry(): bool
    {
        return config('ua-banks.validation.on_missing_registry', 'pass') === 'fail';
    }

    /**
     * @param  list<BankStatus>  $extra
     * @return list<BankStatus>
     */
    private function allowedStatuses(array $extra = []): array
    {
        $configured = config('ua-banks.validation.allowed_statuses', [BankStatus::Normal]);
        $statuses = [];

        foreach (is_array($configured) ? $configured : [$configured] as $status) {
            $status = match (true) {
                $status instanceof BankStatus => $status,
                is_int($status) || (is_string($status) && ctype_digit($status)) => BankStatus::tryFrom((int) $status),
                default => null,
            };

            if ($status !== null) {
                $statuses[] = $status;
            }
        }

        return array_values(array_unique([...$statuses, ...$extra], SORT_REGULAR));
    }

    private function effectiveStatus(Bank $bank): BankStatus
    {
        if ($bank->isRemovedFromSource() && $bank->status === BankStatus::Normal) {
            return BankStatus::ExcludedFromRegister;
        }

        return $bank->status;
    }

    private function statusLabel(Bank $bank): string
    {
        $status = $this->effectiveStatus($bank);

        return $status === BankStatus::Unknown && $bank->statusName !== ''
            ? $bank->statusName
            : $status->label();
    }
}
