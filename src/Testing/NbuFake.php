<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Testing;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fakes the NBU API with the fixtures shipped with the package (trimmed real NBU responses:
 * 10 head offices incl. 300465, 305299, 322001, 300012 in liquidation, and 3 regional aliases).
 *
 * The fixtures contain fewer banks than the default `sync.min_banks`, so run the sync with
 * `--force` or lower the threshold in tests.
 */
final class NbuFake
{
    public static function fixturePath(string $name = 'typ0.json'): string
    {
        $path = dirname(__DIR__, 2).'/tests/Fixtures/nbu/'.$name;

        if (! is_file($path)) {
            throw new RuntimeException("NBU fixture {$name} does not exist.");
        }

        return $path;
    }

    /**
     * @param  string|null  $typ0  JSON body for head offices (defaults to the package fixture)
     * @param  string|null  $typ1  JSON body for regional directorates (defaults to the package fixture)
     */
    public static function fake(?string $typ0 = null, ?string $typ1 = null): void
    {
        Http::fake([
            '*get_data_branch?typ=0*' => Http::response($typ0 ?? self::read('typ0.json'), 200, ['Content-Type' => 'application/json; charset=utf-8']),
            '*get_data_branch?typ=1*' => Http::response($typ1 ?? self::read('typ1.json'), 200, ['Content-Type' => 'application/json; charset=utf-8']),
        ]);
    }

    /**
     * Makes every NBU request fail with the given HTTP status.
     */
    public static function unavailable(int $status = 503): void
    {
        Http::fake(['*get_data_branch*' => Http::response('', $status)]);
    }

    private static function read(string $name): string
    {
        $contents = file_get_contents(self::fixturePath($name));

        if ($contents === false) {
            throw new RuntimeException("Unable to read NBU fixture {$name}.");
        }

        return $contents;
    }
}
