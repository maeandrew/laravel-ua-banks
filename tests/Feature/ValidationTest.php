<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Repositories\SnapshotBankRepository;
use Maeandrew\UaBanks\Rules\UaIban;
use Maeandrew\UaBanks\Rules\UaMfo;
use Maeandrew\UaBanks\Testing\IbanFactory;
use Maeandrew\UaBanks\Tests\TestCase;
use Maeandrew\UaBanks\UaBanksManager;

function validateIban(mixed $value, mixed $rule = null): Illuminate\Contracts\Validation\Validator
{
    return Validator::make(['iban' => $value], ['iban' => ['required', $rule ?? UaIban::make()]]);
}

function useEmptyRegistry(): void
{
    UaBanks::extend('empty', fn () => new SnapshotBankRepository(sys_get_temp_dir().'/nope-a.json', sys_get_temp_dir().'/nope-b.json'));
    config(['ua-banks.driver' => 'empty']);
}

beforeEach(function () {
    // Deterministic registry: the test fixtures, via the snapshot driver storage file.
    app(UaBanksManager::class)->repository()->store(fixtureRegistry());
    app()->setLocale('en');
});

it('passes for an operating bank', function (string $mfo) {
    expect(validateIban(IbanFactory::string($mfo))->passes())->toBeTrue()
        ->and(validateIban(IbanFactory::make($mfo)->formatted())->passes())->toBeTrue();
})->with(['300465', '305299', '322001', 'alias of Oschadbank' => '302076']);

it('rejects bad formats', function (mixed $value) {
    $validator = validateIban($value);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('iban'))->toBe('The iban must be a valid Ukrainian IBAN (UA followed by 27 digits).');
})->with([
    'garbage' => 'hello',
    'other country' => 'DE89370400440532013000',
    'too short' => 'UA2130046500000000000000000',
    'array' => [['UA']],
    'integer' => 12345,
]);

it('rejects a wrong checksum', function () {
    $validator = validateIban(IbanFactory::withInvalidChecksum('300465'));

    expect($validator->errors()->first('iban'))->toBe('The iban has invalid IBAN check digits.');
});

it('rejects an unknown bank', function () {
    $validator = validateIban(IbanFactory::string('399999'));

    expect($validator->errors()->first('iban'))->toBe('The iban belongs to an unknown bank (MFO 399999).');
});

it('rejects a bank that is not operating with bank and status names', function () {
    $validator = validateIban(IbanFactory::string('300012'));

    expect($validator->errors()->first('iban'))
        ->toBe('The iban belongs to ПАТ "Промінвестбанк", which is not operating (status: Liquidation).');
});

it('translates messages to Ukrainian', function () {
    app()->setLocale('uk');

    expect(validateIban(IbanFactory::string('300012'))->errors()->first('iban'))
        ->toBe('Поле iban містить IBAN банку ПАТ "Промінвестбанк", який не працює (стан: Ліквідація).')
        ->and(validateIban('abc')->errors()->first('iban'))->toBe('Поле iban має бути коректним українським IBAN (UA і 27 цифр).')
        ->and(validateIban(IbanFactory::withInvalidChecksum())->errors()->first('iban'))->toBe('Поле iban містить IBAN з неправильними контрольними цифрами.')
        ->and(validateIban(IbanFactory::string('399999'))->errors()->first('iban'))->toBe('Поле iban містить IBAN невідомого банку (МФО 399999).');
});

it('uses the raw NBU status name for unknown statuses', function () {
    $typ0 = TestCase::fixture('typ0.json');
    foreach ($typ0 as &$record) {
        if ($record['MFO'] === 300119) {
            [$record['KSTAN'], $record['N_STAN']] = [8, 'Якийсь новий стан'];
        }
    }
    unset($record);
    app(UaBanksManager::class)->repository()->store(fixtureRegistry(null, $typ0));

    expect(validateIban(IbanFactory::string('300119'))->errors()->first('iban'))->toContain('(status: Якийсь новий стан)');
});

it('skips the registry with allowUnknownBank', function () {
    $rule = fn () => UaIban::make()->allowUnknownBank();

    expect(validateIban(IbanFactory::string('399999'), $rule())->passes())->toBeTrue()
        ->and(validateIban(IbanFactory::string('300012'), $rule())->passes())->toBeTrue()
        ->and(validateIban(IbanFactory::withInvalidChecksum(), $rule())->errors()->first('iban'))->toBe('The iban has invalid IBAN check digits.');
});

