<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Maeandrew\UaBanks\UaBanksManager;

/**
 * Validates a 6-digit MFO that exists in the registry (regional directorate aliases included).
 */
final class UaMfo implements ValidationRule
{
    use ChecksRegistry;

    private bool $operating = false;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Require the bank to be in one of the `validation.allowed_statuses` (Normal by default).
     */
    public function operating(bool $operating = true): self
    {
        $this->operating = $operating;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^\d{6}$/', $value) !== 1) {
            $fail('ua-banks::validation.mfo_format')->translate();

            return;
        }

        $manager = app(UaBanksManager::class);

        if (! $manager->hasData()) {
            if ($this->failsOnMissingRegistry()) {
                $fail('ua-banks::validation.registry_missing')->translate();
            }

            return;
        }

        $bank = $manager->byMfo($value);

        if ($bank === null) {
            $fail('ua-banks::validation.mfo_unknown')->translate(['mfo' => $value]);

            return;
        }

        if ($this->operating && ! in_array($bank->status, $this->allowedStatuses(), true)) {
            $fail('ua-banks::validation.mfo_bank_not_operating')->translate([
                'bank' => $bank->shortName,
                'status' => $this->statusLabel($bank),
                'mfo' => $bank->mfo,
            ]);
        }
    }
}
