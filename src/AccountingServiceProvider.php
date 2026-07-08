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

        $this->app->singleton(ServiceAccountTemplateRegistry::class, function ($app) {
            return new ServiceAccountTemplateRegistry;
        });

        // Register services
        $this->app->singleton(AccountingService::class, function ($app) {
            return new AccountingService($app->make(ServiceCatalog::class));
        });

        $this->app->alias(AccountingService::class, 'laravel-accounting');

        $this->app->singleton(JournalService::class, function ($app) {
            return new JournalService($app->make(ServiceCatalog::class));
        });

        $this->app->singleton(CoaService::class, function ($app) {
            return new CoaService($app->make(AccountCategoryTreeService::class));
        });

        $this->app->singleton(AccountCategoryTreeService::class, function ($app) {
            return new AccountCategoryTreeService;
        });

        $this->app->singleton(MappingService::class, function ($app) {
            return new MappingService;
        });

        $this->app->singleton(ClosingService::class, function ($app) {
            return new ClosingService;
        });

        $this->app->singleton(FiscalPeriodService::class, function ($app) {
            return new FiscalPeriodService;
        });

        $this->app->singleton(ReportService::class, function ($app) {
            return new ReportService($app->make(AccountCategoryTreeService::class));
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
