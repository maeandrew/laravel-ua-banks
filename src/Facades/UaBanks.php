<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Facades;

use Illuminate\Support\Facades\Facade;
use Maeandrew\UaBanks\UaBanksManager;

/**
 * @method static \Maeandrew\UaBanks\Data\Bank|null byMfo(string|int $mfo)
 * @method static \Maeandrew\UaBanks\Data\Bank byMfoOrFail(string|int $mfo)
 * @method static \Maeandrew\UaBanks\Data\Bank|null forIban(string|\Maeandrew\UaBanks\Iban\Iban $iban)
 * @method static \Maeandrew\UaBanks\Data\Bank|null byEdrpou(string|int $edrpou)
 * @method static string|null resolveMfo(string|int $mfo)
 * @method static \Illuminate\Support\Collection<string, \Maeandrew\UaBanks\Data\Bank> all()
 * @method static \Illuminate\Support\Collection<string, \Maeandrew\UaBanks\Data\Bank> operating()
 * @method static array<string, string> aliases()
 * @method static \Carbon\CarbonImmutable|null lastSyncedAt()
 * @method static bool isStale()
 * @method static bool hasData()
 * @method static \Maeandrew\UaBanks\Contracts\BankRepository repository(?string $driver = null)
 * @method static string getDefaultDriver()
 * @method static \Maeandrew\UaBanks\UaBanksManager extend(string $driver, \Closure $callback)
 * @method static \Maeandrew\UaBanks\UaBanksManager forgetRepositories()
 *
 * @see UaBanksManager
 */
final class UaBanks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UaBanksManager::class;
    }
}
