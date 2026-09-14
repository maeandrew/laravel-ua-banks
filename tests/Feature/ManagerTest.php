<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Maeandrew\UaBanks\Contracts\BankRepository;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Enums\IbanError;
use Maeandrew\UaBanks\Exceptions\BankNotFoundException;
use Maeandrew\UaBanks\Exceptions\InvalidIbanException;
use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Iban\Iban;
use Maeandrew\UaBanks\Repositories\SnapshotBankRepository;
use Maeandrew\UaBanks\Repositories\SnapshotFile;
use Maeandrew\UaBanks\Testing\IbanFactory;
use Maeandrew\UaBanks\UaBanksManager;

beforeEach(function () {
    $this->seedDriver = function (string $driver, ?CarbonImmutable $at = null): void {
        $this->useDriver($driver);
        $registry = fixtureRegistry($at ?? CarbonImmutable::now('UTC'));
        app(UaBanksManager::class)->repository()->store($registry);
    };
});

it('finds banks by MFO as string or int', function (string $driver) {
    ($this->seedDriver)($driver);

    expect(UaBanks::byMfo('300465'))->toBeInstanceOf(Bank::class)
        ->and(UaBanks::byMfo('300465')?->shortName)->toBe('АТ "Ощадбанк"')
        ->and(UaBanks::byMfo(305299)?->edrpou)->toBe('14360570')
        ->and(UaBanks::byMfo('399999'))->toBeNull()
        ->and(UaBanks::byMfo('abc'))->toBeNull()
        ->and(UaBanks::byMfo(''))->toBeNull();
})->with('drivers');

it('resolves regional directorate aliases to the head office', function (string $driver) {
    ($this->seedDriver)($driver);

    expect(UaBanks::byMfo('302076')?->mfo)->toBe('300465')
        ->and(UaBanks::byMfo('380269')?->mfo)->toBe('305299')
        ->and(UaBanks::resolveMfo('303398'))->toBe('300465')
        ->and(UaBanks::resolveMfo(300465))->toBe('300465')
        ->and(UaBanks::resolveMfo('399999'))->toBeNull()
        ->and(UaBanks::aliases())->toHaveCount(3);
})->with('drivers');

it('fails loudly with byMfoOrFail', function (string $driver) {
    ($this->seedDriver)($driver);

    expect(UaBanks::byMfoOrFail('322001')->edrpou)->toBe('21133352');

    UaBanks::byMfoOrFail('399999');
})->with('drivers')->throws(BankNotFoundException::class, '399999');

it('finds the bank for an IBAN', function (string $driver) {
    ($this->seedDriver)($driver);

    expect(UaBanks::forIban(IbanFactory::make('322001')->formatted())?->mfo)->toBe('322001')
        ->and(UaBanks::forIban(IbanFactory::make('303398'))?->mfo)->toBe('300465')
        ->and(UaBanks::forIban(IbanFactory::string('399999')))->toBeNull();
})->with('drivers');

it('throws for an invalid IBAN in forIban', function () {
    try {
        UaBanks::forIban(IbanFactory::withInvalidChecksum());
        $this->fail('Expected exception');
    } catch (InvalidIbanException $e) {
        expect($e->reason())->toBe(IbanError::ChecksumMismatch);
    }
});

it('finds banks by EDRPOU', function (string $driver) {
    ($this->seedDriver)($driver);

    expect(UaBanks::byEdrpou('00032129')?->mfo)->toBe('300465')
        ->and(UaBanks::byEdrpou(32129)?->mfo)->toBe('300465')
        ->and(UaBanks::byEdrpou('123456789'))->toBeNull()
        ->and(UaBanks::byEdrpou('00000000'))->toBeNull();
})->with('drivers');

it('lists all and operating banks keyed by MFO', function (string $driver) {
    ($this->seedDriver)($driver);

    $all = UaBanks::all();
    $operating = UaBanks::operating();

    expect($all)->toHaveCount(10)
        ->and($all->keys()->all())->toBe($all->keys()->sort()->values()->all())
        ->and($all->get('300012'))->toBeInstanceOf(Bank::class)
        ->and($operating->has('300012'))->toBeFalse()
        ->and($operating->has('300465'))->toBeTrue()
        ->and($operating->every(fn (Bank $bank) => $bank->isOperating()))->toBeTrue()
        ->and($operating->count())->toBe(7);
})->with('drivers');

