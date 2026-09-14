<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Maeandrew\UaBanks\Contracts\SyncableRepository;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Events\BankSyncFailed;
use Maeandrew\UaBanks\Exceptions\SyncFailedException;
use Maeandrew\UaBanks\Sync\Synchronizer;
use Maeandrew\UaBanks\Sync\SyncResult;
use Maeandrew\UaBanks\UaBanksManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class SyncCommand extends Command
{
    protected $signature = 'ua-banks:sync
        {--dry-run : Show what would change without writing anything}
        {--force : Ignore the sync.min_banks threshold}';

    protected $description = 'Synchronize the Ukrainian bank registry with the NBU open data API';

    public function handle(UaBanksManager $manager, Synchronizer $synchronizer, Dispatcher $events, LoggerInterface $logger): int
    {
        $driver = $manager->getDefaultDriver();

        try {
            $repository = $manager->repository($driver);

            if (! $repository instanceof SyncableRepository) {
                throw SyncFailedException::unsupportedRepository($driver);
            }

            $result = $synchronizer->sync(
                $repository,
                force: (bool) $this->option('force'),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (Throwable $e) {
            $logger->error('ua-banks: synchronization failed: '.$e->getMessage(), ['exception' => $e, 'driver' => $driver]);
            $events->dispatch(new BankSyncFailed($e, $driver));
            $this->components->error('Synchronization failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($result, $driver);

        return self::SUCCESS;
    }

    private function report(SyncResult $result, string $driver): void
    {
        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($result->dryRun || $this->output->isVerbose()) {
            foreach ($result->added as $bank) {
                $this->line(sprintf('  <fg=green>+ %s</> %s', $bank->mfo, $bank->shortName));
            }

            foreach ($result->updated as $mfo => $change) {
                $this->line(sprintf('  <fg=yellow>~ %s</> %s (%s)', $mfo, $change['new']->shortName, implode(', ', $change['changes'])));
            }

            foreach ($result->removed as $bank) {
                $this->line(sprintf('  <fg=red>- %s</> %s', $bank->mfo, $bank->shortName));
            }

            foreach ($result->statusChanges as $mfo => $change) {
                $this->line(sprintf(
                    '  <fg=cyan>! %s</> %s: %s → %s',
                    $mfo,
                    $change['new']->shortName,
                    $this->statusName($change['old']),
                    $this->statusName($change['new']),
                ));
            }
        }

        $this->components->twoColumnDetail('Driver', $driver);
        $this->components->twoColumnDetail('Head offices in source', (string) (count($result->registry->banks) - count(array_filter(
            $result->registry->banks,
            static fn (Bank $bank): bool => $bank->isRemovedFromSource(),
        ))));
        $this->components->twoColumnDetail('Aliases (regional directorates)', (string) count($result->registry->aliases));
        $this->components->twoColumnDetail('Added', (string) count($result->added));
        $this->components->twoColumnDetail('Updated', (string) count($result->updated));
        $this->components->twoColumnDetail('Removed from source', (string) count($result->removed));
        $this->components->twoColumnDetail('Status changes', (string) count($result->statusChanges));

        if ($result->dryRun) {
            $this->components->info('Dry run: nothing was written.');
        } else {
            $this->components->info($result->hasChanges() ? 'Bank registry synchronized.' : 'Bank registry is up to date, no changes.');
        }
    }

    private function statusName(Bank $bank): string
    {
        return $bank->statusName !== '' ? $bank->statusName : (string) $bank->statusCode;
    }
}
