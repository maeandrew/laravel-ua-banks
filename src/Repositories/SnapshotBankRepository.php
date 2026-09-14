<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use Maeandrew\UaBanks\Contracts\SyncableRepository;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Sync\Registry;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * JSON file driver. Reads the synchronized file from storage and falls back to the
 * snapshot bundled with the package, so lookups work without migrations or network.
 */
final class SnapshotBankRepository implements SyncableRepository
{
    private const string CACHE_PREFIX = 'ua-banks:snapshot:';

    private ?Registry $memo = null;

    private ?string $memoKey = null;

    /** @var array<string, string>|null */
    private ?array $edrpouIndex = null;

    /** @var array<string, true> file versions that failed to load in this process */
    private array $failedKeys = [];

    public function __construct(
        private readonly string $storagePath,
        private readonly string $bundledPath,
        private readonly ?Cache $cache = null,
        private readonly int $cacheTtl = 86400,
        private readonly string $source = '',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function find(string $mfo): ?Bank
    {
        return $this->registry()->banks[$mfo] ?? null;
    }

    public function findByEdrpou(string $edrpou): ?Bank
    {
        $registry = $this->registry();

        if ($this->edrpouIndex === null) {
            $this->edrpouIndex = [];

            foreach ($registry->banks as $bank) {
                // Prefer a bank that is still present in the source when EDRPOU codes repeat.
                if ($bank->edrpou !== '' && (! isset($this->edrpouIndex[$bank->edrpou]) || ! $bank->isRemovedFromSource())) {
                    $this->edrpouIndex[$bank->edrpou] = $bank->mfo;
                }
            }
        }

        $mfo = $this->edrpouIndex[$edrpou] ?? null;

        return $mfo === null ? null : ($registry->banks[$mfo] ?? null);
    }

    public function all(): Collection
    {
        return new Collection($this->registry()->banks);
    }

    public function aliases(): array
    {
        return $this->registry()->aliases;
    }

    public function lastSyncedAt(): ?CarbonImmutable
    {
        $registry = $this->registry();

        return $registry->isEmpty() ? null : $registry->generatedAt;
    }

    public function isEmpty(): bool
    {
        return $this->registry()->isEmpty();
    }

    public function stored(): ?Registry
    {
        if (! is_file($this->storagePath)) {
            return null;
        }

        try {
            return SnapshotFile::decode(SnapshotFile::read($this->storagePath));
        } catch (RuntimeException) {
            return null;
        }
    }

    public function store(Registry $registry): void
    {
        $previousKey = $this->cacheKey($this->storagePath);

        SnapshotFile::write($this->storagePath, SnapshotFile::encode($registry, $this->source));

        if ($previousKey !== null) {
            $this->cache?->forget($previousKey);
        }

        $this->flush();
    }

    /**
     * Forgets the in-process copy of the data.
     */
    public function flush(): void
    {
        $this->memo = null;
        $this->memoKey = null;
        $this->edrpouIndex = null;
    }

    /**
     * The file lookups are currently served from, or null when no file exists.
     */
    public function activePath(): ?string
    {
        foreach ([$this->storagePath, $this->bundledPath] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function registry(): Registry
    {
        foreach ([$this->storagePath, $this->bundledPath] as $path) {
            $key = $this->cacheKey($path);

            if ($key === null || isset($this->failedKeys[$key])) {
                continue;
            }

            if ($this->memo !== null && $this->memoKey === $key) {
                return $this->memo;
            }

            try {
                $registry = SnapshotFile::decode($this->load($path, $key));
            } catch (RuntimeException $e) {
                $this->failedKeys[$key] = true;
                $this->logger?->warning('ua-banks: ignoring unreadable snapshot file, falling back to the next source.', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $this->memo = $registry;
            $this->memoKey = $key;
            $this->edrpouIndex = null;

            return $registry;
        }

        $this->flush();

        return new Registry([], [], CarbonImmutable::createFromTimestampUTC(0));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function load(string $path, string $key): array
    {
        if ($this->cache === null || $this->cacheTtl <= 0) {
            return SnapshotFile::read($path);
        }

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $data = SnapshotFile::read($path);
        $this->cache->put($key, $data, $this->cacheTtl);

        return $data;
    }

    /**
     * Versioned cache key: changes whenever the file is replaced (new inode) or modified.
     */
    private function cacheKey(string $path): ?string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        if ($stat === false) {
            return null;
        }

        return self::CACHE_PREFIX.sha1($path.'|'.$stat['ino'].'|'.$stat['mtime'].'|'.$stat['size']);
    }
}
