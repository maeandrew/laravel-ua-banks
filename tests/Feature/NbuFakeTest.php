<?php

use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Testing\NbuFake;
use Maeandrew\UaBanks\UaBanksManager;

it('lets consumers sync against the packaged fixtures', function () {
    config(['ua-banks.sync.min_banks' => 50]);
    NbuFake::fake();

    $this->artisan('ua-banks:sync', ['--force' => true])->assertSuccessful();

    expect(UaBanks::all())->toHaveCount(10)
        ->and(UaBanks::byMfo('380269')?->mfo)->toBe('305299');
});

it('simulates an unavailable NBU', function () {
    NbuFake::unavailable(503);

    $this->artisan('ua-banks:sync')->assertFailed();

    expect(app(UaBanksManager::class)->repository()->stored())->toBeNull();
});

it('rejects unknown fixture names', function () {
    NbuFake::fixturePath('nope.json');
})->throws(RuntimeException::class);
