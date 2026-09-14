<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Repositories\SnapshotFile;
use Maeandrew\UaBanks\Rules\UaIban;
use Maeandrew\UaBanks\Testing\IbanFactory;
use Maeandrew\UaBanks\UaBanksManager;

/*
 * Definition of Done: in a fresh application with zero configuration (no sync, no migrations,
 * no network) the bundled snapshot answers lookups and the UaIban rule works.
 */

beforeEach(function () {
    // Restore the package defaults that the base TestCase overrides.
    config(['ua-banks' => require __DIR__.'/../../config/ua-banks.php']);
    config(['ua-banks.snapshot.path' => $this->tempDir.'/storage/app/ua-banks/banks.json']);
    app(UaBanksManager::class)->forgetRepositories();
    app()->setLocale('en');
    Http::fake();
});

it('ships a bundled snapshot generated from real NBU data under 150 KB', function () {
    $data = SnapshotFile::read(UaBanksManager::BUNDLED_SNAPSHOT);

    expect(filesize(UaBanksManager::BUNDLED_SNAPSHOT))->toBeLessThan(150 * 1024)
        ->and($data['schema'])->toBe(1)
        ->and($data['source'])->toContain('bank.gov.ua')
        ->and(count($data['banks']))->toBeGreaterThanOrEqual(50)
        ->and($data['aliases'])->not->toBeEmpty();
});

it('finds Oschadbank without any configuration', function () {
    $bank = UaBanks::byMfo('300465');

    expect(config('ua-banks.snapshot.path'))->not->toBeFile()
        ->and($bank)->not->toBeNull()
        ->and($bank->shortName)->toBe('АТ "Ощадбанк"')
        ->and($bank->edrpou)->toBe('00032129')
        ->and($bank->isOperating())->toBeTrue()
        ->and(UaBanks::byMfo('305299')?->edrpou)->toBe('14360570')
        ->and(UaBanks::byMfo('322001')?->edrpou)->toBe('21133352');
});

it('rejects a generated IBAN of the bank in liquidation (300012)', function () {
    $validator = Validator::make(['iban' => IbanFactory::string('300012')], ['iban' => ['required', UaIban::make()]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('iban'))->toContain('Промінвестбанк')
        ->and($validator->errors()->first('iban'))->toContain('Liquidation');

    app()->setLocale('uk');
    $validator = Validator::make(['iban' => IbanFactory::string('300012')], ['iban' => 'required|ua_iban']);

    expect($validator->errors()->first('iban'))->toContain('Промінвестбанк')->toContain('Ліквідація');
});

it('accepts a generated IBAN of an operating bank', function () {
    expect(Validator::make(['iban' => IbanFactory::string('305299')], ['iban' => [UaIban::make()]])->passes())->toBeTrue();

    Http::assertNothingSent();
});
