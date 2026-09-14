# Laravel UA Banks

**English** · [Українська](README.uk.md)

A local registry of Ukrainian banks, built from the National Bank of Ukraine (NBU) open data,
plus Ukrainian IBAN and MFO validation for Laravel.

Generic IBAN validators only check the format and the MOD 97 checksum. They don't know whether
the bank code (MFO) exists, which bank it belongs to, or whether that bank is being liquidated.
This package fills that gap:

1. **A local bank registry** synchronized with the NBU open data API. It keeps working when the NBU
   is down, and a snapshot bundled with the package works right after `composer require`.
2. **A simple API**: `byMfo`, `forIban`, `byEdrpou`, bank status, EDRPOU code, regional directorate aliases.
3. **Validation rules** with separate messages for *invalid format / checksum*, *unknown bank*,
   and *bank is not operating*.

> **Disclaimer.** This is an unofficial package. It is not affiliated with or endorsed by the National
> Bank of Ukraine. Registry data may be outdated. Don't treat it as the sole source of truth for
> financial decisions.

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13

## Installation

```bash
composer require maeandrew/laravel-ua-banks
```

That's it. Out of the box the package uses the `snapshot` driver with the registry bundled in
the package. No migrations, configuration or network access are needed.

Optionally publish the config and translations:

```bash
php artisan vendor:publish --tag=ua-banks-config
php artisan vendor:publish --tag=ua-banks-translations
```

## 30-second example

```php
use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Rules\UaIban;

$bank = UaBanks::byMfo('300465');
$bank->shortName;      // АТ "Ощадбанк"
$bank->edrpou;         // "00032129"
$bank->isOperating();  // true

UaBanks::forIban($request->input('iban'))?->shortName;

$request->validate([
    'iban' => ['required', UaIban::make()], // format + checksum + bank exists and operates
]);
```

## Public API

```php
use Maeandrew\UaBanks\Facades\UaBanks;

UaBanks::byMfo('300465');            // ?Bank. Accepts string|int and resolves regional directorate aliases
UaBanks::byMfoOrFail('300465');      // Bank, or throws BankNotFoundException
UaBanks::forIban('UA.. .... ....');  // ?Bank. Throws InvalidIbanException if the IBAN itself is invalid
UaBanks::byEdrpou('00032129');       // ?Bank
UaBanks::resolveMfo('302076');       // "300465" (head office MFO), or null
UaBanks::all();                      // Collection<string, Bank>, keyed by MFO
UaBanks::operating();                // only banks with the Normal status still present in the source
UaBanks::aliases();                  // ['302076' => '300465', ...]
UaBanks::lastSyncedAt();             // ?CarbonImmutable
UaBanks::isStale();                  // older than ua-banks.stale_after_days (14)
```

The facade proxies `Maeandrew\UaBanks\UaBanksManager`, a singleton. You can also inject it
through the container.

### `Bank` DTO

A `final readonly` object (not an Eloquent model):

| Property | Type | NBU field |
|---|---|---|
| `mfo` | `string` (6 digits) | `MFO` |
| `nkb` | `string` | `NKB` |
| `shortName`, `fullName` | `string` | `SHORTNAME`, `FULLNAME` |
| `nameEn` | `?string` | `NAME_E` |
| `edrpou` | `string` (8 digits, leading zeros kept) | `KOD_EDRPOU` |
| `status` | `BankStatus` | `KSTAN` |
| `statusCode`, `statusName` | `int`, `string` (raw values) | `KSTAN`, `N_STAN` |
| `statusSince`, `openedAt`, `closedAt` | `?CarbonImmutable` (date, Europe/Kyiv) | `D_STAN`, `D_OPEN`, `D_CLOSE` |
| `city`, `address` | `?string` | `NP`, `ADRESS` |
| `syncedAt` | `CarbonImmutable` | — |
| `removedFromSourceAt` | `?CarbonImmutable` | — |

Methods: `isOperating()`, `isInLiquidation()`, `isRemovedFromSource()`, `toArray()`. The DTO also
implements `JsonSerializable`.

`BankStatus` enum: `Normal = 1`, `ExcludedFromRegister = 3`, `Liquidation = 4`, `Suspended = 5`,
`Unknown = 0`. Unknown future codes become `Unknown`, and the raw code and name are kept.
`$status->label()` returns a translated label.

### `Iban` value object

Framework-agnostic, with no Laravel dependencies:

```php
use Maeandrew\UaBanks\Iban\Iban;

$iban = Iban::parse(' ua97 3004-6500 0260 0000 0012 3456 7 '); // normalizes spaces, dashes, NBSP and case
$iban->value();          // "UA973004650002600000001234567"
$iban->mfo();            // "300465"
$iban->accountNumber();  // "0002600000001234567" (last 19 characters)
$iban->formatted();      // "UA97 3004 6500 0260 0000 0012 3456 7"

Iban::tryParse($input);  // ?Iban
Iban::validate($input);  // ?IbanError: InvalidFormat | WrongCountry | WrongLength | ChecksumMismatch
```

