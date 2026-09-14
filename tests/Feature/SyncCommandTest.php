<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Maeandrew\UaBanks\Contracts\BankRepository;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Events\BanksSynced;
use Maeandrew\UaBanks\Events\BankStatusChanged;
use Maeandrew\UaBanks\Events\BankSyncFailed;
use Maeandrew\UaBanks\Exceptions\SyncFailedException;
use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Models\BankRecord;
use Maeandrew\UaBanks\Repositories\SnapshotBankRepository;
use Maeandrew\UaBanks\Tests\TestCase;
use Maeandrew\UaBanks\UaBanksManager;

/**
 * Fingerprint of everything the driver stores, to prove failed syncs leave data untouched.
 */
function storedState(): string
{
    $repository = app(UaBanksManager::class)->repository();

    return json_encode([
        $repository->all()->map->toArray()->all(),
        $repository->aliases(),
        $repository instanceof SnapshotBankRepository && is_file(config('ua-banks.snapshot.path'))
            ? md5_file(config('ua-banks.snapshot.path'))
            : null,
    ], JSON_THROW_ON_ERROR);
}

it('synchronizes the registry', function (string $driver) {
    $this->useDriver($driver);
    $this->fakeNbu();
    Event::fake();

    $this->artisan('ua-banks:sync')
        ->expectsOutputToContain('Bank registry synchronized.')
        ->assertSuccessful();

    expect(UaBanks::all())->toHaveCount(10)
        ->and(UaBanks::byMfo('300465')?->edrpou)->toBe('00032129')
        ->and(UaBanks::byMfo('300012')?->status)->toBe(BankStatus::Liquidation)
        ->and(UaBanks::byMfo('380269')?->mfo)->toBe('305299')
        ->and(UaBanks::aliases())->toHaveCount(3)
        ->and(UaBanks::lastSyncedAt()?->isToday())->toBeTrue();

    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => $e->added === 10 && $e->updated === 0 && $e->removed === 0);
    Event::assertNotDispatched(BankStatusChanged::class);
    Event::assertNotDispatched(BankSyncFailed::class);

    Http::assertSent(fn ($request) => $request->url() === 'https://bank.gov.ua/NBU_BankInfo/get_data_branch?typ=0&json'
        && $request->hasHeader('User-Agent', 'maeandrew/laravel-ua-banks'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '?typ=1&json'));
})->with('drivers');

it('stores the raw NBU record in the database driver', function () {
    $this->useDriver('database');
    $this->fakeNbu();

    $this->artisan('ua-banks:sync')->assertSuccessful();

    $record = BankRecord::query()->findOrFail('300465');

    expect($record->raw['KOD_EDRPOU'] ?? null)->toBe('00032129')
        ->and($record->removed_from_source_at)->toBeNull()
        ->and($record->aliases()->count())->toBe(2);
});

it('writes the snapshot storage file for the snapshot driver', function () {
    $this->useDriver('snapshot');
    $this->fakeNbu();

    $this->artisan('ua-banks:sync')->assertSuccessful();

    expect(config('ua-banks.snapshot.path'))->toBeFile()
        ->and(json_decode(file_get_contents(config('ua-banks.snapshot.path')), true)['banks'])->toHaveCount(10);
});

it('reports no changes on a repeated run', function (string $driver) {
    $this->useDriver($driver);
    $this->fakeNbu();
    $this->artisan('ua-banks:sync')->assertSuccessful();
    $before = collect(UaBanks::all())->map(fn ($bank) => $bank->registryAttributes())->all();

    Event::fake();
    $this->artisan('ua-banks:sync')
        ->expectsOutputToContain('no changes')
        ->assertSuccessful();

    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => $e->added + $e->updated + $e->removed === 0);
    Event::assertNotDispatched(BankStatusChanged::class);
    expect(collect(UaBanks::all())->map(fn ($bank) => $bank->registryAttributes())->all())->toBe($before);
})->with('drivers');

function failNbu(string $scenario): void
{
    $fixture = fn (string $name) => file_get_contents(TestCase::fixturePath($name));

    match ($scenario) {
        'timeout' => Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out')),
        'HTTP 500' => Http::fake(['*' => Http::response('Internal Server Error', 500)]),
        'HTTP 403' => Http::fake(['*' => Http::response('Forbidden', 403)]),
        'invalid JSON' => Http::fake(['*' => Http::response($fixture('typ0-invalid-json.json'))]),
        'not an array' => Http::fake(['*' => Http::response($fixture('typ0-invalid-not-array.json'))]),
        'missing fields' => Http::fake([
            '*typ=0*' => Http::response($fixture('typ0-invalid-missing-fields.json')),
            '*typ=1*' => Http::response($fixture('typ1.json')),
        ]),
        'duplicates' => Http::fake([
            '*typ=0*' => Http::response($fixture('typ0-invalid-duplicates.json')),
            '*typ=1*' => Http::response($fixture('typ1.json')),
        ]),
    };
}

