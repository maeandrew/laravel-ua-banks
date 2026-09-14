<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Maeandrew\UaBanks\Contracts\BankRepository;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Exceptions\BankNotFoundException;
use Maeandrew\UaBanks\Exceptions\InvalidIbanException;
use Maeandrew\UaBanks\Iban\Iban;
use Maeandrew\UaBanks\Repositories\DatabaseBankRepository;
use Maeandrew\UaBanks\Repositories\SnapshotBankRepository;
use Maeandrew\UaBanks\Sync\RecordMapper;

/**
 * Entry point of the package; bound as a singleton and proxied by the UaBanks facade.
 */
class UaBanksManager
{
    public const string BUNDLED_SNAPSHOT = __DIR__.'/../resources/data/banks.json';

    /** @var array<string, BankRepository> */
    private array $repositories = [];

    /** @var array<string, Closure(Container): BankRepository> */
    private array $customCreators = [];

    public function __construct(private readonly Container $container) {}

    public function byMfo(string|int $mfo): ?Bank
    {
        $mfo = RecordMapper::normalizeMfo($mfo);

        if ($mfo === null) {
            return null;
        }

        $repository = $this->repository();
        $bank = $repository->find($mfo);

        if ($bank !== null) {
            return $bank;
        }

        $glmfo = $this->aliasTarget($repository, $mfo);

        return $glmfo === null ? null : $repository->find($glmfo);
    }

    /**
     * @throws BankNotFoundException
     */
    public function byMfoOrFail(string|int $mfo): Bank
    {
        return $this->byMfo($mfo) ?? throw new BankNotFoundException((string) $mfo);
    }

    /**
     * @throws InvalidIbanException when the IBAN itself is invalid
     */
    public function forIban(string|Iban $iban): ?Bank
    {
        $iban = $iban instanceof Iban ? $iban : Iban::parse($iban);

        return $this->byMfo($iban->mfo());
    }

    public function byEdrpou(string|int $edrpou): ?Bank
    {
        $edrpou = RecordMapper::normalizeEdrpou($edrpou);

        return $edrpou === null ? null : $this->repository()->findByEdrpou($edrpou);
    }

    /**
     * Resolves an MFO to the head office MFO: returns the MFO itself for a head office,
     * the head office MFO for a regional directorate alias, or null when unknown.
     */
    public function resolveMfo(string|int $mfo): ?string
    {
        $mfo = RecordMapper::normalizeMfo($mfo);

        if ($mfo === null) {
            return null;
        }

        $repository = $this->repository();

        if ($repository->find($mfo) !== null) {
            return $mfo;
        }

        return $this->aliasTarget($repository, $mfo);
    }

    /**
     * @return Collection<string, Bank> keyed by MFO
     */
    public function all(): Collection
    {
        return $this->repository()->all();
    }

    /**
     * Banks with the Normal status that are still present in the NBU source.
     *
     * @return Collection<string, Bank> keyed by MFO
     */
    public function operating(): Collection
    {
        return $this->all()->filter(static fn (Bank $bank): bool => $bank->isOperating() && ! $bank->isRemovedFromSource());
    }

    /**
     * @return array<string, string> regional directorate MFO => head office MFO
     */
    public function aliases(): array
    {
        return $this->repository()->aliases();
    }

    public function lastSyncedAt(): ?CarbonImmutable
    {
        return $this->repository()->lastSyncedAt();
    }

    public function isStale(): bool
    {
        $syncedAt = $this->lastSyncedAt();
        $days = $this->config('ua-banks.stale_after_days', 14);

        return $syncedAt === null
            || $syncedAt->lt(CarbonImmutable::now()->subDays(is_numeric($days) ? (int) $days : 14));
    }

    public function hasData(): bool
    {
        return ! $this->repository()->isEmpty();
    }

    public function repository(?string $driver = null): BankRepository
    {
        $driver ??= $this->getDefaultDriver();

        return $this->repositories[$driver] ??= $this->createRepository($driver);
    }

    public function getDefaultDriver(): string
    {
        $driver = $this->config('ua-banks.driver', 'snapshot');

        return is_string($driver) && $driver !== '' ? $driver : 'snapshot';
    }

    /**
     * Registers a custom driver.
     *
     * @param  Closure(Container): BankRepository  $callback
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;
        unset($this->repositories[$driver]);

        return $this;
    }

    /**
     * Forgets resolved repositories (e.g. after changing configuration at runtime).
     */
    public function forgetRepositories(): static
    {
        $this->repositories = [];

        return $this;
    }

    protected function createRepository(string $driver): BankRepository
    {
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($this->container);
        }

        return match ($driver) {
            'snapshot' => $this->createSnapshotRepository(),
            'database' => new DatabaseBankRepository,
            default => throw new InvalidArgumentException("ua-banks driver [{$driver}] is not supported."),
        };
    }

    protected function createSnapshotRepository(): SnapshotBankRepository
    {
        $path = $this->config('ua-banks.snapshot.path');
        $store = $this->config('ua-banks.cache.store');
        $ttl = $this->config('ua-banks.cache.ttl', 86400);
        $source = $this->config('ua-banks.source.base_url', '');

        /** @var CacheFactory $cache */
        $cache = $this->container->make(CacheFactory::class);

        return new SnapshotBankRepository(
            storagePath: is_string($path) && $path !== '' ? $path : $this->storagePath(),
            bundledPath: self::BUNDLED_SNAPSHOT,
            cache: $ttl === null || $ttl === 0 || $ttl === false ? null : $cache->store(is_string($store) ? $store : null),
            cacheTtl: is_numeric($ttl) ? (int) $ttl : 86400,
            source: is_string($source) ? $source : '',
        );
    }

    private function aliasTarget(BankRepository $repository, string $mfo): ?string
    {
        if ($repository instanceof DatabaseBankRepository) {
            return $repository->resolveAlias($mfo);
        }

        return $repository->aliases()[$mfo] ?? null;
    }

    private function storagePath(): string
    {
        return function_exists('storage_path')
            ? storage_path('app/ua-banks/banks.json')
            : sys_get_temp_dir().'/ua-banks/banks.json';
    }

    private function config(string $key, mixed $default = null): mixed
    {
        /** @var Repository $config */
        $config = $this->container->make('config');

        return $config->get($key, $default);
    }
}
