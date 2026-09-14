<?php

use Illuminate\Support\Facades\Http;
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Sync\NbuClient;
use Maeandrew\UaBanks\Sync\Synchronizer;

/*
 * Hits the real NBU API. Excluded by default; run with `composer test:live`.
 */

beforeEach(function () {
    Http::allowStrayRequests();
    config([
        'ua-banks.sync.min_banks' => 50,
        'ua-banks.source.retries' => 3,
        'ua-banks.source.backoff' => [1, 3, 9],
    ]);
});

it('downloads and validates the real registry', function () {
    $registry = app(Synchronizer::class)->fetch();

    expect(count($registry->banks))->toBeGreaterThanOrEqual(50)
        ->and($registry->aliases)->not->toBeEmpty()
        ->and($registry->banks['300465']->edrpou)->toBe('00032129')
        ->and($registry->banks['305299']->edrpou)->toBe('14360570')
        ->and($registry->banks['322001']->edrpou)->toBe('21133352')
        ->and($registry->banks['300012']->status)->toBe(BankStatus::Liquidation);

    fwrite(STDERR, sprintf(
        "\n[live] head offices: %d, aliases: %d, warnings: %d\n",
        count($registry->banks),
        count($registry->aliases),
        count($registry->warnings),
    ));
})->group('live');

it('returns JSON arrays for both record types', function () {
    $client = app(NbuClient::class);

    expect($client->fetch(NbuClient::TYPE_HEAD_OFFICE))->toBeArray()->not->toBeEmpty()
        ->and($client->fetch(NbuClient::TYPE_REGIONAL_DIRECTORATE))->toBeArray()->not->toBeEmpty();
})->group('live');

it('synchronizes both drivers idempotently', function (string $driver) {
    $this->useDriver($driver);

    $this->artisan('ua-banks:sync')->assertSuccessful();
    expect(UaBanks::byMfo('300465')?->edrpou)->toBe('00032129');

    $this->artisan('ua-banks:sync')
        ->expectsOutputToContain('no changes')
        ->assertSuccessful();
})->with('drivers')->group('live');