`Iban::parse()` throws `InvalidIbanException`. `$e->reason()` returns an `IbanError`.
The MOD 97 checksum is computed in chunks, so `bcmath` and `gmp` are not required.

## Validation

### `UaIban`

```php
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Rules\UaIban;

'iban' => ['required', UaIban::make()],                                // format + checksum + bank exists and operates
'iban' => ['required', UaIban::make()->allowUnknownBank()],            // format + checksum only
'iban' => ['required', UaIban::make()->allowStatuses(BankStatus::Liquidation)], // also accept banks in liquidation
'iban' => 'required|ua_iban',                                          // string alias, same defaults
```

The rule checks format → country/length → checksum → bank exists → status is allowed, and
reports the **first** failure. Each failure has its own translation key:

| Key | Example (en) |
|---|---|
| `ua-banks::validation.iban_format` | The iban must be a valid Ukrainian IBAN (UA followed by 27 digits). |
| `ua-banks::validation.iban_checksum` | The iban has invalid IBAN check digits. |
| `ua-banks::validation.iban_unknown_bank` | The iban belongs to an unknown bank (MFO 399999). |
| `ua-banks::validation.iban_bank_not_operating` | The iban belongs to ПАТ "Промінвестбанк", which is not operating (status: Liquidation). |
| `ua-banks::validation.registry_missing` | The iban cannot be verified because the bank registry is not available. |

- By default only `Normal` is allowed (`ua-banks.validation.allowed_statuses`). `allowStatuses()`
  adds statuses to that list.
- The rules **never** make network requests.
- If there is no registry data at all, `ua-banks.validation.on_missing_registry` decides what
  happens: `pass` (default, only format and checksum are checked) or `fail`.

### `UaMfo`

```php
use Maeandrew\UaBanks\Rules\UaMfo;

'mfo' => ['required', UaMfo::make()],              // 6 digits, exists (aliases included)
'mfo' => ['required', UaMfo::make()->operating()], // ...and the bank is operating
'mfo' => 'required|ua_mfo',
```

Translations ship in `uk` and `en`. Publish them with the `ua-banks-translations` tag.

## Storage drivers

Choose the driver with `ua-banks.driver` (or `UA_BANKS_DRIVER`).

### `snapshot` (default)

A JSON file. The driver reads `storage/app/ua-banks/banks.json`, which `ua-banks:sync` writes.
If that file doesn't exist, it falls back to the snapshot **bundled** with the package
(`resources/data/banks.json`). Parsed data is memoized per process and cached in the Laravel
cache (`ua-banks.cache.store`, `ua-banks.cache.ttl`). The cache key changes whenever the file
is replaced.

### `database`

```bash
php artisan vendor:publish --tag=ua-banks-migrations
php artisan migrate
php artisan ua-banks:sync
```

Tables: `ua_banks` (PK `mfo`, all `Bank` columns plus `raw` JSON with the original NBU record and
`removed_from_source_at`) and `ua_bank_mfo_aliases` (`mfo` → `glmfo`). You can change the names
and connection in `ua-banks.database`. Eloquent models exist
(`Maeandrew\UaBanks\Models\BankRecord`, `MfoAlias`), but the public API always returns `Bank` DTOs.

The `database` driver does not fall back to the bundled snapshot. Run `ua-banks:sync` after migrating.

### Custom drivers

```php
use Maeandrew\UaBanks\Facades\UaBanks;

UaBanks::extend('redis', fn ($app) => new MyRedisBankRepository(...));
```

A driver implements `Maeandrew\UaBanks\Contracts\BankRepository`. To support `ua-banks:sync`,
implement `Maeandrew\UaBanks\Contracts\SyncableRepository` as well.

## Synchronization

```bash
php artisan ua-banks:sync            # download, validate, store atomically
php artisan ua-banks:sync --dry-run  # show the diff, write nothing
php artisan ua-banks:sync --force    # ignore the sync.min_banks threshold
```

1. Downloads head offices (`typ=0`) and regional directorates with their own MFO (`typ=1`) from
   `https://bank.gov.ua/NBU_BankInfo/get_data_branch?typ=…&json`. Defaults: 20 s timeout,
   3 retries with a 1/3/9 s backoff, and a custom `User-Agent`.
2. **Validates the response before writing anything.** It must be a JSON array with at least
   `sync.min_banks` (50) head offices. Every record needs a valid `MFO`, `SHORTNAME` and `KSTAN`,
   and MFOs must be unique. Non-critical problems, such as a malformed date, only produce a
   warning and a `null` field.