it('keeps old data when the NBU fails', function (string $driver, string $scenario, string $message) {
    $this->useDriver($driver);
    $this->fakeNbu();
    $this->artisan('ua-banks:sync')->assertSuccessful();
    $before = storedState();

    Http::swap(new Factory);
    Http::preventStrayRequests();
    failNbu($scenario);
    Event::fake();
    Log::spy();

    $this->artisan('ua-banks:sync')
        ->expectsOutputToContain('Synchronization failed')
        ->assertFailed();

    expect(storedState())->toBe($before);
    Event::assertDispatched(BankSyncFailed::class, fn (BankSyncFailed $e) => $e->driver === $driver
        && $e->exception instanceof SyncFailedException
        && str_contains($e->exception->getMessage(), $message));
    Event::assertNotDispatched(BanksSynced::class);
    Log::shouldHaveReceived('error')->once();
})->with('drivers')->with([
    ['timeout', 'unreachable'],
    ['HTTP 500', 'HTTP 500'],
    ['HTTP 403', 'HTTP 403'],
    ['invalid JSON', 'invalid JSON'],
    ['not an array', 'not a JSON array'],
    ['missing fields', 'missing or invalid MFO'],
    ['duplicates', 'duplicate MFO'],
]);

it('retries transient failures before giving up', function () {
    config(['ua-banks.source.retries' => 2]);
    Http::fake(['*' => Http::sequence()->push('busy', 503)->push('busy', 503)->pushFile(TestCase::fixturePath('typ0.json'))
        ->whenEmpty(Http::response(file_get_contents(TestCase::fixturePath('typ1.json'))))]);

    $this->artisan('ua-banks:sync')->assertSuccessful();

    Http::assertSentCount(4);
    expect(UaBanks::all())->toHaveCount(10);
});

it('does not write anything on the first failure when storage is empty', function (string $driver) {
    $this->useDriver($driver);
    Http::fake(['*' => Http::response('nope', 500)]);

    $this->artisan('ua-banks:sync')->assertFailed();

    expect(app(UaBanksManager::class)->repository()->stored())->toBeNull();
})->with('drivers');

it('refuses fewer than min_banks head offices unless --force is used', function (string $driver) {
    $this->useDriver($driver);
    config(['ua-banks.sync.min_banks' => 50]);
    $this->fakeNbu();
    Event::fake();

    $this->artisan('ua-banks:sync')
        ->expectsOutputToContain('only 10 head offices received, at least 50 expected')
        ->assertFailed();

    expect(app(UaBanksManager::class)->repository()->stored())->toBeNull();
    Event::assertDispatched(BankSyncFailed::class);

    $this->artisan('ua-banks:sync', ['--force' => true])->assertSuccessful();

    expect(app(UaBanksManager::class)->repository()->stored()?->banks)->toHaveCount(10);
})->with('drivers');

it('shows the diff without writing on --dry-run', function (string $driver) {
    $this->useDriver($driver);
    $this->fakeNbu();
    $this->artisan('ua-banks:sync')->assertSuccessful();
    $before = storedState();

    $typ0 = TestCase::fixture('typ0.json');
    $typ0 = array_values(array_filter($typ0, fn ($r) => $r['MFO'] !== 300506));
    foreach ($typ0 as &$record) {
        if ($record['MFO'] === 300119) {
            $record['KSTAN'] = 4;
            $record['N_STAN'] = 'Ліквідація';
        }
        if ($record['MFO'] === 322001) {
            $record['ADRESS'] = 'нова адреса';
        }
    }
    unset($record);
    $new = TestCase::fixture('typ0.json')[0];
    $new['MFO'] = $new['GLMFO'] = 399001;
    $new['SHORTNAME'] = 'АТ "Тестовий банк"';
    $typ0[] = $new;

    $this->fakeNbu($typ0);
    Event::fake([BanksSynced::class, BankStatusChanged::class, BankSyncFailed::class]);

    $this->artisan('ua-banks:sync', ['--dry-run' => true])
        ->expectsOutputToContain('+ 399001')
        ->expectsOutputToContain('~ 322001')
        ->expectsOutputToContain('- 300506')
        ->expectsOutputToContain('! 300119')
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();

    expect(storedState())->toBe($before);
    Event::assertNotDispatched(BanksSynced::class);
    Event::assertNotDispatched(BankStatusChanged::class);
})->with('drivers');

