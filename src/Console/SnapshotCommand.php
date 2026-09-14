<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Console;

use Illuminate\Console\Command;
use Maeandrew\UaBanks\Repositories\SnapshotFile;
use Maeandrew\UaBanks\Sync\Synchronizer;
use Maeandrew\UaBanks\UaBanksManager;
use Throwable;

/**
 * Maintainer command: regenerates the snapshot bundled with the package.
 */
final class SnapshotCommand extends Command
{
    protected $signature = 'ua-banks:snapshot
        {--path= : Where to write the snapshot (defaults to the bundled resources/data/banks.json)}
        {--force : Ignore the sync.min_banks threshold}';

    protected $description = 'Regenerate the bundled bank registry snapshot from the NBU (package maintainers)';

    public function handle(Synchronizer $synchronizer): int
    {
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : UaBanksManager::BUNDLED_SNAPSHOT;

        try {
            $registry = $synchronizer->fetch((bool) $this->option('force'));
            $source = config('ua-banks.source.base_url');
            $contents = SnapshotFile::encode($registry, is_string($source) ? $source : '');
            SnapshotFile::write($path, $contents);
        } catch (Throwable $e) {
            $this->components->error('Snapshot failed: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($registry->warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info(sprintf(
            'Snapshot written: %d banks, %d aliases, %.1f KB.',
            count($registry->banks),
            count($registry->aliases),
            strlen($contents) / 1024,
        ));

        return self::SUCCESS;
    }
}
