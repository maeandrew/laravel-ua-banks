<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Testing;

use Maeandrew\UaBanks\Iban\Iban;

/**
 * Generates syntactically valid (synthetic) Ukrainian IBANs for tests.
 *
 * Generated IBANs have correct check digits, but the account numbers are random
 * and do not belong to anyone.
 */
final class IbanFactory
{
    public static function make(string|int $mfo = '300465', ?string $account = null): Iban
    {
        return Iban::generate(
            str_pad((string) $mfo, 6, '0', STR_PAD_LEFT),
            $account ?? self::randomAccount(),
        );
    }

    public static function string(string|int $mfo = '300465', ?string $account = null): string
    {
        return self::make($mfo, $account)->value();
    }

    /**
     * An IBAN with the correct structure but wrong check digits.
     */
    public static function withInvalidChecksum(string|int $mfo = '300465', ?string $account = null): string
    {
        $valid = self::make($mfo, $account)->value();
        $digits = (int) substr($valid, 2, 2);
        $wrong = str_pad((string) ($digits === 98 ? 97 : $digits + 1), 2, '0', STR_PAD_LEFT);

        return 'UA'.$wrong.substr($valid, 4);
    }

    private static function randomAccount(): string
    {
        $account = '';

        for ($i = 0; $i < 19; $i++) {
            $account .= (string) random_int(0, 9);
        }

        return $account;
    }
}
