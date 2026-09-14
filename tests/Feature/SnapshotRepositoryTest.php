<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Maeandrew\UaBanks\Repositories\SnapshotFile;
use Maeandrew\UaBanks\Tests\TestCase;
use Maeandrew\UaBanks\UaBanksManager;

it('falls back to the bundled snapshot when nothing was synchronized', function () {
    $repository = snapshotRepository($this->tempDir.'/missing.json', UaBanksManager::BUNDLED_SNAPSHOT);

    expect($repository->isEmpty())->toBeFalse()
        ->and($repository->find('300465')?->edrpou)->toBe('00032129')
        ->and($repository->all()->count())->toBeGreaterThanOrEqual(50)
        ->and($repository->stored())->toBeNull()
        ->and($repository->activePath())->toBe(UaBanksManager::BUNDLED_SNAPSHOT)
        ->and($repository->lastSyncedAt())->not->toBeNull();
});

it('prefers the synchronized storage file over the bundled snapshot', function () {
    $storage = $this->tempDir.'/storage/banks.json';
    $typ0 = TestCase::fixture('typ0.json');
    $typ0[0]['SHORTNAME'] = 'Storage copy';

    SnapshotFile::write($storage, SnapshotFile::encode(fixtureRegistry(CarbonImmutable::parse('2026-09-10T00:00:00Z'), $typ0), 'test'));
    $repository = snapshotRepository($storage, UaBanksManager::BUNDLED_SNAPSHOT);

    expect($repository->all())->toHaveCount(10)
        ->and($repository->find('300012')?->shortName)->toBe('Storage copy')
        ->and($repository->lastSyncedAt()?->toIso8601String())->toBe('2026-09-10T00:00:00+00:00')
        ->and($repository->stored()?->banks)->toHaveCount(10)
        ->and($repository->activePath())->toBe($storage);
});

it('falls back to the bundled file when the storage file is corrupt', function () {
    $storage = $this->tempDir.'/storage/banks.json';
    mkdir(dirname($storage), 0777, true);
    file_put_contents($storage, '{"schema": 1, "banks": [');

    $repository = snapshotRepository($storage, UaBanksManager::BUNDLED_SNAPSHOT);

    expect($repository->find('305299'))->not->toBeNull()
        ->and($repository->stored())->toBeNull();
});

it('is empty when neither file exists', function () {
    $repository = snapshotRepository($this->tempDir.'/a.json', $this->tempDir.'/b.json');

    expect($repository->isEmpty())->toBeTrue()
        ->and($repository->all())->toBeEmpty()
        ->and($repository->aliases())->toBe([])
        ->and($repository->lastSyncedAt())->toBeNull()
        ->and($repository->find('300465'))->toBeNull();
});

it('exposes aliases and EDRPOU lookups', function () {
    $storage = $this->tempDir.'/banks.json';
    SnapshotFile::write($storage, SnapshotFile::encode(fixtureRegistry(), 'test'));
    $repository = snapshotRepository($storage, $this->tempDir.'/none.json');

    expect($repository->aliases())->toBe(['302076' => '300465', '303398' => '300465', '380269' => '305299'])
        ->and($repository->findByEdrpou('14360570')?->mfo)->toBe('305299')
        ->and($repository->findByEdrpou('99999999'))->toBeNull();
});

it('caches parsed data with a key that follows the file version', function () {
    $storage = $this->tempDir.'/banks.json';
    SnapshotFile::write($storage, SnapshotFile::encode(fixtureRegistry(), 'test'));
    $cache = Cache::store('array');

    $first = snapshotRepository($storage, $this->tempDir.'/none.json', $cache);
    expect($first->find('300465'))->not->toBeNull();

    // A different process (new repository instance) is served from the Laravel cache, not from disk.
    $cachedKey = collect((fn () => $this->storage)->call($cache->getStore()))->keys()->first(fn ($key) => str_starts_with($key, 'ua-banks:snapshot:'));
    expect($cachedKey)->not->toBeNull();

    $cached = $cache->get($cachedKey);
    $cached['banks'][0]['short_name'] = 'From cache';
    $cache->put($cachedKey, $cached, 60);

    expect(snapshotRepository($storage, $this->tempDir.'/none.json', $cache)->find('300012')?->shortName)->toBe('From cache');

    // Replacing the file (as sync does) produces a new cache key, so fresh data is read.
    $typ0 = TestCase::fixture('typ0.json');
    $typ0[0]['SHORTNAME'] = 'Replaced';
    $first->store(fixtureRegistry(null, $typ0));

    expect(snapshotRepository($storage, $this->tempDir.'/none.json', $cache)->find('300012')?->shortName)->toBe('Replaced')
        ->and($first->find('300012')?->shortName)->toBe('Replaced')
        ->and($cache->has($cachedKey))->toBeFalse();
});

it('memoizes within the process', function () {
    $storage = $this->tempDir.'/banks.json';
    SnapshotFile::write($storage, SnapshotFile::encode(fixtureRegistry(), 'test'));
    $repository = snapshotRepository($storage, $this->tempDir.'/none.json');

    expect($repository->find('300465'))->toBe($repository->find('300465'));
});

it('writes files atomically without leftovers', function () {
    $storage = $this->tempDir.'/nested/dir/banks.json';
    $repository = snapshotRepository($storage, $this->tempDir.'/none.json');

    $repository->store(fixtureRegistry());

    expect(glob(dirname($storage).'/{,.}*', GLOB_BRACE))->toHaveCount(3) // ".", "..", banks.json
        ->and(SnapshotFile::read($storage)['schema'])->toBe(1);
});

it('uses the configured storage path through the manager', function () {
    SnapshotFile::write(config('ua-banks.snapshot.path'), SnapshotFile::encode(fixtureRegistry(), 'test'));

    expect(app(UaBanksManager::class)->all())->toHaveCount(10);
});
