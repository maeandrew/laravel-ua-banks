<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regional directorate MFO (TYP=1) pointing to its head office MFO.
 *
 * @property string $mfo
 * @property string $glmfo
 */
class MfoAlias extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'mfo';

    protected $keyType = 'string';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        $connection = config('ua-banks.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    public function getTable(): string
    {
        $table = config('ua-banks.database.tables.aliases');

        return is_string($table) && $table !== '' ? $table : 'ua_bank_mfo_aliases';
    }

    /**
     * @return BelongsTo<BankRecord, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(BankRecord::class, 'glmfo', 'mfo');
    }
}
