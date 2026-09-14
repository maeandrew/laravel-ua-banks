# Contributing

Thanks for considering a contribution!

## Ground rules

- **No real IBANs.** Never put real people's or companies' IBANs into code, tests, fixtures,
  issues or docs. Generate them with `Maeandrew\UaBanks\Testing\IbanFactory` (or
  `Iban::generate()`) from real bank MFO codes.
- **Tests don't use the network.** `Http::preventStrayRequests()` is enabled for the whole suite.
  Put anything that must talk to the real NBU API in `tests/Live` (the `live` group).
- Keep the scope of v0.1 in mind: head offices and regional directorates only, Ukrainian IBANs only.
- Use [Conventional Commits](https://www.conventionalcommits.org/) in English.

## Local setup

You need either PHP 8.3+ with Composer, or Docker:

```bash
docker compose run --rm php composer install
```

(Drop the `docker compose run --rm php` prefix if you have PHP locally.)

## Checks

All of these must pass before a pull request is merged:

```bash
composer test          # Pest (unit + feature), no network
composer analyse       # Larastan, level max, no baseline
composer format:check  # Pint, laravel preset (run `composer format` to fix)
```

Database driver against PostgreSQL:

```bash
docker compose up -d pgsql
docker compose run --rm -e UA_BANKS_TEST_DB=pgsql php composer test
```

Against the real NBU API (optional, slow, may fail when the NBU is down):

```bash
composer test:live
```

## Updating the bundled snapshot

```bash
composer snapshot   # runs `ua-banks:snapshot` through Testbench
```

This rewrites `resources/data/banks.json` from the live NBU API with the same validation as
`ua-banks:sync`. Check that the file stays under 150 KB, review the diff, and commit it as
`chore(data): refresh bundled bank snapshot`.

## Test fixtures

`tests/Fixtures/nbu/typ0.json` and `typ1.json` are trimmed real NBU responses: 10 head offices,
including `300465`, `305299`, `322001` and `300012` (in liquidation), plus 3 regional aliases that
point to banks in the fixture. They ship with the package for `NbuFake`, so keep them small and
keep the reference banks in place.
