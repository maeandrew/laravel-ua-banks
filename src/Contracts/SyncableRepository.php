<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Contracts;

use Maeandrew\UaBanks\Sync\Registry;

/**
 * A repository that `ua-banks:sync` can write to.
 */
interface SyncableRepository extends BankRepository
{
    /**
     * Data previously written by {@see store()}, or null when nothing was synchronized yet.
     * Bundled fallback data must not be returned here.
     */
    public function stored(): ?Registry;

    /**
     * Atomically replaces the stored registry. Either all data is written or nothing is.
     */
    public function store(Registry $registry): void;
}