it('marks banks that disappeared from the source instead of deleting them', function (string $driver) {
    $this->useDriver($driver);
    $this->fakeNbu();
    $this->artisan('ua-banks:sync')->assertSuccessful();

    $this->travel(1)->hours();
    $typ0 = array_values(array_filter(TestCase::fixture('typ0.json'), fn ($r) => $r['MFO'] !== 300506));
    $this->fakeNbu($typ0);
    Event::fake();

    $this->artisan('ua-banks:sync')->assertSuccessful();

    $removed = UaBanks::byMfo('300506');
    expect($removed)->not->toBeNull()
        ->and($removed->removedFromSourceAt)->not->toBeNull()
        ->and($removed->isRemovedFromSource())->toBeTrue()
        ->and(UaBanks::operating()->has('300506'))->toBeFalse()
        ->and(UaBanks::all())->toHaveCount(10);
    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => $e->removed === 1 && $e->added === 0 && $e->updated === 0);

    $removedAt = $removed->removedFromSourceAt;

    // Still missing on the next run: timestamp is preserved and not counted again.
    $this->travel(1)->hours();
    Event::fake();
    $this->artisan('ua-banks:sync')->assertSuccessful();
    expect(UaBanks::byMfo('300506')?->removedFromSourceAt?->equalTo($removedAt))->toBeTrue();
    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => $e->removed === 0);

    // Reappears: flag is cleared and it counts as updated.
    $this->fakeNbu();
    Event::fake();
    $this->artisan('ua-banks:sync')->assertSuccessful();
    expect(UaBanks::byMfo('300506')?->removedFromSourceAt)->toBeNull();
    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => $e->updated === 1);
})->with('drivers');

it('dispatches BankStatusChanged for every KSTAN change', function (string $driver) {
    $this->useDriver($driver);
    $this->fakeNbu();
    $this->artisan('ua-banks:sync')->assertSuccessful();

    $typ0 = TestCase::fixture('typ0.json');
    foreach ($typ0 as &$record) {
        match ($record['MFO']) {
            300119 => [$record['KSTAN'], $record['N_STAN']] = [4, 'Ліквідація'],
            300506 => [$record['KSTAN'], $record['N_STAN']] = [9, 'Новий стан'],
            default => null,
        };
    }
    unset($record);

    $this->fakeNbu($typ0);
    Event::fake();

    $this->artisan('ua-banks:sync')->assertSuccessful();

    Event::assertDispatchedTimes(BankStatusChanged::class, 2);
    Event::assertDispatched(BankStatusChanged::class, fn (BankStatusChanged $e) => $e->bank->mfo === '300119'
        && $e->oldStatus === BankStatus::Normal
        && $e->newStatus === BankStatus::Liquidation
        && $e->bank->status === BankStatus::Liquidation);
    Event::assertDispatched(BankStatusChanged::class, fn (BankStatusChanged $e) => $e->bank->mfo === '300506'
        && $e->newStatus === BankStatus::Unknown
        && $e->newStatusCode === 9);
    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => $e->updated === 2);
    expect(UaBanks::byMfo('300506')?->statusName)->toBe('Новий стан');
})->with('drivers');

it('does not dispatch status changes on the first sync', function (string $driver) {
    $this->useDriver($driver);
    $typ0 = TestCase::fixture('typ0.json');
    $typ0[0]['KSTAN'] = 1; // differs from the bundled snapshot, which must not count as stored data

    $this->fakeNbu($typ0);
    Event::fake();

    $this->artisan('ua-banks:sync')->assertSuccessful();

    Event::assertNotDispatched(BankStatusChanged::class);
    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => $e->added === 10);
})->with('drivers');

it('reports mapping warnings without failing', function (string $driver) {
    $this->useDriver($driver);
    $typ0 = TestCase::fixture('typ0.json');
    $typ0[2]['D_OPEN'] = '32.13.2001';
    $this->fakeNbu($typ0);
    Event::fake();

    $this->artisan('ua-banks:sync')
        ->expectsOutputToContain('invalid D_OPEN value "32.13.2001"')
        ->assertSuccessful();

    expect(UaBanks::byMfo((string) $typ0[2]['MFO'])?->openedAt)->toBeNull();
    Event::assertDispatched(BanksSynced::class, fn (BanksSynced $e) => count($e->warnings) === 1);
})->with('drivers');

it('fails gracefully for drivers that cannot be synchronized', function () {
    UaBanks::extend('readonly', fn () => new class implements BankRepository
    {
        public function find(string $mfo): ?Bank
        {
            return null;
        }

        public function findByEdrpou(string $edrpou): ?Bank
        {
            return null;
        }

        public function all(): Collection
        {
            return collect();
        }

        public function aliases(): array
        {
            return [];
        }

        public function lastSyncedAt(): ?CarbonImmutable
        {
            return null;
        }

        public function isEmpty(): bool
        {
            return true;
        }
    });
    config(['ua-banks.driver' => 'readonly']);
    Event::fake();

    $this->artisan('ua-banks:sync')
        ->expectsOutputToContain('does not support synchronization')
        ->assertFailed();

    Event::assertDispatched(BankSyncFailed::class, fn (BankSyncFailed $e) => $e->driver === 'readonly');
    Http::assertNothingSent();
});
