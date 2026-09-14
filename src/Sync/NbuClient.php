<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks\Sync;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use JsonException;
use Maeandrew\UaBanks\Exceptions\SyncFailedException;
use Throwable;

/**
 * Downloads bank records from the NBU open data API (`get_data_branch`).
 */
final class NbuClient
{
    public const int TYPE_HEAD_OFFICE = 0;

    public const int TYPE_REGIONAL_DIRECTORATE = 1;

    /**
     * @param  int  $retries  additional attempts after the first failed one
     * @param  list<int|float>  $backoff  delay in seconds before each retry; the last value repeats
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl = 'https://bank.gov.ua/NBU_BankInfo/get_data_branch',
        private readonly int $timeout = 20,
        private readonly int $retries = 3,
        private readonly array $backoff = [1, 3, 9],
        private readonly string $userAgent = 'maeandrew/laravel-ua-banks',
    ) {}

    public function url(int $type): string
    {
        return $this->baseUrl.(str_contains($this->baseUrl, '?') ? '&' : '?').'typ='.$type.'&json';
    }

    /**
     * Returns the decoded JSON body (not validated).
     *
     * @throws SyncFailedException
     */
    public function fetch(int $type): mixed
    {
        $url = $this->url($type);

        try {
            $response = $this->http
                ->withUserAgent($this->userAgent)
                ->acceptJson()
                ->timeout($this->timeout)
                ->retry(
                    $this->retries + 1,
                    fn (int $attempt): int => $this->delayMs($attempt),
                    fn (Throwable $e): bool => $this->shouldRetry($e),
                    throw: false,
                )
                ->get($url);
        } catch (ConnectionException $e) {
            throw SyncFailedException::unreachable($url, $e);
        } catch (RequestException $e) {
            throw SyncFailedException::httpError($url, $e->response->status());
        }

        if (! $response->successful()) {
            throw SyncFailedException::httpError($url, $response->status());
        }

        $body = $response->body();

        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        try {
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SyncFailedException::invalidJson($url, $e);
        }
    }

    private function delayMs(int $attempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        $seconds = $this->backoff[$attempt - 1] ?? $this->backoff[array_key_last($this->backoff)];

        return (int) round($seconds * 1000);
    }

    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status >= 500 || in_array($status, [403, 408, 425, 429], true);
        }

        return false;
    }
}
