<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Enums\IbanError;
use Maeandrew\UaBanks\Iban\Iban;
use Maeandrew\UaBanks\UaBanksManager;

/**
 * Validates a Ukrainian IBAN: format, check digits, and (by default) that the bank exists
 * in the local registry and is operating. Never performs network requests.
 */
final class UaIban implements ValidationRule
{
    use ChecksRegistry;

    private bool $allowUnknownBank = false;

    /** @var list<BankStatus> */
    private array $extraStatuses = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Only check the format and check digits; skip the registry lookup.
     */
    public function allowUnknownBank(bool $allow = true): self
    {
        $this->allowUnknownBank = $allow;

        return $this;
    }

    /**
     * Additionally accept banks in the given statuses (on top of `validation.allowed_statuses`).
     */
    public function allowStatuses(BankStatus ...$statuses): self
    {
        foreach ($statuses as $status) {
            if (! in_array($status, $this->extraStatuses, true)) {
                $this->extraStatuses[] = $status;
            }
        }

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('ua-banks::validation.iban_format')->translate();

            return;
        }

        $error = Iban::validate($value);

        if ($error !== null) {
            $key = $error === IbanError::ChecksumMismatch ? 'iban_checksum' : 'iban_format';
            $fail("ua-banks::validation.{$key}")->translate();

            return;
        }

        if ($this->allowUnknownBank) {
            return;
        }

        $manager = $this->manager();
        $mfo = Iban::parse($value)->mfo();

        if (! $manager->hasData()) {
            if ($this->failsOnMissingRegistry()) {
                $fail('ua-banks::validation.registry_missing')->translate();
            }

            return;
        }

        $bank = $manager->byMfo($mfo);

        if ($bank === null) {
            $fail('ua-banks::validation.iban_unknown_bank')->translate(['mfo' => $mfo]);

            return;
        }

        if (! in_array($this->effectiveStatus($bank), $this->allowedStatuses($this->extraStatuses), true)) {
            $fail('ua-banks::validation.iban_bank_not_operating')->translate([
                'bank' => $bank->shortName,
                'status' => $this->statusLabel($bank),
                'mfo' => $bank->mfo,
            ]);
        }
    }

    private function manager(): UaBanksManager
    {
        return app(UaBanksManager::class);
    }
}
