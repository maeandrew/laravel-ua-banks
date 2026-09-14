<?php

use Carbon\CarbonImmutable;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Sync\RecordMapper;
use Maeandrew\UaBanks\Tests\TestCase;

function fixtureBank(int $mfo): Bank
{
    $record = collect(TestCase::fixture('typ0.json'))->firstWhere('MFO', $mfo);

    return (new RecordMapper)->toBank($record, CarbonImmutable::parse('2026-09-14T10:00:00Z'));
}

it('round-trips through toArray / fromArray', function () {
    $bank = fixtureBank(300012);
    $copy = Bank::fromArray($bank->toArray());

    expect($copy)->toEqual($bank)
        ->and($copy->toArray())->toBe($bank->toArray())
        ->and(json_encode($bank))->toBe(json_encode($bank->toArray()));
});

it('exposes status helpers', function () {
    expect(fixtureBank(300465)->isOperating())->toBeTrue()
        ->and(fixtureBank(300465)->isInLiquidation())->toBeFalse()
        ->and(fixtureBank(300012)->isOperating())->toBeFalse()
        ->and(fixtureBank(300012)->isInLiquidation())->toBeTrue()
        ->and(fixtureBank(300012)->statusSince?->format('d.m.Y'))->toBe('25.02.2022');
});

it('serializes dates in ISO formats', function () {
    $array = fixtureBank(300012)->toArray();

    expect($array['status_since'])->toBe('2022-02-25')
        ->and($array['synced_at'])->toBe('2026-09-14T10:00:00+00:00')
        ->and($array['removed_from_source_at'])->toBeNull()
        ->and($array['edrpou'])->toBe('00039002');
});

it('translates status labels', function () {
    app()->setLocale('uk');
    expect(BankStatus::Liquidation->label())->toBe('Ліквідація')
        ->and(BankStatus::Normal->label())->toBe('Нормальний');

    app()->setLocale('en');
    expect(BankStatus::Liquidation->label())->toBe('Liquidation')
        ->and(BankStatus::Suspended->label('uk'))->toBe('Тимчасово призупинено діяльність');
});

it('maps codes to statuses', function () {
    expect(BankStatus::fromCode(1))->toBe(BankStatus::Normal)
        ->and(BankStatus::fromCode(3))->toBe(BankStatus::ExcludedFromRegister)
        ->and(BankStatus::fromCode(2))->toBe(BankStatus::Unknown)
        ->and(BankStatus::fromCode(99))->toBe(BankStatus::Unknown);
});
