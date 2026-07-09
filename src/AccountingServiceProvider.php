<?php

namespace ESolution\LaravelAccounting;

use ESolution\LaravelAccounting\Services\AccountCategoryTreeService;
use ESolution\LaravelAccounting\Services\AccountingService;
use ESolution\LaravelAccounting\Services\ClosingService;
use ESolution\LaravelAccounting\Services\CoaService;
use ESolution\LaravelAccounting\Services\FiscalPeriodService;
use ESolution\LaravelAccounting\Services\JournalService;
use ESolution\LaravelAccounting\Services\MappingService;
use ESolution\LaravelAccounting\Services\ReportService;
use ESolution\LaravelAccounting\Repositories\AccountCategoryRepository;
use ESolution\LaravelAccounting\Repositories\AccountRepository;
use ESolution\LaravelAccounting\Repositories\FiscalPeriodRepository;
use ESolution\LaravelAccounting\Repositories\JournalRepository;
use ESolution\LaravelAccounting\Repositories\ServiceAccountRepository;
use ESolution\LaravelAccounting\Repositories\ServiceRepository;
use ESolution\LaravelAccounting\Support\AccountingConnectionResolver;
use ESolution\LaravelAccounting\Support\ServiceAccountTemplateRegistry;
use ESolution\LaravelAccounting\Support\ServiceCatalog;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/accounting.php', 'accounting'
        );

        $this->app->singleton(ServiceCatalog::class, function ($app) {
            return new ServiceCatalog;
        });

        $this->app->singleton(AccountingConnectionResolver::class, function ($app) {
            return new AccountingConnectionResolver;
        });

        $this->app->singleton(AccountCategoryRepository::class, fn () => new AccountCategoryRepository);
        $this->app->singleton(AccountRepository::class, fn () => new AccountRepository);
        $this->app->singleton(ServiceRepository::class, fn () => new ServiceRepository);
        $this->app->singleton(ServiceAccountRepository::class, fn () => new ServiceAccountRepository);
        $this->app->singleton(JournalRepository::class, function ($app) {
            return new JournalRepository(
                $app->make(AccountRepository::class),
                $app->make(ServiceRepository::class)
            );
        });
        $this->app->singleton(FiscalPeriodRepository::class, fn () => new FiscalPeriodRepository);

        $this->app->singleton(ServiceAccountTemplateRegistry::class, function ($app) {
            return new ServiceAccountTemplateRegistry;
        });

        // Register services
        $this->app->singleton(AccountingService::class, function ($app) {
            return new AccountingService(
                $app->make(ServiceCatalog::class),
                $app->make(ServiceRepository::class)
            );
        });

        $this->app->alias(AccountingService::class, 'laravel-accounting');

        $this->app->singleton(JournalService::class, function ($app) {
            return new JournalService(
                $app->make(ServiceCatalog::class),
                $app->make(ServiceRepository::class),
                $app->make(ServiceAccountRepository::class),
                $app->make(AccountRepository::class),
                $app->make(JournalRepository::class),
                $app->make(FiscalPeriodRepository::class)
            );
        });

        $this->app->singleton(CoaService::class, function ($app) {
            return new CoaService($app->make(AccountCategoryTreeService::class));
        });

        $this->app->singleton(AccountCategoryTreeService::class, function ($app) {
            return new AccountCategoryTreeService(
                $app->make(AccountCategoryRepository::class),
                $app->make(AccountRepository::class)
            );
        });

        $this->app->singleton(MappingService::class, function ($app) {
            return new MappingService($app->make(ServiceAccountRepository::class));
        });

        $this->app->singleton(ClosingService::class, function ($app) {
            return new ClosingService($app->make(AccountCategoryRepository::class));
        });

        $this->app->singleton(FiscalPeriodService::class, function ($app) {
            return new FiscalPeriodService($app->make(FiscalPeriodRepository::class));
        });

        $this->app->singleton(ReportService::class, function ($app) {
            return new ReportService(
                $app->make(AccountCategoryTreeService::class),
                $app->make(AccountCategoryRepository::class),
                $app->make(AccountRepository::class),
                $app->make(JournalRepository::class)
            );
        });
    }

    public function boot()
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Load migrations
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/accounting');

            // Publish configuration
            $this->publishes([
                __DIR__.'/../config/accounting.php' => config_path('accounting.php'),
            ], 'accounting-config');

            // Publish migrations
            $this->publishes([
                __DIR__.'/../database/migrations/accounting/' => database_path('migrations/accounting'),
            ], 'accounting-migrations');
        }
    }
}
