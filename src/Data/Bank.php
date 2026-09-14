<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Data;

use Carbon\CarbonImmutable;
use JsonSerializable;
use Maeandrew\UaBanks\Enums\BankStatus;

/**
 * A bank head office from the NBU registry.
 */
final readonly class Bank implements JsonSerializable
{
    public const string TIMEZONE = 'Europe/Kyiv';

    public function __construct(
        public string $mfo,
        public string $nkb,
        public string $shortName,
        public string $fullName,
        public ?string $nameEn,
        public string $edrpou,
        public BankStatus $status,
        public int $statusCode,
        public string $statusName,
        public ?CarbonImmutable $statusSince,
        public ?CarbonImmutable $openedAt,
        public ?CarbonImmutable $closedAt,
        public ?string $city,
        public ?string $address,
        public CarbonImmutable $syncedAt,
        public ?CarbonImmutable $removedFromSourceAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Output of {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        $statusCode = self::int($data['status_code'] ?? 0);

        return new self(
            mfo: self::string($data['mfo'] ?? ''),
            nkb: self::string($data['nkb'] ?? ''),
            shortName: self::string($data['short_name'] ?? ''),
            fullName: self::string($data['full_name'] ?? ''),
            nameEn: self::nullableString($data['name_en'] ?? null),
            edrpou: self::string($data['edrpou'] ?? ''),
            status: BankStatus::fromCode($statusCode),
            statusCode: $statusCode,
            statusName: self::string($data['status_name'] ?? ''),
            statusSince: self::date($data['status_since'] ?? null),
            openedAt: self::date($data['opened_at'] ?? null),
            closedAt: self::date($data['closed_at'] ?? null),
            city: self::nullableString($data['city'] ?? null),
            address: self::nullableString($data['address'] ?? null),
            syncedAt: self::dateTime($data['synced_at'] ?? null) ?? CarbonImmutable::now(),
            removedFromSourceAt: self::dateTime($data['removed_from_source_at'] ?? null),
        );
    }

    public function isOperating(): bool
    {
        return $this->status === BankStatus::Normal;
    }

    public function isInLiquidation(): bool
    {
        return $this->status === BankStatus::Liquidation;
    }

    public function isRemovedFromSource(): bool
    {
        return $this->removedFromSourceAt !== null;
    }

    public function withSyncState(CarbonImmutable $syncedAt, ?CarbonImmutable $removedFromSourceAt): self
    {
        return new self(
            $this->mfo, $this->nkb, $this->shortName, $this->fullName, $this->nameEn, $this->edrpou,
            $this->status, $this->statusCode, $this->statusName, $this->statusSince, $this->openedAt,
            $this->closedAt, $this->city, $this->address, $syncedAt, $removedFromSourceAt,
        );
    }

    /**
     * Registry fields only (without synchronization timestamps); used to detect changes.
     *
     * @return array<string, string|int|null>
     */
    public function registryAttributes(): array
    {
        return [
            'mfo' => $this->mfo,
            'nkb' => $this->nkb,
            'short_name' => $this->shortName,
            'full_name' => $this->fullName,
            'name_en' => $this->nameEn,
            'edrpou' => $this->edrpou,
            'status' => $this->status->value,
            'status_code' => $this->statusCode,
            'status_name' => $this->statusName,
            'status_since' => $this->statusSince?->format('Y-m-d'),
            'opened_at' => $this->openedAt?->format('Y-m-d'),
            'closed_at' => $this->closedAt?->format('Y-m-d'),
            'city' => $this->city,
            'address' => $this->address,
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return $this->registryAttributes() + [
            'synced_at' => $this->syncedAt->utc()->format(DATE_ATOM),
            'removed_from_source_at' => $this->removedFromSourceAt?->utc()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($value->format('Y-m-d'), self::TIMEZONE)->startOfDay();
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), self::TIMEZONE);

        return $date instanceof CarbonImmutable ? $date : null;
    }

    private static function dateTime(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }
}
