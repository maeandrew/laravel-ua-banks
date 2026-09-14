# Laravel UA Banks

[English](README.md) · **Українська**

Локальний довідник банків України на основі відкритих даних Національного банку України (НБУ)
і валідація українських IBAN та МФО для Laravel.

Звичайні IBAN-валідатори перевіряють лише формат і контрольну суму MOD 97. Вони не знають, чи
існує код банку (МФО), якому банку він належить і чи цей банк не ліквідується. Пакет закриває
саме цю прогалину:

1. **Локальний довідник банків**, синхронізований з відкритим API НБУ. Він працює, навіть коли НБУ
   недоступний, а вбудований у пакет snapshot доступний одразу після `composer require`.
2. **Простий API**: `byMfo`, `forIban`, `byEdrpou`, стан банку, код ЄДРПОУ, аліаси регіональних дирекцій.
3. **Правила валідації** з окремими повідомленнями для випадків *невірний формат / контрольна сума*,
   *невідомий банк* і *банк не працює*.

> **Застереження.** Це неофіційний пакет. Він не пов'язаний з Національним банком України і не
> схвалений ним. Дані довідника можуть бути застарілими. Не використовуйте їх як єдине джерело
> правди для фінансових рішень.

## Вимоги

- PHP 8.3+
- Laravel 11, 12 або 13

## Встановлення

```bash
composer require maeandrew/laravel-ua-banks
```

Це все. За замовчуванням пакет використовує драйвер `snapshot` із вбудованим у пакет довідником.
Міграції, конфігурація і доступ до мережі не потрібні.

За бажання опублікуйте конфігурацію і переклади:

```bash
php artisan vendor:publish --tag=ua-banks-config
php artisan vendor:publish --tag=ua-banks-translations
```

## Приклад за 30 секунд

```php
use Maeandrew\UaBanks\Facades\UaBanks;
use Maeandrew\UaBanks\Rules\UaIban;

$bank = UaBanks::byMfo('300465');
$bank->shortName;      // АТ "Ощадбанк"
$bank->edrpou;         // "00032129"
$bank->isOperating();  // true

UaBanks::forIban($request->input('iban'))?->shortName;

$request->validate([
    'iban' => ['required', UaIban::make()], // формат + контрольна сума + банк існує і працює
]);
```

## Публічний API

```php
use Maeandrew\UaBanks\Facades\UaBanks;

UaBanks::byMfo('300465');            // ?Bank. Приймає string|int і враховує аліаси регіональних дирекцій
UaBanks::byMfoOrFail('300465');      // Bank або виняток BankNotFoundException
UaBanks::forIban('UA.. .... ....');  // ?Bank. Кидає InvalidIbanException, якщо сам IBAN невалідний
UaBanks::byEdrpou('00032129');       // ?Bank
UaBanks::resolveMfo('302076');       // "300465" (МФО головного офісу) або null
UaBanks::all();                      // Collection<string, Bank>, ключ — МФО
UaBanks::operating();                // лише банки зі станом «Нормальний», які є в джерелі
UaBanks::aliases();                  // ['302076' => '300465', ...]
UaBanks::lastSyncedAt();             // ?CarbonImmutable
UaBanks::isStale();                  // дані старші за ua-banks.stale_after_days (14)
```

Фасад проксіює виклики до `Maeandrew\UaBanks\UaBanksManager` (singleton). Цей сервіс також можна
отримати через DI.

### DTO `Bank`

Об'єкт `final readonly` (не модель Eloquent):

| Властивість | Тип | Поле НБУ |
|---|---|---|
| `mfo` | `string` (6 цифр) | `MFO` |
| `nkb` | `string` | `NKB` |
| `shortName`, `fullName` | `string` | `SHORTNAME`, `FULLNAME` |
| `nameEn` | `?string` | `NAME_E` |
| `edrpou` | `string` (8 цифр, провідні нулі зберігаються) | `KOD_EDRPOU` |
| `status` | `BankStatus` | `KSTAN` |
| `statusCode`, `statusName` | `int`, `string` (сирі значення) | `KSTAN`, `N_STAN` |
| `statusSince`, `openedAt`, `closedAt` | `?CarbonImmutable` (дата, Europe/Kyiv) | `D_STAN`, `D_OPEN`, `D_CLOSE` |
| `city`, `address` | `?string` | `NP`, `ADRESS` |
| `syncedAt` | `CarbonImmutable` | — |
| `removedFromSourceAt` | `?CarbonImmutable` | — |

