<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Iban;

use InvalidArgumentException;
use Maeandrew\UaBanks\Enums\IbanError;
use Maeandrew\UaBanks\Exceptions\InvalidIbanException;
use Maeandrew\UaBanks\Testing\IbanFactory;
use Stringable;

/**
 * Ukrainian IBAN value object: UA + 2 check digits + 6-digit MFO + 19-digit account number.
 *
 * Framework-agnostic; has no Laravel dependencies.
 */
final class Iban implements Stringable
{
    public const string COUNTRY = 'UA';

    public const int LENGTH = 29;

    private function __construct(private readonly string $value) {}

    /**
     * @throws InvalidIbanException
     */
    public static function parse(string $input): self
    {
        $value = self::normalize($input);
        $error = self::detectError($value);

        if ($error !== null) {
            throw new InvalidIbanException($error, $input);
        }

        return new self($value);
    }

    public static function tryParse(string $input): ?self
    {
        $value = self::normalize($input);

        return self::detectError($value) === null ? new self($value) : null;
    }

    /**
     * Returns the first validation error for the input, or null when it is a valid UA IBAN.
     */
    public static function validate(string $input): ?IbanError
    {
        return self::detectError(self::normalize($input));
    }

    /**
     * Builds a valid IBAN for the given MFO and account number by computing the check digits.
     *
     * @internal Test helper. Use {@see IbanFactory} in application tests.
     */
    public static function generate(string $mfo, string $account): self
    {
        if (preg_match('/^\d{6}$/', $mfo) !== 1) {
            throw new InvalidArgumentException('MFO must be exactly 6 digits.');
        }

        if (preg_match('/^\d{1,19}$/', $account) !== 1) {
            throw new InvalidArgumentException('Account number must be 1 to 19 digits.');
        }

        $bban = $mfo.str_pad($account, 19, '0', STR_PAD_LEFT);
        $checkDigits = 98 - self::mod97($bban.self::COUNTRY.'00');

        return new self(self::COUNTRY.str_pad((string) $checkDigits, 2, '0', STR_PAD_LEFT).$bban);
    }

    public static function normalize(string $input): string
    {
        return strtoupper(str_replace([' ', '-', "\u{00A0}", "\u{202F}", "\t", "\r", "\n"], '', $input));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function checkDigits(): string
    {
        return substr($this->value, 2, 2);
    }

    /**
     * The 6-digit bank code (MFO), characters 5–10 of the IBAN.
     */
    public function mfo(): string
    {
        return substr($this->value, 4, 6);
    }

    /**
     * The 19-digit account number (last 19 characters).
     */
    public function accountNumber(): string
    {
        return substr($this->value, -19);
    }

    /**
     * Human readable form, e.g. "UA21 3004 6500 0000 ...".
     */
    public function formatted(): string
    {
        return implode(' ', str_split($this->value, 4));
    }

    public function equals(self|string $other): bool
    {
        return $this->value === ($other instanceof self ? $other->value : self::normalize($other));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function detectError(string $value): ?IbanError
    {
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $value) !== 1) {
            return IbanError::InvalidFormat;
        }

        if (! str_starts_with($value, self::COUNTRY)) {
            return IbanError::WrongCountry;
        }

        if (strlen($value) !== self::LENGTH) {
            return IbanError::WrongLength;
        }

        if (preg_match('/^UA\d{27}$/', $value) !== 1) {
            return IbanError::InvalidFormat;
        }

        if (self::mod97(substr($value, 4).substr($value, 0, 4)) !== 1) {
            return IbanError::ChecksumMismatch;
        }

        return null;
    }

    /**
     * ISO 7064 MOD 97-10 remainder computed piecewise, so neither bcmath nor gmp is required.
     */
    private static function mod97(string $alphanumeric): int
    {
        $digits = '';

        foreach (str_split($alphanumeric) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
