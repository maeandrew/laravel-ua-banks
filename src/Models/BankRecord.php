<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Maeandrew\UaBanks\Data\Bank;

/**
 * Eloquent model for the `database` driver. The public API returns {@see Bank} DTOs instead.
 *
 * @property string $mfo
 * @property string $nkb
 * @property string $short_name
 * @property string $full_name
 * @property string|null $name_en
 * @property string $edrpou
 * @property int $status
 * @property int $status_code
 * @property string $status_name
 * @property string|null $status_since
 * @property string|null $opened_at
 * @property string|null $closed_at
 * @property string|null $city
 * @property string|null $address
 * @property array<string, mixed>|null $raw
 * @property string $synced_at
 * @property string|null $removed_from_source_at
 */
class BankRecord extends Model
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
        $table = config('ua-banks.database.tables.banks');

        return is_string($table) && $table !== '' ? $table : 'ua_banks';
    }

    /**
     * @return HasMany<MfoAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(MfoAlias::class, 'glmfo', 'mfo');
    }

    public function toBank(): Bank
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $this->getAttributes();

        return Bank::fromArray($attributes);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
        ];
    }
}