Методи: `isOperating()`, `isInLiquidation()`, `isRemovedFromSource()`, `toArray()`. DTO також
реалізує `JsonSerializable`.

Enum `BankStatus`: `Normal = 1`, `ExcludedFromRegister = 3`, `Liquidation = 4`, `Suspended = 5`,
`Unknown = 0`. Невідомі майбутні коди стають `Unknown`, а сирий код і назва зберігаються.
`$status->label()` повертає перекладену назву стану.

### Value object `Iban`

Не залежить від Laravel:

```php
use Maeandrew\UaBanks\Iban\Iban;

$iban = Iban::parse(' ua97 3004-6500 0260 0000 0012 3456 7 '); // прибирає пробіли, дефіси, NBSP і вирівнює регістр
$iban->value();          // "UA973004650002600000001234567"
$iban->mfo();            // "300465"
$iban->accountNumber();  // "0002600000001234567" (останні 19 символів)
$iban->formatted();      // "UA97 3004 6500 0260 0000 0012 3456 7"

Iban::tryParse($input);  // ?Iban
Iban::validate($input);  // ?IbanError: InvalidFormat | WrongCountry | WrongLength | ChecksumMismatch
```

`Iban::parse()` кидає `InvalidIbanException`. `$e->reason()` повертає `IbanError`.
Контрольна сума MOD 97 рахується частинами, тому `bcmath` і `gmp` не потрібні.

## Валідація

### `UaIban`

```php
use Maeandrew\UaBanks\Enums\BankStatus;
use Maeandrew\UaBanks\Rules\UaIban;

'iban' => ['required', UaIban::make()],                                // формат + контрольна сума + банк існує і працює
'iban' => ['required', UaIban::make()->allowUnknownBank()],            // лише формат + контрольна сума
'iban' => ['required', UaIban::make()->allowStatuses(BankStatus::Liquidation)], // приймати й банки в ліквідації
'iban' => 'required|ua_iban',                                          // рядковий аліас із тими ж налаштуваннями
```

Правило перевіряє формат → країну/довжину → контрольну суму → існування банку → дозволеність стану
і повертає **першу** помилку. Кожна помилка має власний ключ перекладу:

| Ключ | Приклад (uk) |
|---|---|
| `ua-banks::validation.iban_format` | Поле iban має бути коректним українським IBAN (UA і 27 цифр). |
| `ua-banks::validation.iban_checksum` | Поле iban містить IBAN з неправильними контрольними цифрами. |
| `ua-banks::validation.iban_unknown_bank` | Поле iban містить IBAN невідомого банку (МФО 399999). |
| `ua-banks::validation.iban_bank_not_operating` | Поле iban містить IBAN банку ПАТ "Промінвестбанк", який не працює (стан: Ліквідація). |
| `ua-banks::validation.registry_missing` | Поле iban неможливо перевірити: довідник банків недоступний. |

- За замовчуванням дозволений лише стан `Normal` (`ua-banks.validation.allowed_statuses`).
  `allowStatuses()` додає стани до цього списку.
- Правила **ніколи** не звертаються до мережі.
- Якщо даних довідника немає зовсім, поведінку визначає `ua-banks.validation.on_missing_registry`:
  `pass` (за замовчуванням, перевіряються лише формат і контрольна сума) або `fail`.

### `UaMfo`

```php
use Maeandrew\UaBanks\Rules\UaMfo;

'mfo' => ['required', UaMfo::make()],              // 6 цифр, МФО існує (з урахуванням аліасів)
'mfo' => ['required', UaMfo::make()->operating()], // ...і банк працює
'mfo' => 'required|ua_mfo',
```

