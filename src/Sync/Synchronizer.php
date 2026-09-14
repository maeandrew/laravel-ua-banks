<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maeandrew\UaBanks\Contracts\SyncableRepository;
use Maeandrew\UaBanks\Events\BanksSynced;
use Maeandrew\UaBanks\Events\BankStatusChanged;
use Maeandrew\UaBanks\Exceptions\SyncFailedException;

/**
 * Downloads, validates and atomically stores the NBU registry.
 */
final class Synchronizer
{
    public function __construct(
        private readonly NbuClient $client,
        private readonly RegistryValidator $validator,
        private readonly RecordMapper $mapper,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Downloads and validates the registry without touching any storage.
     *
     * @throws SyncFailedException
     */
    public function fetch(bool $force = false, ?CarbonImmutable $now = null): Registry
    {
        $typ0 = $this->client->fetch(NbuClient::TYPE_HEAD_OFFICE);
        $typ1 = $this->client->fetch(NbuClient::TYPE_REGIONAL_DIRECTORATE);

        [$typ0, $typ1] = $this->validator->validate($typ0, $typ1, $force);

        return $this->mapper->toRegistry($typ0, $typ1, $now ?? CarbonImmutable::now('UTC'));
    }

    /**
     * @throws SyncFailedException
     */
    public function sync(SyncableRepository $repository, bool $force = false, bool $dryRun = false): SyncResult
    {
        $now = CarbonImmutable::now('UTC');
        $fresh = $this->fetch($force, $now);
        $result = $this->diff($repository->stored(), $fresh, $now, $dryRun);

        if ($dryRun) {
            return $result;
        }

        $repository->store($result->registry);

        $this->events->dispatch(new BanksSynced(
            added: count($result->added),
            updated: count($result->updated),
            removed: count($result->removed),
            warnings: $result->warnings,
        ));

        foreach ($result->statusChanges as $change) {
            $this->events->dispatch(new BankStatusChanged(
                bank: $change['new'],
                oldStatus: $change['old']->status,
                newStatus: $change['new']->status,
                oldStatusCode: $change['old']->statusCode,
                newStatusCode: $change['new']->statusCode,
            ));
        }

        return $result;
    }

    public function diff(?Registry $stored, Registry $fresh, CarbonImmutable $now, bool $dryRun = false): SyncResult
    {
        $existing = $stored->banks ?? [];
        $banks = [];
        $added = [];
        $updated = [];
        $removed = [];
        $statusChanges = [];

        foreach ($fresh->banks as $mfo => $bank) {
            $bank = $bank->withSyncState($now, null);
            $banks[$mfo] = $bank;
            $old = $existing[$mfo] ?? null;

            if ($old === null) {
                $added[$mfo] = $bank;

                continue;
            }

            $changes = array_keys(array_diff_assoc($bank->registryAttributes(), $old->registryAttributes()));

            if ($old->isRemovedFromSource()) {
                $changes[] = 'removed_from_source_at';
            }

            if ($changes !== []) {
                $updated[$mfo] = ['old' => $old, 'new' => $bank, 'changes' => $changes];
            }

            if ($old->statusCode !== $bank->statusCode) {
                $statusChanges[$mfo] = ['old' => $old, 'new' => $bank];
            }
        }

        foreach ($existing as $mfo => $old) {
            if (isset($banks[$mfo])) {
                continue;
            }

            if (! $old->isRemovedFromSource()) {
                $removed[$mfo] = $old;
            }

            $banks[$mfo] = $old->withSyncState($old->syncedAt, $old->removedFromSourceAt ?? $now);
        }

        ksort($banks, SORT_STRING);

        $oldAliases = $stored->aliases ?? [];
        $aliasesChanged = count(array_diff_assoc($fresh->aliases, $oldAliases))
            + count(array_diff_key($oldAliases, $fresh->aliases));

        return new SyncResult(
            registry: new Registry($banks, $fresh->aliases, $now, $fresh->raw, $fresh->warnings),
            added: $added,
            updated: $updated,
            removed: $removed,
            statusChanges: $stored === null ? [] : $statusChanges,
            warnings: $fresh->warnings,
            firstSync: $stored === null,
            dryRun: $dryRun,
            aliasesChanged: $aliasesChanged,
        );
    }
}
