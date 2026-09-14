# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - Unreleased

### Added

- `Iban` value object for Ukrainian IBANs: normalization, format, length and ISO 13616 MOD 97
  validation with typed `IbanError` reasons. No `bcmath`/`gmp` required.
- `Bank` readonly DTO and `BankStatus` enum with translated labels. Unknown NBU status codes are
  mapped to `Unknown`, and the raw code and name are kept.
- `UaBanks` facade / `UaBanksManager`: `byMfo`, `byMfoOrFail`, `forIban`, `byEdrpou`, `resolveMfo`,
  `all`, `operating`, `aliases`, `lastSyncedAt`, `isStale`, plus custom drivers via `extend`.
- Regional directorate MFO aliases (`typ=1`) that resolve to the head office bank.
- `snapshot` driver (default) with the registry bundled in the package (generated from real NBU data),
  storage file priority, per-process memoization and a versioned Laravel cache.
- `database` driver with a publishable migration (`ua_banks`, `ua_bank_mfo_aliases`), the raw NBU
  record and `removed_from_source_at`.
- `ua-banks:sync` command with response validation, atomic writes, `--dry-run`, `--force`,
  retries with backoff and optional scheduler registration.
- `ua-banks:snapshot` maintainer command to regenerate the bundled snapshot.
- Events: `BanksSynced`, `BankStatusChanged`, `BankSyncFailed`.
- Validation rules `UaIban` (`allowUnknownBank()`, `allowStatuses()`) and `UaMfo` (`operating()`),
  string aliases `ua_iban` and `ua_mfo`, and Ukrainian and English translations.
- Testing helpers `IbanFactory` and `NbuFake`.