it('accepts additional statuses with allowStatuses', function () {
    expect(validateIban(IbanFactory::string('300012'), UaIban::make()->allowStatuses(BankStatus::Liquidation))->passes())->toBeTrue()
        ->and(validateIban(IbanFactory::string('300465'), UaIban::make()->allowStatuses(BankStatus::Liquidation))->passes())->toBeTrue()
        ->and(validateIban(IbanFactory::string('300012'), UaIban::make()->allowStatuses(BankStatus::Suspended))->fails())->toBeTrue();
});

it('respects validation.allowed_statuses from config', function () {
    config(['ua-banks.validation.allowed_statuses' => [BankStatus::Normal, 4]]);

    expect(validateIban(IbanFactory::string('300012'))->passes())->toBeTrue();
});

it('passes format-valid IBANs when the registry is missing and on_missing_registry = pass', function () {
    useEmptyRegistry();

    expect(validateIban(IbanFactory::string('399999'))->passes())->toBeTrue()
        ->and(validateIban(IbanFactory::withInvalidChecksum())->fails())->toBeTrue();
});

it('fails when the registry is missing and on_missing_registry = fail', function () {
    useEmptyRegistry();
    config(['ua-banks.validation.on_missing_registry' => 'fail']);

    expect(validateIban(IbanFactory::string('300465'))->errors()->first('iban'))
        ->toBe('The iban cannot be verified because the bank registry is not available.')
        ->and(validateIban(IbanFactory::string('300465'), UaIban::make()->allowUnknownBank())->passes())->toBeTrue();
});

it('provides the ua_iban string rule with the same defaults', function () {
    $validate = fn (string $value) => Validator::make(['account' => $value], ['account' => 'required|ua_iban']);

    expect($validate(IbanFactory::string('300465'))->passes())->toBeTrue()
        ->and($validate('nope')->errors()->first('account'))->toBe('The account must be a valid Ukrainian IBAN (UA followed by 27 digits).')
        ->and($validate(IbanFactory::withInvalidChecksum())->errors()->first('account'))->toBe('The account has invalid IBAN check digits.')
        ->and($validate(IbanFactory::string('399999'))->errors()->first('account'))->toBe('The account belongs to an unknown bank (MFO 399999).')
        ->and($validate(IbanFactory::string('300012'))->errors()->first('account'))
        ->toBe('The account belongs to ПАТ "Промінвестбанк", which is not operating (status: Liquidation).');
});

it('lets custom messages override the ua_iban message', function () {
    $validator = Validator::make(['iban' => 'nope'], ['iban' => 'ua_iban'], ['iban.ua_iban' => 'Custom :attribute']);

    expect($validator->errors()->first('iban'))->toBe('Custom iban');
});

it('uses custom attribute names', function () {
    $validator = Validator::make(['iban' => 'nope'], ['iban' => [UaIban::make()]], [], ['iban' => 'рахунок']);

    expect($validator->errors()->first('iban'))->toBe('The рахунок must be a valid Ukrainian IBAN (UA followed by 27 digits).');
});

it('validates MFO codes', function () {
    $validate = fn (mixed $value, ?UaMfo $rule = null) => Validator::make(['mfo' => $value], ['mfo' => [$rule ?? UaMfo::make()]]);

    expect($validate('300465')->passes())->toBeTrue()
        ->and($validate('303398')->passes())->toBeTrue()
        ->and($validate(300465)->passes())->toBeTrue()
        ->and($validate('300012')->passes())->toBeTrue()
        ->and($validate('300012', UaMfo::make()->operating())->errors()->first('mfo'))
        ->toBe('The mfo belongs to ПАТ "Промінвестбанк", which is not operating (status: Liquidation).')
        ->and($validate('30046')->errors()->first('mfo'))->toBe('The mfo must be a 6-digit MFO bank code.')
        ->and($validate('399999')->errors()->first('mfo'))->toBe('The mfo is not a known bank MFO.')
        ->and(Validator::make(['mfo' => '399999'], ['mfo' => 'ua_mfo'])->errors()->first('mfo'))->toBe('The mfo is not a known bank MFO.');
});

it('validates MFO codes without a registry according to on_missing_registry', function () {
    useEmptyRegistry();

    expect(Validator::make(['mfo' => '399999'], ['mfo' => [UaMfo::make()]])->passes())->toBeTrue();

    config(['ua-banks.validation.on_missing_registry' => 'fail']);

    expect(Validator::make(['mfo' => '399999'], ['mfo' => [UaMfo::make()]])->fails())->toBeTrue();
});

it('never performs network requests', function () {
    Http::fake();

    validateIban(IbanFactory::string('300465'))->passes();
    validateIban(IbanFactory::string('399999'))->passes();

    Http::assertNothingSent();
});