3. Replaces the data **atomically**: a transaction for `database`, a temporary file plus `rename`
   for `snapshot`. Banks that disappear from the source are **not deleted**; they get
   `removed_from_source_at` instead.
4. On any failure (network, format, threshold) the old data stays untouched. The command logs
   the error, dispatches `BankSyncFailed` and returns a non-zero exit code.

### Scheduler

```php
// config/ua-banks.php
'schedule' => [
    'enabled' => true,                // default: false
    'cron' => '17 4 * * *',           // daily at 04:17
    'timezone' => 'Europe/Kyiv',
],
```

When enabled, the package registers `ua-banks:sync` in the scheduler itself, with
`withoutOverlapping()` and `onOneServer()`.

## Events

| Event | Payload |
|---|---|
| `Maeandrew\UaBanks\Events\BanksSynced` | `added`, `updated`, `removed`, `warnings` |
| `Maeandrew\UaBanks\Events\BankStatusChanged` | `bank`, `oldStatus`, `newStatus`, `oldStatusCode`, `newStatusCode`. One event per KSTAN change; none on the first sync |
| `Maeandrew\UaBanks\Events\BankSyncFailed` | `exception`, `driver` |

```php
Event::listen(BankStatusChanged::class, function (BankStatusChanged $event) {
    if ($event->newStatus === BankStatus::Liquidation) {
        // notify customers with accounts in $event->bank
    }
});
```

## Data freshness

`UaBanks::lastSyncedAt()` returns when the data was last synchronized. For the bundled snapshot,
this is its `generated_at`. `UaBanks::isStale()` is `true` when that time is older than
`ua-banks.stale_after_days` (14), or when there is no data. The bundled snapshot is only as
fresh as the package release you installed, so schedule `ua-banks:sync` if freshness matters.

## Testing in your application

Generate synthetic IBANs with valid check digits for real bank codes. Never use real people's IBANs.

```php
use Maeandrew\UaBanks\Testing\IbanFactory;

IbanFactory::make('305299');                 // Iban with a random account number
IbanFactory::string('300465', '26001234567'); // "UA..."
IbanFactory::withInvalidChecksum('322001');  // wrong check digits
```

To test code that runs `ua-banks:sync` without network access, fake the NBU with the fixtures
shipped with the package (trimmed real NBU responses: 10 head offices, including `300465`,
`305299`, `322001` and `300012` (in liquidation), plus 3 regional aliases):

```php
use Maeandrew\UaBanks\Testing\NbuFake;

NbuFake::fake();                                    // Http::fake() with the package fixtures
$this->artisan('ua-banks:sync', ['--force' => true])->assertSuccessful();

NbuFake::fake(typ0: json_encode($myRecords));       // your own response bodies
NbuFake::unavailable(503);                          // simulate an NBU outage
NbuFake::fixturePath('typ0.json');                  // raw fixture path, for Http::fake() yourself
```

`--force` is needed because the fixtures contain fewer than 50 banks. Alternatively, lower
`ua-banks.sync.min_banks` in tests.

## Configuration

See [`config/ua-banks.php`](config/ua-banks.php). Every key is commented: `driver`,
`source.{base_url,timeout,retries,backoff,user_agent}`, `sync.min_banks`, `snapshot.path`,
`database.{connection,tables}`, `cache.{store,ttl}`, `stale_after_days`,
`schedule.{enabled,cron,timezone}`, `validation.{on_missing_registry,allowed_statuses}`.

## Limitations

- Only head offices (`typ=0`) and regional directorates with their own MFO (`typ=1`) are covered.
  Branches, representative offices and foreign branches are out of scope.
- Only Ukrainian IBANs. No SWIFT/BIC, no exchange rates.
- A valid IBAN for an operating bank doesn't prove the account exists.
- Bank statuses are only as fresh as your last sync (or the bundled snapshot).

## Data source

[National Bank of Ukraine: bank registry open data](https://bank.gov.ua/ua/open-data)
(`NBU_BankInfo/get_data_branch`). The data is published by the NBU. This package only downloads,
validates and caches it.

## Development

The repository includes a Docker environment, so no local PHP is needed:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer test           # Pest, no network
docker compose run --rm php composer analyse        # Larastan, level max
docker compose run --rm php composer format:check   # Pint
docker compose run --rm php composer test:live      # hits the real NBU API
docker compose up -d pgsql && docker compose run --rm -e UA_BANKS_TEST_DB=pgsql php composer test
docker compose run --rm php composer snapshot       # regenerate resources/data/banks.json
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT © Andrii Mei. See [LICENSE.md](LICENSE.md).
