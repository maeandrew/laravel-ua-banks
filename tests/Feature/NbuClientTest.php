<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Maeandrew\UaBanks\Exceptions\SyncFailedException;
use Maeandrew\UaBanks\Sync\NbuClient;
use Maeandrew\UaBanks\Tests\TestCase;

function nbuClient(int $retries = 3, array $backoff = [1, 3, 9]): NbuClient
{
    return new NbuClient(app(Factory::class), retries: $retries, backoff: $backoff, timeout: 20);
}

it('always requests JSON with an explicit typ', function () {
    Http::fake(['*' => Http::response('[]')]);

    nbuClient()->fetch(NbuClient::TYPE_HEAD_OFFICE);
    nbuClient()->fetch(NbuClient::TYPE_REGIONAL_DIRECTORATE);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://bank.gov.ua/NBU_BankInfo/get_data_branch?typ=0&json'
        && $r->method() === 'GET'
        && $r->hasHeader('Accept', 'application/json')
        && $r->hasHeader('User-Agent', 'maeandrew/laravel-ua-banks'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://bank.gov.ua/NBU_BankInfo/get_data_branch?typ=1&json');
});

it('decodes the JSON body (including a UTF-8 BOM)', function () {
    Http::fake(['*' => Http::response("\xEF\xBB\xBF".file_get_contents(TestCase::fixturePath('typ1.json')))]);

    expect(nbuClient()->fetch(1))->toHaveCount(3);
});

it('retries with a 1/3/9 second backoff by default', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('down', 503)]);

    expect(fn () => nbuClient()->fetch(0))->toThrow(SyncFailedException::class, 'HTTP 503');

    Http::assertSentCount(4);
    Sleep::assertSequence([
        Sleep::for(1000)->milliseconds(),
        Sleep::for(3000)->milliseconds(),
        Sleep::for(9000)->milliseconds(),
    ]);
});

it('does not retry client errors other than 403/408/425/429', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('not found', 404)]);

    expect(fn () => nbuClient()->fetch(0))->toThrow(SyncFailedException::class, 'HTTP 404');

    Http::assertSentCount(1);
});

it('retries 403 responses', function () {
    Sleep::fake();
    Http::fake(['*' => Http::sequence()->push('forbidden', 403)->push('[1]')]);

    expect(nbuClient()->fetch(0))->toBe([1]);
    Http::assertSentCount(2);
});

it('wraps connection failures', function () {
    Sleep::fake();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(fn () => nbuClient(retries: 1)->fetch(0))->toThrow(SyncFailedException::class, 'unreachable');
});
