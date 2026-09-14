<?php

use Illuminate\Support\Facades\Http;
use Maeandrew\UaBanks\Repositories\SnapshotFile;

it('writes a snapshot in the documented format', function () {
    $this->fakeNbu();
    $path = $this->tempDir.'/snapshot/banks.json';

    $this->artisan('ua-banks:snapshot', ['--path' => $path])
        ->expectsOutputToContain('Snapshot written: 10 banks, 3 aliases')
        ->assertSuccessful();

    $data = SnapshotFile::read($path);

    expect(array_keys($data))->toBe(['schema', 'generated_at', 'source', 'banks', 'aliases'])
        ->and($data['schema'])->toBe(1)
        ->and($data['source'])->toBe('https://bank.gov.ua/NBU_BankInfo/get_data_branch')
        ->and($data['banks'])->toHaveCount(10)
        ->and($data['banks'][0]['mfo'])->toBe('300012')
        ->and($data['aliases'])->toBe(['302076' => '300465', '303398' => '300465', '380269' => '305299']);
});

it('uses the same validation as sync', function () {
    config(['ua-banks.sync.min_banks' => 50]);
    $this->fakeNbu();
    $path = $this->tempDir.'/snapshot/banks.json';

    $this->artisan('ua-banks:snapshot', ['--path' => $path])
        ->expectsOutputToContain('at least 50 expected')
        ->assertFailed();

    expect($path)->not->toBeFile();

    $this->artisan('ua-banks:snapshot', ['--path' => $path, '--force' => true])->assertSuccessful();
    expect($path)->toBeFile();
});

it('fails without touching the file when the NBU is down', function () {
    $path = $this->tempDir.'/snapshot/banks.json';
    Http::fake(['*' => Http::response('', 502)]);

    $this->artisan('ua-banks:snapshot', ['--path' => $path])->assertFailed();

    expect($path)->not->toBeFile();
});
