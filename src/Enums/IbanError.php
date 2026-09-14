<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Enums;

/**
 * Reason why a string is not a valid Ukrainian IBAN.
 */
enum IbanError: string
{
    /** Contains characters that are not allowed or does not look like an IBAN at all. */
    case InvalidFormat = 'invalid_format';

    /** Looks like an IBAN, but the country code is not "UA". */
    case WrongCountry = 'wrong_country';

    /** UA IBAN, but not exactly 29 characters long. */
    case WrongLength = 'wrong_length';

    /** ISO 13616 MOD 97-10 check failed. */
    case ChecksumMismatch = 'checksum_mismatch';

    public function message(): string
    {
        return match ($this) {
            self::InvalidFormat => 'The IBAN has an invalid format.',
            self::WrongCountry => 'The IBAN is not a Ukrainian (UA) IBAN.',
            self::WrongLength => 'A Ukrainian IBAN must be exactly 29 characters long.',
            self::ChecksumMismatch => 'The IBAN check digits are invalid.',
        };
    }
}
