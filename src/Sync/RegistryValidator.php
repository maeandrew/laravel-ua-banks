<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Sync;

use Maeandrew\UaBanks\Exceptions\SyncFailedException;

/**
 * Checks raw NBU responses before anything is written. Throws on critical problems only.
 */
final class RegistryValidator
{
    private const int MAX_REPORTED_ERRORS = 10;

    public function __construct(private readonly int $minBanks = 50) {}

    /**
     * @return array{0: list<mixed>, 1: list<mixed>} the validated head office and alias lists
     *
     * @throws SyncFailedException
     */
    public function validate(mixed $typ0, mixed $typ1, bool $force = false): array
    {
        $errors = [];

        if (! is_array($typ0) || ! array_is_list($typ0)) {
            throw SyncFailedException::invalidRegistry(['head office response (typ=0) is not a JSON array']);
        }

        if (! is_array($typ1) || ! array_is_list($typ1)) {
            throw SyncFailedException::invalidRegistry(['regional directorate response (typ=1) is not a JSON array']);
        }

        if (! $force && count($typ0) < $this->minBanks) {
            throw SyncFailedException::invalidRegistry([sprintf(
                'only %d head offices received, at least %d expected (use --force to ignore)',
                count($typ0),
                $this->minBanks,
            )]);
        }

        $seen = [];

        foreach ($typ0 as $index => $record) {
            $this->validateRecord($record, "typ=0 record #{$index}", $errors, $seen);
        }

        $seenAliases = [];

        foreach ($typ1 as $index => $record) {
            $label = "typ=1 record #{$index}";

            if ($this->validateRecord($record, $label, $errors, $seenAliases)
                && is_array($record) && RecordMapper::normalizeMfo($record['GLMFO'] ?? null) === null) {
                $errors[] = "{$label}: missing or invalid GLMFO";
            }
        }

        if ($errors !== []) {
            $total = count($errors);
            $errors = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);

            if ($total > self::MAX_REPORTED_ERRORS) {
                $errors[] = sprintf('and %d more', $total - self::MAX_REPORTED_ERRORS);
            }

            throw SyncFailedException::invalidRegistry($errors);
        }

        return [$typ0, $typ1];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, true>  $seen
     */
    private function validateRecord(mixed $record, string $label, array &$errors, array &$seen): bool
    {
        if (! is_array($record)) {
            $errors[] = "{$label}: not an object";

            return false;
        }

        $valid = true;
        $mfo = RecordMapper::normalizeMfo($record['MFO'] ?? null);

        if ($mfo === null) {
            $errors[] = "{$label}: missing or invalid MFO";
            $valid = false;
        } elseif (isset($seen[$mfo])) {
            $errors[] = "{$label}: duplicate MFO {$mfo}";
            $valid = false;
        } else {
            $seen[$mfo] = true;
        }

        if (! is_string($record['SHORTNAME'] ?? null) || trim($record['SHORTNAME']) === '') {
            $errors[] = "{$label}: missing SHORTNAME";
            $valid = false;
        }

        if (RecordMapper::statusCode($record['KSTAN'] ?? null) === null) {
            $errors[] = "{$label}: missing or invalid KSTAN";
            $valid = false;
        }

        return $valid;
    }
}
