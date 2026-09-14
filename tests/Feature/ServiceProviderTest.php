<?php

use Illuminate\Support\ServiceProvider;
use Maeandrew\UaBanks\Sync\NbuClient;
use Maeandrew\UaBanks\UaBanksServiceProvider;

it('publishes config, translations and migrations under package tags', function () {
    $config = ServiceProvider::pathsToPublish(UaBanksServiceProvider::class, 'ua-banks-config');
    $translations = ServiceProvider::pathsToPublish(UaBanksServiceProvider::class, 'ua-banks-translations');
    $migrations = ServiceProvider::pathsToPublish(UaBanksServiceProvider::class, 'ua-banks-migrations');

    expect(array_map('realpath', array_keys($config)))->toBe([realpath(__DIR__.'/../../config/ua-banks.php')])
        ->and(array_values($config)[0])->toEndWith('ua-banks.php')
        ->and(array_map('realpath', array_keys($translations)))->toBe([realpath(__DIR__.'/../../resources/lang')])
        ->and(array_keys($migrations)[0])->toEndWith('create_ua_banks_tables.php.stub');
});

it('registers the commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('ua-banks:sync')->toContain('ua-banks:snapshot');
});

it('merges the package config', function () {
    expect(config('ua-banks.database.tables'))->toBe(['banks' => 'ua_banks', 'aliases' => 'ua_bank_mfo_aliases'])
        ->and(config('ua-banks.schedule.enabled'))->toBeFalse()
        ->and(config('ua-banks.stale_after_days'))->toBe(14);
});

it('builds the NBU client from config', function () {
    config(['ua-banks.source.base_url' => 'https://example.test/get_data_branch']);

    expect(app(NbuClient::class)->url(1))->toBe('https://example.test/get_data_branch?typ=1&json');
});

it('translates under the ua-banks namespace', function () {
    expect(__('ua-banks::statuses.liquidation', [], 'uk'))->toBe('Ліквідація')
        ->and(__('ua-banks::validation.iban_checksum', ['attribute' => 'IBAN'], 'en'))->toBe('The IBAN has invalid IBAN check digits.');
});
