<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The NBU registry could not be downloaded, decoded or did not pass validation.
 */
final class SyncFailedException extends RuntimeException implements UaBanksException
{
    public static function unreachable(string $url, Throwable $previous): self
    {
        return new self(sprintf('NBU source %s is unreachable: %s', $url, $previous->getMessage()), 0, $previous);
    }

    public static function httpError(string $url, int $status): self
    {
        return new self(sprintf('NBU source %s responded with HTTP %d.', $url, $status));
    }

    public static function invalidJson(string $url, Throwable $previous): self
    {
        return new self(sprintf('NBU source %s returned invalid JSON: %s', $url, $previous->getMessage()), 0, $previous);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function invalidRegistry(array $errors): self
    {
        return new self('NBU registry failed validation: '.implode('; ', $errors));
    }

    public static function unsupportedRepository(string $driver): self
    {
        return new self(sprintf('The "%s" ua-banks driver does not support synchronization.', $driver));
    }
}
