<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Sync;

use Carbon\CarbonImmutable;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Enums\BankStatus;

/**
 * Maps raw NBU `get_data_branch` records to domain objects.
 *
 * Expects records that already passed {@see RegistryValidator}; non-critical problems
 * (e.g. malformed dates) become null values and are reported as warnings.
 */
final class RecordMapper
{
    /** @var list<string> */
    private array $warnings = [];

    public static function normalizeMfo(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0 && $value <= 999_999) {
            return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
        }

        if (is_string($value) && preg_match('/^\d{1,6}$/', trim($value)) === 1) {
            return str_pad(trim($value), 6, '0', STR_PAD_LEFT);
        }

        return null;
    }

    public static function normalizeEdrpou(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0 && $value <= 99_999_999) {
            return str_pad((string) $value, 8, '0', STR_PAD_LEFT);
        }

        if (is_string($value) && preg_match('/^\d{1,8}$/', trim($value)) === 1) {
            return str_pad(trim($value), 8, '0', STR_PAD_LEFT);
        }

        return null;
    }

    public static function statusCode(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d+$/', trim($value)) === 1 ? (int) trim($value) : null;
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    public function toBank(array $record, CarbonImmutable $syncedAt): Bank
    {
        $mfo = self::normalizeMfo($record['MFO'] ?? null) ?? '';
        $statusCode = self::statusCode($record['KSTAN'] ?? null) ?? BankStatus::Unknown->value;
        $shortName = $this->string($record, 'SHORTNAME') ?? '';

        $edrpou = self::normalizeEdrpou($record['KOD_EDRPOU'] ?? null);

        if ($edrpou === null) {
            $this->warn($mfo, 'KOD_EDRPOU', $record['KOD_EDRPOU'] ?? null);
        }

        $status = BankStatus::fromCode($statusCode);

        if ($status === BankStatus::Unknown) {
            $this->warnings[] = sprintf('MFO %s: unknown KSTAN code %d, mapped to Unknown.', $mfo, $statusCode);
        }

        return new Bank(
            mfo: $mfo,
            nkb: $this->string($record, 'NKB') ?? '',
            shortName: $shortName,
            fullName: $this->string($record, 'FULLNAME') ?? $shortName,
            nameEn: $this->string($record, 'NAME_E'),
            edrpou: $edrpou ?? '',
            status: $status,
            statusCode: $statusCode,
            statusName: $this->string($record, 'N_STAN') ?? '',
            statusSince: $this->date($record, 'D_STAN', $mfo),
            openedAt: $this->date($record, 'D_OPEN', $mfo),
            closedAt: $this->date($record, 'D_CLOSE', $mfo),
            city: $this->string($record, 'NP'),
            address: $this->string($record, 'ADRESS'),
            syncedAt: $syncedAt,
        );
    }

    /**
     * @param  array<array-key, mixed>  $typ0  head office records (TYP=0)
     * @param  array<array-key, mixed>  $typ1  regional directorate records (TYP=1)
     */
    public function toRegistry(array $typ0, array $typ1, CarbonImmutable $syncedAt): Registry
    {
        $this->warnings = [];
        $banks = [];
        $raw = [];

        foreach ($typ0 as $record) {
            if (! is_array($record)) {
                continue;
            }

            $bank = $this->toBank($record, $syncedAt);
            $banks[$bank->mfo] = $bank;
            $raw[$bank->mfo] = $this->stringKeys($record);
        }

        ksort($banks, SORT_STRING);

        $aliases = [];

        foreach ($typ1 as $record) {
            if (! is_array($record)) {
                continue;
            }

            $mfo = self::normalizeMfo($record['MFO'] ?? null);
            $glmfo = self::normalizeMfo($record['GLMFO'] ?? null);

            if ($mfo === null || $glmfo === null) {
                continue;
            }

            if (isset($banks[$mfo])) {
                $this->warnings[] = sprintf('Alias MFO %s is also a head office MFO; alias ignored.', $mfo);

                continue;
            }

            if (! isset($banks[$glmfo])) {
                $this->warnings[] = sprintf('Alias MFO %s points to unknown head office %s; alias ignored.', $mfo, $glmfo);

                continue;
            }

            $aliases[$mfo] = $glmfo;
        }

        ksort($aliases, SORT_STRING);

        return new Registry($banks, $aliases, $syncedAt, $raw, $this->warnings);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function string(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function date(array $record, string $key, string $mfo): ?CarbonImmutable
    {
        $value = $record[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($value), $m) === 1
            && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $date = CarbonImmutable::createFromFormat('!d.m.Y', trim($value), Bank::TIMEZONE);

            if ($date instanceof CarbonImmutable) {
                return $date;
            }
        }

        $this->warn($mfo, $key, $value);

        return null;
    }

    private function warn(string $mfo, string $field, mixed $value): void
    {
        $this->warnings[] = sprintf(
            'MFO %s: invalid %s value %s, stored as null.',
            $mfo,
            $field,
            json_encode($value, JSON_UNESCAPED_UNICODE) ?: '?',
        );
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @return array<string, mixed>
     */
    private function stringKeys(array $record): array
    {
        $result = [];

        foreach ($record as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
