<?php

namespace Maeandrew\UaBanks\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Maeandrew\UaBanks\UaBanksManager;
use Maeandrew\UaBanks\UaBanksServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/ua-banks-tests-'.bin2hex(random_bytes(6));

        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->tempDir);
    }

    protected function getPackageProviders($app): array
    {
        return [UaBanksServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ua-banks.snapshot.path', $this->tempDir.'/storage/banks.json');
        $app['config']->set('ua-banks.sync.min_banks', 5);
        $app['config']->set('ua-banks.source.retries', 1);
        $app['config']->set('ua-banks.source.backoff', [0]);

        if (env('UA_BANKS_TEST_DB') === 'pgsql') {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => env('DB_PGSQL_HOST', '127.0.0.1'),
                'port' => env('DB_PGSQL_PORT', '5432'),
                'database' => env('DB_PGSQL_DATABASE', 'ua_banks'),
                'username' => env('DB_PGSQL_USERNAME', 'ua_banks'),
                'password' => env('DB_PGSQL_PASSWORD', 'secret'),
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
        }
    }

    protected function migrateUaBanksTables(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_ua_banks_tables.php.stub';
        $migration->down();
        $migration->up();

        $this->beforeApplicationDestroyed(fn () => $migration->down());
    }

    protected function useDriver(string $driver): void
    {
        config(['ua-banks.driver' => $driver]);

        if ($driver === 'database') {
            $this->migrateUaBanksTables();
        }

        app(UaBanksManager::class)->forgetRepositories();
    }

    public static function fixturePath(string $name): string
    {
        return __DIR__.'/Fixtures/nbu/'.$name;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::fixturePath($name)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>|string|null  $typ0  records, raw body, or null for the default fixture
     * @param  list<array<string, mixed>>|string|null  $typ1
     */
    public function fakeNbu(array|string|null $typ0 = null, array|string|null $typ1 = null): void
    {
        $body = static fn (array|string|null $data, string $fixture): string => match (true) {
            is_string($data) => $data,
            is_array($data) => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default => (string) file_get_contents(self::fixturePath($fixture)),
        };

        // Start from a clean factory so a second call replaces (instead of appends to) earlier stubs.
        Http::swap(new HttpFactory($this->app->make('events')));
        Http::preventStrayRequests();

        Http::fake([
            '*get_data_branch?typ=0&json' => Http::response($body($typ0, 'typ0.json'), 200, ['Content-Type' => 'application/json; charset=utf-8']),
            '*get_data_branch?typ=1&json' => Http::response($body($typ1, 'typ1.json'), 200, ['Content-Type' => 'application/json; charset=utf-8']),
        ]);
    }
}
