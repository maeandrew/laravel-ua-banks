<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Maeandrew\UaBanks\Repositories\SnapshotBankRepository;
use Maeandrew\UaBanks\Sync\RecordMapper;
use Maeandrew\UaBanks\Sync\Registry;
use Maeandrew\UaBanks\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Live');

dataset('drivers', ['snapshot', 'database']);

function fixtureRegistry(?CarbonImmutable $at = null, ?array $typ0 = null): Registry
{
    return (new RecordMapper)->toRegistry(
        $typ0 ?? TestCase::fixture('typ0.json'),
        TestCase::fixture('typ1.json'),
        $at ?? CarbonImmutable::parse('2026-09-01T00:00:00Z'),
    );
}

function snapshotRepository(string $storage, string $bundled, ?CacheRepository $cache = null): SnapshotBankRepository
{
    return new SnapshotBankRepository($storage, $bundled, $cache, 3600, 'test');
}
