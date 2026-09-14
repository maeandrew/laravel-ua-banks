<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Repositories;

use Carbon\CarbonImmutable;
use JsonException;
use Maeandrew\UaBanks\Data\Bank;
use Maeandrew\UaBanks\Sync\Registry;
use RuntimeException;

/**
 * Reads and writes the snapshot JSON format:
 * {"schema": 1, "generated_at": "...", "source": "...", "banks": [...], "aliases": {...}}.
 */
final class SnapshotFile
{
    public const int SCHEMA = 1;

    public static function encode(Registry $registry, string $source): string
    {
        $data = [
            'schema' => self::SCHEMA,
            'generated_at' => $registry->generatedAt->utc()->format(DATE_ATOM),
            'source' => $source,
            'banks' => array_values(array_map(static fn (Bank $bank): array => $bank->toArray(), $registry->banks)),
            'aliases' => (object) $registry->aliases,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException when the file is missing or is not a valid snapshot
     */
    public static function read(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read ua-banks snapshot {$path}.");
        }

        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Snapshot {$path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || ! is_array($data['banks'] ?? null)) {
            throw new RuntimeException("Snapshot {$path} has an unsupported format.");
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data  output of {@see read()}
     */
    public static function decode(array $data): Registry
    {
        $banks = [];

        foreach (is_array($data['banks'] ?? null) ? $data['banks'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $bank = Bank::fromArray($row);

            if ($bank->mfo !== '') {
                $banks[$bank->mfo] = $bank;
            }
        }

        ksort($banks, SORT_STRING);

        $aliases = [];

        foreach (is_array($data['aliases'] ?? null) ? $data['aliases'] : [] as $mfo => $glmfo) {
            if (is_scalar($glmfo)) {
                $aliases[str_pad((string) $mfo, 6, '0', STR_PAD_LEFT)] = (string) $glmfo;
            }
        }

        $generatedAt = is_string($data['generated_at'] ?? null)
            ? CarbonImmutable::parse($data['generated_at'])->utc()
            : CarbonImmutable::createFromTimestampUTC(0);

        return new Registry($banks, $aliases, $generatedAt);
    }

    /**
     * Writes the file atomically: a temporary file in the same directory is renamed over the target.
     */
    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory for ua-banks snapshot {$path}.");
        }

        $temporary = $directory.DIRECTORY_SEPARATOR.'.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to write ua-banks snapshot {$temporary}.");
        }

        @chmod($temporary, 0664);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to move ua-banks snapshot into place at {$path}.");
        }
    }
}
