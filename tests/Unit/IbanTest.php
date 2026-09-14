<?php

use Maeandrew\UaBanks\Enums\IbanError;
use Maeandrew\UaBanks\Exceptions\InvalidIbanException;
use Maeandrew\UaBanks\Iban\Iban;
use Maeandrew\UaBanks\Testing\IbanFactory;

/**
 * Finds an account number for which the generated IBAN has the requested check digits.
 */
function ibanWithCheckDigits(string $mfo, string $digits): Iban
{
    for ($i = 1; $i < 100_000; $i++) {
        $iban = Iban::generate($mfo, (string) $i);

        if ($iban->checkDigits() === $digits) {
            return $iban;
        }
    }

    throw new RuntimeException("No account found for check digits {$digits}.");
}

it('generates IBANs that parse back (round trip)', function (string $mfo, string $account) {
    $generated = Iban::generate($mfo, $account);
    $parsed = Iban::parse($generated->value());

    expect($parsed->value())->toBe($generated->value())
        ->and($parsed->mfo())->toBe($mfo)
        ->and($parsed->accountNumber())->toBe(str_pad($account, 19, '0', STR_PAD_LEFT))
        ->and(strlen($parsed->value()))->toBe(29)
        ->and((string) $parsed)->toBe($generated->value());
})->with([
    ['300465', '1'],
    ['305299', '2600012345678'],
    ['322001', '9999999999999999999'],
    ['300012', '0000000000000000000'],
]);

it('validates the check digits with a manual modulo on string chunks', function () {
    $iban = IbanFactory::make('300465', '26001234567890');
    $numeric = substr($iban->value(), 4).'3010'.$iban->checkDigits();
    $remainder = 0;

    foreach (str_split($numeric) as $digit) {
        $remainder = ($remainder * 10 + (int) $digit) % 97;
    }

    expect($remainder)->toBe(1);
});

it('normalizes spaces, dashes, NBSP and lowercase', function () {
    $iban = IbanFactory::make('300465', '1234567');
    $messy = ' '.strtolower(implode("\u{00A0}", str_split(substr($iban->value(), 0, 12), 4))).'-'.implode(' ', str_split(substr($iban->value(), 12), 3)).' ';

    expect(Iban::parse($messy)->value())->toBe($iban->value())
        ->and(Iban::normalize('ua 12-34'))->toBe('UA1234');
});

it('formats in groups of four', function () {
    $iban = Iban::generate('300465', '1');

    expect($iban->formatted())->toMatch('/^UA\d{2} 3004 65\d{2} (\d{4} ){4}\d$/')
        ->and(str_replace(' ', '', $iban->formatted()))->toBe($iban->value());
});

it('reports the reason for invalid input', function (string $input, IbanError $reason) {
    expect(Iban::validate($input))->toBe($reason)
        ->and(Iban::tryParse($input))->toBeNull();

    try {
        Iban::parse($input);
        $this->fail('Expected InvalidIbanException');
    } catch (InvalidIbanException $e) {
        expect($e->reason())->toBe($reason)
            ->and($e->input)->toBe($input)
            ->and($e->getMessage())->toBe($reason->message());
    }
})->with([
    'empty' => ['', IbanError::InvalidFormat],
    'garbage' => ['not an iban', IbanError::InvalidFormat],
    'special characters' => ['UA21#004650000000000000000000', IbanError::InvalidFormat],
    'no check digits' => ['UAXX3004650000000000000000000', IbanError::InvalidFormat],
    'letters inside UA BBAN' => ['UA213004650000000000000000A00', IbanError::InvalidFormat],
    'other country' => ['DE89370400440532013000', IbanError::WrongCountry],
    'too short (28)' => ['UA21300465000000000000000000', IbanError::WrongLength],
    'too long (30)' => ['UA2130046500000000000000000000', IbanError::WrongLength],
    'checksum' => [substr_replace(Iban::generate('300465', '42')->value(), '0', -1), IbanError::ChecksumMismatch],
]);

it('rejects the impossible check digits 00 and 01', function (string $digits) {
    $valid = Iban::generate('300465', '26000000000001')->value();
    $input = 'UA'.$digits.substr($valid, 4);

    expect(Iban::validate($input))->toBe(IbanError::ChecksumMismatch);
})->with(['00', '01']);

it('accepts the boundary check digits 02 and 98', function (string $digits) {
    $iban = ibanWithCheckDigits('300465', $digits);

    expect(Iban::parse($iban->value())->checkDigits())->toBe($digits);
})->with(['02', '98']);

it('accepts exactly 29 characters only', function () {
    $valid = Iban::generate('322001', '5')->value();

    expect(Iban::validate($valid))->toBeNull()
        ->and(Iban::validate(substr($valid, 0, 28)))->toBe(IbanError::WrongLength)
        ->and(Iban::validate($valid.'0'))->toBe(IbanError::WrongLength);
});

it('compares IBANs', function () {
    $iban = Iban::generate('300465', '77');

    expect($iban->equals(strtolower($iban->formatted())))->toBeTrue()
        ->and($iban->equals(Iban::generate('300465', '78')))->toBeFalse();
});

it('rejects invalid generator input', function (string $mfo, string $account) {
    Iban::generate($mfo, $account);
})->with([
    ['30046', '1'],
    ['3004650', '1'],
    ['300465', ''],
    ['300465', '12345678901234567890'],
    ['300465', '12a'],
])->throws(InvalidArgumentException::class);

it('provides a factory for consumers', function () {
    $iban = IbanFactory::make(305299);

    expect($iban->mfo())->toBe('305299')
        ->and(Iban::validate(IbanFactory::string()))->toBeNull()
        ->and(IbanFactory::make()->mfo())->toBe('300465')
        ->and(Iban::validate(IbanFactory::withInvalidChecksum('322001')))->toBe(IbanError::ChecksumMismatch);
});
