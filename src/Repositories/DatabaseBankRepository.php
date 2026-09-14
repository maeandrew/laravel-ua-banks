<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maeandrew\UaBanks\Contracts\BankRepository;
use Maeandrew\UaBanks\Contracts\SyncableRepository;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Models\BankRecord;
use Maeandrew\UaBanks\Models\MfoAlias;
use Maeandrew\UaBanks\Sync\Registry;

/**
 * Database driver backed by the `ua_banks` and `ua_bank_mfo_aliases` tables.
 *
 * Until the first successful sync the tables are empty; lookups are then answered by the optional
 * fallback repository (the bundled snapshot by default). {@see stored()} never uses the fallback.
 */
final class DatabaseBankRepository implements SyncableRepository
{
    private const int CHUNK = 200;

    public function __construct(private readonly ?BankRepository $fallback = null) {}

    public function find(string $mfo): ?Bank
    {
        $bank = BankRecord::query()->find($mfo)?->toBank();

        return $bank ?? ($this->usesFallback() ? $this->fallback?->find($mfo) : null);
    }

    public function findByEdrpou(string $edrpou): ?Bank
    {
        $bank = BankRecord::query()
            ->where('edrpou', $edrpou)
            ->orderByRaw('CASE WHEN removed_from_source_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('mfo')
            ->first()
            ?->toBank();

        return $bank ?? ($this->usesFallback() ? $this->fallback?->findByEdrpou($edrpou) : null);
    }

    public function all(): Collection
    {
        $banks = $this->storedBanks();

        if ($banks === [] && $this->fallback !== null) {
            return $this->fallback->all();
        }

        return new Collection($banks);
    }

    public function aliases(): array
    {
        return $this->usesFallback() ? ($this->fallback?->aliases() ?? []) : $this->storedAliases();
    }

    public function resolveAlias(string $mfo): ?string
    {
        $glmfo = MfoAlias::query()->whereKey($mfo)->value('glmfo');

        if (is_scalar($glmfo)) {
            return (string) $glmfo;
        }

        return $this->usesFallback() ? ($this->fallback?->aliases()[$mfo] ?? null) : null;
    }

    public function lastSyncedAt(): ?CarbonImmutable
    {
        return $this->storedLastSyncedAt() ?? ($this->usesFallback() ? $this->fallback?->lastSyncedAt() : null);
    }

    public function isEmpty(): bool
    {
        return ! $this->hasStoredData() && ($this->fallback === null || $this->fallback->isEmpty());
    }

    /**
     * True when lookups are currently served by the fallback (nothing synchronized yet).
     */
    public function usesFallback(): bool
    {
        return $this->fallback !== null && ! $this->hasStoredData();
    }

    public function stored(): ?Registry
    {
        $banks = $this->storedBanks();

        if ($banks === []) {
            return null;
        }

        return new Registry($banks, $this->storedAliases(), $this->storedLastSyncedAt() ?? CarbonImmutable::now('UTC'));
    }

    public function store(Registry $registry): void
    {
        $connection = (new BankRecord)->getConnection();

        $connection->transaction(function () use ($registry): void {
            $withRaw = [];
            $withoutRaw = [];

            foreach ($registry->banks as $mfo => $bank) {
                $row = $this->row($bank);

                if (isset($registry->raw[$mfo])) {
                    $row['raw'] = json_encode($registry->raw[$mfo], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $withRaw[] = $row;
                } else {
                    $withoutRaw[] = $row;
                }
            }

            foreach ([$withRaw, $withoutRaw] as $rows) {
                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    BankRecord::query()->upsert($chunk, ['mfo'], array_values(array_diff(array_keys($chunk[0]), ['mfo'])));
                }
            }

            MfoAlias::query()->delete();

            $aliases = [];

            foreach ($registry->aliases as $mfo => $glmfo) {
                $aliases[] = ['mfo' => $mfo, 'glmfo' => $glmfo];
            }

            foreach (array_chunk($aliases, self::CHUNK) as $chunk) {
                MfoAlias::query()->insert($chunk);
            }
        });
    }

    private function hasStoredData(): bool
    {
        return BankRecord::query()->exists();
    }

    /**
     * @return array<string, Bank>
     */
    private function storedBanks(): array
    {
        $banks = [];

        foreach (BankRecord::query()->orderBy('mfo')->get() as $record) {
            $bank = $record->toBank();
            $banks[$bank->mfo] = $bank;
        }

        return $banks;
    }

    /**
     * @return array<string, string>
     */
    private function storedAliases(): array
    {
        $aliases = [];

        foreach (MfoAlias::query()->orderBy('mfo')->get() as $alias) {
            $aliases[(string) $alias->mfo] = (string) $alias->glmfo;
        }

        return $aliases;
    }

    private function storedLastSyncedAt(): ?CarbonImmutable
    {
        $value = BankRecord::query()->max('synced_at');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function row(Bank $bank): array
    {
        return [
            ...$bank->registryAttributes(),
            'synced_at' => $bank->syncedAt->utc()->format('Y-m-d H:i:s'),
            'removed_from_source_at' => $bank->removedFromSourceAt?->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