it('reports freshness', function (string $driver) {
    ($this->seedDriver)($driver, CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'));

    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'));
    expect(UaBanks::lastSyncedAt()?->toIso8601String())->toBe('2026-09-01T12:00:00+00:00')
        ->and(UaBanks::isStale())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:01', 'UTC'));
    expect(UaBanks::isStale())->toBeTrue();

    config(['ua-banks.stale_after_days' => 30]);
    expect(UaBanks::isStale())->toBeFalse();
})->with('drivers');

it('uses generated_at of the bundled snapshot for freshness', function () {
    $generatedAt = SnapshotFile::read(UaBanksManager::BUNDLED_SNAPSHOT)['generated_at'];

    expect(UaBanks::lastSyncedAt()?->equalTo(CarbonImmutable::parse($generatedAt)))->toBeTrue();
});

it('is stale and empty when there is no data', function () {
    config(['ua-banks.database.fallback_to_bundled_snapshot' => false]);
    $this->useDriver('database');

    expect(UaBanks::hasData())->toBeFalse()
        ->and(UaBanks::lastSyncedAt())->toBeNull()
        ->and(UaBanks::isStale())->toBeTrue()
        ->and(UaBanks::all())->toBeEmpty();
});

it('serves the bundled snapshot from the database driver until the first sync', function () {
    $this->useDriver('database');
    $repository = app(UaBanksManager::class)->repository();
    $bundledAt = CarbonImmutable::parse(SnapshotFile::read(UaBanksManager::BUNDLED_SNAPSHOT)['generated_at']);

    expect($repository->usesFallback())->toBeTrue()
        ->and($repository->stored())->toBeNull()
        ->and(UaBanks::hasData())->toBeTrue()
        ->and(UaBanks::byMfo('300465')?->edrpou)->toBe('00032129')
        ->and(UaBanks::byMfo('302076')?->mfo)->toBe('300465')
        ->and(UaBanks::byEdrpou('14360570')?->mfo)->toBe('305299')
        ->and(UaBanks::all()->count())->toBeGreaterThan(50)
        ->and(UaBanks::aliases())->not->toBeEmpty()
        ->and(UaBanks::lastSyncedAt()?->equalTo($bundledAt))->toBeTrue();

    $repository->store(fixtureRegistry(CarbonImmutable::parse('2026-09-13T08:00:00Z')));

    expect($repository->usesFallback())->toBeFalse()
        ->and(UaBanks::all())->toHaveCount(10)
        ->and(UaBanks::byMfo('300335')?->mfo)->toBe('300335')
        ->and(UaBanks::byMfo('380946'))->toBeNull() // in the bundled snapshot only
        ->and(UaBanks::lastSyncedAt()?->toIso8601String())->toBe('2026-09-13T08:00:00+00:00');
});

it('can disable the database fallback', function () {
    config(['ua-banks.database.fallback_to_bundled_snapshot' => false]);
    $this->useDriver('database');

    expect(UaBanks::byMfo('300465'))->toBeNull()
        ->and(UaBanks::repository()->usesFallback())->toBeFalse();
});

it('supports custom drivers via extend', function () {
    UaBanks::extend('custom', function (Container $app): BankRepository {
        expect($app)->toBeInstanceOf(Container::class);

        return new SnapshotBankRepository(sys_get_temp_dir().'/does-not-exist.json', UaBanksManager::BUNDLED_SNAPSHOT);
    });
    config(['ua-banks.driver' => 'custom']);

    expect(UaBanks::repository())->toBeInstanceOf(SnapshotBankRepository::class)
        ->and(UaBanks::byMfo('300465')?->edrpou)->toBe('00032129');
});

it('rejects unknown drivers', function () {
    config(['ua-banks.driver' => 'nope']);

    UaBanks::byMfo('300465');
})->throws(InvalidArgumentException::class, 'ua-banks driver [nope] is not supported.');

it('is a singleton available through DI and the facade', function () {
    expect(app(UaBanksManager::class))->toBe(app(UaBanksManager::class))
        ->and(UaBanks::getFacadeRoot())->toBe(app(UaBanksManager::class))
        ->and(app(BankRepository::class))->toBe(app(UaBanksManager::class)->repository());
});

it('accepts Iban objects', function () {
    expect(UaBanks::forIban(Iban::generate('305299', '1'))?->mfo)->toBe('305299');
});