Переклади є для `uk` і `en`. Опублікувати їх можна тегом `ua-banks-translations`.

## Драйвери сховища

Драйвер обирається через `ua-banks.driver` (або `UA_BANKS_DRIVER`).

### `snapshot` (за замовчуванням)

JSON-файл. Драйвер читає `storage/app/ua-banks/banks.json`, який записує `ua-banks:sync`. Якщо
цього файлу немає, використовується **вбудований** у пакет snapshot (`resources/data/banks.json`).
Розібрані дані кешуються в межах процесу і в Laravel cache (`ua-banks.cache.store`,
`ua-banks.cache.ttl`). Ключ кешу змінюється щоразу, коли файл замінюється.

### `database`

```bash
php artisan vendor:publish --tag=ua-banks-migrations
php artisan migrate
php artisan ua-banks:sync
```

Таблиці: `ua_banks` (PK `mfo`, усі колонки `Bank`, а також `raw` JSON з оригінальним записом НБУ
і `removed_from_source_at`) та `ua_bank_mfo_aliases` (`mfo` → `glmfo`). Назви таблиць і з'єднання
можна змінити в `ua-banks.database`. Моделі Eloquent є
(`Maeandrew\UaBanks\Models\BankRecord`, `MfoAlias`), але публічний API завжди повертає DTO `Bank`.

Драйвер `database` не використовує вбудований snapshot як запасний варіант. Після міграції
запустіть `ua-banks:sync`.

### Власні драйвери

```php
use Maeandrew\UaBanks\Facades\UaBanks;

UaBanks::extend('redis', fn ($app) => new MyRedisBankRepository(...));
```

Драйвер реалізує `Maeandrew\UaBanks\Contracts\BankRepository`. Щоб він підтримував
`ua-banks:sync`, реалізуйте також `Maeandrew\UaBanks\Contracts\SyncableRepository`.

## Синхронізація

```bash
php artisan ua-banks:sync            # завантажити, перевірити, атомарно записати
php artisan ua-banks:sync --dry-run  # показати різницю, нічого не записуючи
php artisan ua-banks:sync --force    # ігнорувати поріг sync.min_banks
```

1. Завантажує головні офіси (`typ=0`) і регіональні дирекції з власним МФО (`typ=1`) з
   `https://bank.gov.ua/NBU_BankInfo/get_data_branch?typ=…&json`. За замовчуванням: таймаут 20 с,
   3 повторні спроби із затримками 1/3/9 с і власний `User-Agent`.
2. **Перевіряє відповідь до будь-якого запису.** Це має бути JSON-масив щонайменше з
   `sync.min_banks` (50) головних офісів. Кожен запис має містити валідні `MFO`, `SHORTNAME` і `KSTAN`,
   а МФО не повинні повторюватися. Некритичні проблеми, як-от погана дата, дають лише
   попередження, а поле стає `null`.
3. Замінює дані **атомарно**: для `database` — у транзакції, для `snapshot` — через тимчасовий
   файл і `rename`. Банки, які зникли з джерела, **не видаляються**: їм ставиться
   `removed_from_source_at`.
4. У разі будь-якої помилки (мережа, формат, поріг) старі дані лишаються недоторканими. Команда
   пише помилку в лог, диспатчить `BankSyncFailed` і завершується з ненульовим кодом.

### Планувальник

```php
// config/ua-banks.php
'schedule' => [
    'enabled' => true,                // за замовчуванням false
    'cron' => '17 4 * * *',           // щодня о 04:17
    'timezone' => 'Europe/Kyiv',
],
```

Якщо планувальник увімкнено, пакет сам реєструє `ua-banks:sync` з `withoutOverlapping()` і
`onOneServer()`.

## Події

| Подія | Дані |
|---|---|
| `Maeandrew\UaBanks\Events\BanksSynced` | `added`, `updated`, `removed`, `warnings` |
| `Maeandrew\UaBanks\Events\BankStatusChanged` | `bank`, `oldStatus`, `newStatus`, `oldStatusCode`, `newStatusCode`. Одна подія на кожну зміну KSTAN; на першій синхронізації подій немає |
| `Maeandrew\UaBanks\Events\BankSyncFailed` | `exception`, `driver` |

