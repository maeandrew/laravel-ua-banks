<?php

use Carbon\CarbonImmutable;
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Sync\RecordMapper;
use Maeandrew\UaBanks\Tests\TestCase;

function oschadRecord(array $overrides = []): array
{
    $record = collect(TestCase::fixture('typ0.json'))->firstWhere('MFO', 300465);

    return array_merge($record, $overrides);
}

beforeEach(function () {
    $this->mapper = new RecordMapper;
    $this->now = CarbonImmutable::parse('2026-09-14 10:00:00', 'UTC');
});

it('maps a real NBU record', function () {
    $bank = $this->mapper->toBank(oschadRecord(), $this->now);

    expect($bank->mfo)->toBe('300465')
        ->and($bank->edrpou)->toBe('00032129')
        ->and($bank->shortName)->toBe('АТ "Ощадбанк"')
        ->and($bank->nameEn)->not->toBeNull()
        ->and($bank->status)->toBe(BankStatus::Normal)
        ->and($bank->statusCode)->toBe(1)
        ->and($bank->statusName)->toBe('Нормальний')
        ->and($bank->city)->toBe('Київ')
        ->and($bank->syncedAt->equalTo($this->now))->toBeTrue()
        ->and($this->mapper->warnings())->toBe([]);
});

it('converts numeric MFO to a 6-digit string', function (mixed $input, ?string $expected) {
    expect(RecordMapper::normalizeMfo($input))->toBe($expected);
})->with([
    [300465, '300465'],
    [12345, '012345'],
    [0, '000000'],
    ['300465', '300465'],
    [' 12 ', '000012'],
    [1234567, null],
    [-1, null],
    ['30a465', null],
    [null, null],
    [300465.0, null],
]);

it('keeps EDRPOU leading zeros', function () {
    expect($this->mapper->toBank(oschadRecord(['KOD_EDRPOU' => '00032129']), $this->now)->edrpou)->toBe('00032129')
        ->and($this->mapper->toBank(oschadRecord(['KOD_EDRPOU' => 32129]), $this->now)->edrpou)->toBe('00032129');
});

it('parses dd.mm.yyyy dates as Kyiv dates without time', function () {
    $bank = $this->mapper->toBank(oschadRecord(['D_OPEN' => '31.12.1991', 'D_STAN' => '25.02.2022', 'D_CLOSE' => null]), $this->now);

    expect($bank->openedAt?->format('Y-m-d H:i:s'))->toBe('1991-12-31 00:00:00')
        ->and($bank->openedAt?->timezoneName)->toBe('Europe/Kyiv')
        ->and($bank->statusSince?->format('Y-m-d'))->toBe('2022-02-25')
        ->and($bank->closedAt)->toBeNull();
});

it('turns malformed dates into null with a warning', function (mixed $date) {
    $bank = $this->mapper->toBank(oschadRecord(['D_OPEN' => $date]), $this->now);

    expect($bank->openedAt)->toBeNull()
        ->and($this->mapper->warnings())->toHaveCount(1)
        ->and($this->mapper->warnings()[0])->toContain('D_OPEN');
})->with(['31.02.2020', '2020-01-01', '1.1.2020', 'yesterday', 20200101]);

it('maps null and empty optional fields to null', function () {
    $bank = $this->mapper->toBank(oschadRecord(['NAME_E' => null, 'NP' => '', 'ADRESS' => '   ', 'D_STAN' => '']), $this->now);

    expect($bank->nameEn)->toBeNull()
        ->and($bank->city)->toBeNull()
        ->and($bank->address)->toBeNull()
        ->and($bank->statusSince)->toBeNull()
        ->and($this->mapper->warnings())->toBe([]);
});

it('maps unknown KSTAN codes to Unknown and keeps raw code and name', function () {
    $bank = $this->mapper->toBank(oschadRecord(['KSTAN' => 7, 'N_STAN' => 'Новий стан']), $this->now);

    expect($bank->status)->toBe(BankStatus::Unknown)
        ->and($bank->statusCode)->toBe(7)
        ->and($bank->statusName)->toBe('Новий стан')
        ->and($bank->isOperating())->toBeFalse()
        ->and($this->mapper->warnings()[0])->toContain('unknown KSTAN code 7');
});

it('maps known KSTAN codes', function (int $code, BankStatus $status) {
    expect($this->mapper->toBank(oschadRecord(['KSTAN' => $code]), $this->now)->status)->toBe($status);
})->with([
    [1, BankStatus::Normal],
    [3, BankStatus::ExcludedFromRegister],
    [4, BankStatus::Liquidation],
    [5, BankStatus::Suspended],
]);

it('builds a registry with aliases pointing to head offices', function () {
    $registry = $this->mapper->toRegistry(TestCase::fixture('typ0.json'), TestCase::fixture('typ1.json'), $this->now);

    expect($registry->banks)->toHaveCount(10)
        ->and(array_keys($registry->banks))->toBe(collect(array_keys($registry->banks))->sort()->values()->all())
        ->and($registry->aliases)->toBe(['302076' => '300465', '303398' => '300465', '380269' => '305299'])
        ->and($registry->raw['300465']['GLMFO'])->toBe(300465);
});

it('ignores aliases pointing to unknown banks', function () {
    $typ1 = TestCase::fixture('typ1.json');
    $typ1[0]['GLMFO'] = 399999;

    $registry = $this->mapper->toRegistry(TestCase::fixture('typ0.json'), $typ1, $this->now);

    expect($registry->aliases)->toHaveCount(2)
        ->and($registry->warnings[0])->toContain('unknown head office 399999');
});
