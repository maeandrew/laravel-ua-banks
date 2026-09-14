<?php

declare(strict_types=1);

namespace Maeandrew\UaBanks;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\InvokableValidationRule;
use Illuminate\Validation\Validator;
use Maeandrew\UaBanks\Console\SnapshotCommand;
use Maeandrew\UaBanks\Console\SyncCommand;
use Maeandrew\UaBanks\Contracts\BankRepository;
use Maeandrew\UaBanks\Rules\UaIban;
use Maeandrew\UaBanks\Rules\UaMfo;
use Maeandrew\UaBanks\Sync\NbuClient;
use Maeandrew\UaBanks\Sync\RecordMapper;
use Maeandrew\UaBanks\Sync\RegistryValidator;
use Maeandrew\UaBanks\Sync\Synchronizer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class UaBanksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ua-banks')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigration('create_ua_banks_tables')
            ->hasCommands([SyncCommand::class, SnapshotCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(UaBanksManager::class, static fn (Container $app): UaBanksManager => new UaBanksManager($app));
        $this->app->bind(BankRepository::class, static fn (Container $app): BankRepository => $app->make(UaBanksManager::class)->repository());

        $this->app->bind(NbuClient::class, static function (Container $app): NbuClient {
            $config = $app->make(Config::class);
            $backoff = $config->get('ua-banks.source.backoff', [1, 3, 9]);

            return new NbuClient(
                http: $app->make(HttpFactory::class),
                baseUrl: self::string($config->get('ua-banks.source.base_url'), 'https://bank.gov.ua/NBU_BankInfo/get_data_branch'),
                timeout: self::int($config->get('ua-banks.source.timeout'), 20),
                retries: self::int($config->get('ua-banks.source.retries'), 3),
                backoff: is_array($backoff) ? array_values(array_map(floatval(...), array_filter($backoff, is_numeric(...)))) : [],
                userAgent: self::string($config->get('ua-banks.source.user_agent'), 'maeandrew/laravel-ua-banks'),
            );
        });

        $this->app->bind(RegistryValidator::class, static fn (Container $app): RegistryValidator => new RegistryValidator(
            self::int($app->make(Config::class)->get('ua-banks.sync.min_banks'), 50),
        ));

        $this->app->bind(Synchronizer::class, static fn (Container $app): Synchronizer => new Synchronizer(
            $app->make(NbuClient::class),
            $app->make(RegistryValidator::class),
            new RecordMapper,
            $app->make(Dispatcher::class),
        ));
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving('validator', static function (ValidationFactory $validator): void {
            $validator->extend('ua_iban', static fn (string $attribute, mixed $value, array $parameters, Validator $v): bool => self::runRule(UaIban::make(), 'ua_iban', $attribute, $value, $v));
            $validator->extend('ua_mfo', static fn (string $attribute, mixed $value, array $parameters, Validator $v): bool => self::runRule(UaMfo::make(), 'ua_mfo', $attribute, $value, $v));
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app->make(Config::class);

            if (! $config->get('ua-banks.schedule.enabled', false)) {
                return;
            }

            $schedule->command(SyncCommand::class)
                ->cron(self::string($config->get('ua-banks.schedule.cron'), '17 4 * * *'))
                ->timezone(self::string($config->get('ua-banks.schedule.timezone'), 'Europe/Kyiv'))
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    private static function runRule(UaIban|UaMfo $rule, string $name, string $attribute, mixed $value, Validator $validator): bool
    {
        $invokable = InvokableValidationRule::make($rule)->setValidator($validator);

        if ($invokable->passes($attribute, $value)) {
            return true;
        }

        $messages = (array) $invokable->message();
        $message = reset($messages);
        $customKey = $attribute.'.'.$name;

        if (is_string($message) && ! isset($validator->customMessages[$customKey]) && ! isset($validator->customMessages[$name])) {
            $validator->setCustomMessages([$customKey => $message]);
        }

        return false;
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