```php
Event::listen(BankStatusChanged::class, function (BankStatusChanged $event) {
    if ($event->newStatus === BankStatus::Liquidation) {
        // повідомити клієнтів із рахунками в $event->bank
    }
});
```

## Свіжість даних

`UaBanks::lastSyncedAt()` повертає час останньої синхронізації. Для вбудованого snapshot це його
`generated_at`. `UaBanks::isStale()` повертає `true`, якщо цей час старший за
`ua-banks.stale_after_days` (14) або якщо даних немає. Вбудований snapshot свіжий настільки, наскільки
свіжий встановлений реліз пакета, тож якщо свіжість важлива, заплануйте `ua-banks:sync`.

## Тестування у вашому застосунку

Генеруйте синтетичні IBAN із правильними контрольними цифрами для реальних кодів банків.
Ніколи не використовуйте реальні IBAN людей.

```php
use Maeandrew\UaBanks\Testing\IbanFactory;

IbanFactory::make('305299');                 // Iban із випадковим номером рахунку
IbanFactory::string('300465', '26001234567'); // "UA..."
IbanFactory::withInvalidChecksum('322001');  // неправильні контрольні цифри
```

Щоб тестувати код, який запускає `ua-banks:sync`, без мережі, підмініть НБУ фікстурами пакета.
Це скорочені справжні відповіді НБУ: 10 головних офісів, серед них `300465`, `305299`, `322001` і
`300012` (у ліквідації), плюс 3 регіональні аліаси:

```php
use Maeandrew\UaBanks\Testing\NbuFake;

NbuFake::fake();                                    // Http::fake() з фікстурами пакета
$this->artisan('ua-banks:sync', ['--force' => true])->assertSuccessful();

NbuFake::fake(typ0: json_encode($myRecords));       // власні тіла відповідей
NbuFake::unavailable(503);                          // імітація недоступності НБУ
NbuFake::fixturePath('typ0.json');                  // шлях до фікстури для власного Http::fake()
```

`--force` потрібен, бо у фікстурах менше ніж 50 банків. Або зменште `ua-banks.sync.min_banks` у тестах.

## Конфігурація

Див. [`config/ua-banks.php`](config/ua-banks.php). Кожен ключ має коментар: `driver`,
`source.{base_url,timeout,retries,backoff,user_agent}`, `sync.min_banks`, `snapshot.path`,
`database.{connection,tables}`, `cache.{store,ttl}`, `stale_after_days`,
`schedule.{enabled,cron,timezone}`, `validation.{on_missing_registry,allowed_statuses}`.

## Обмеження

- Покриваються лише головні офіси (`typ=0`) і регіональні дирекції з власним МФО (`typ=1`).
  Відділення, представництва і закордонні філії — поза межами пакета.
- Лише українські IBAN. Без SWIFT/BIC і курсів валют.
- Валідний IBAN робочого банку не доводить, що рахунок існує.
- Стани банків актуальні настільки, наскільки свіжа ваша остання синхронізація (або вбудований snapshot).

## Джерело даних

[Національний банк України: відкриті дані довідника банків](https://bank.gov.ua/ua/open-data)
(`NBU_BankInfo/get_data_branch`). Дані публікує НБУ. Пакет лише завантажує, перевіряє і кешує їх.

## Розробка

У репозиторії є Docker-оточення, тож локальний PHP не потрібен:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer test           # Pest, без мережі
docker compose run --rm php composer analyse        # Larastan, рівень max
docker compose run --rm php composer format:check   # Pint
docker compose run --rm php composer test:live      # запити до реального API НБУ
docker compose up -d pgsql && docker compose run --rm -e UA_BANKS_TEST_DB=pgsql php composer test
docker compose run --rm php composer snapshot       # перегенерувати resources/data/banks.json
```

Див. [CONTRIBUTING.md](CONTRIBUTING.md).

## Ліцензія

MIT © Andrii Mei. Див. [LICENSE.md](LICENSE.md).
