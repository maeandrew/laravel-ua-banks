<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Enums;

/**
 * Bank state as published by the NBU in the KSTAN field.
 */
enum BankStatus: int
{
    /** A KSTAN code this package does not know yet. The raw code and name are kept on the Bank. */
    case Unknown = 0;

    case Normal = 1;

    case ExcludedFromRegister = 3;

    case Liquidation = 4;

    case Suspended = 5;

    public static function fromCode(int $code): self
    {
        return $code === self::Unknown->value ? self::Unknown : (self::tryFrom($code) ?? self::Unknown);
    }

    public function translationKey(): string
    {
        return 'ua-banks::statuses.'.match ($this) {
            self::Unknown => 'unknown',
            self::Normal => 'normal',
            self::ExcludedFromRegister => 'excluded_from_register',
            self::Liquidation => 'liquidation',
            self::Suspended => 'suspended',
        };
    }

    public function label(?string $locale = null): string
    {
        $label = __($this->translationKey(), [], $locale);

        return is_string($label) ? $label : $this->name;
    }
}
