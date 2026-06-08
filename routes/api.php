<?php

use ESolution\LaravelAccounting\Http\Controllers\Api\AccountCategoryController;
use ESolution\LaravelAccounting\Http\Controllers\Api\AccountController;
use ESolution\LaravelAccounting\Http\Controllers\Api\JournalController;
use ESolution\LaravelAccounting\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

$defineRoutes = function ($tenantId = null) {
    $prefix = $tenantId ? '{tenantId}/' : '';

    // Account Categories
    Route::apiResource($prefix.'categories', AccountCategoryController::class)->names([
        'index' => $tenantId ? 'tenant.categories.index' : 'categories.index',
        'store' => $tenantId ? 'tenant.categories.store' : 'categories.store',
        'show' => $tenantId ? 'tenant.categories.show' : 'categories.show',
        'update' => $tenantId ? 'tenant.categories.update' : 'categories.update',
        'destroy' => $tenantId ? 'tenant.categories.destroy' : 'categories.destroy',
    ]);
    Route::patch($prefix.'categories/{id}/toggle-status', [AccountCategoryController::class, 'toggleStatus']);

    // Accounts
    Route::apiResource($prefix.'accounts', AccountController::class)->names([
        'index' => $tenantId ? 'tenant.accounts.index' : 'accounts.index',
        'store' => $tenantId ? 'tenant.accounts.store' : 'accounts.store',
        'show' => $tenantId ? 'tenant.accounts.show' : 'accounts.show',
        'update' => $tenantId ? 'tenant.accounts.update' : 'accounts.update',
        'destroy' => $tenantId ? 'tenant.accounts.destroy' : 'accounts.destroy',
    ]);
    Route::patch($prefix.'accounts/{id}/toggle-status', [AccountController::class, 'toggleStatus']);

    // Services (Business Transactions)
    Route::apiResource($prefix.'services', ServiceController::class)->names([
        'index' => $tenantId ? 'tenant.services.index' : 'services.index',
        'store' => $tenantId ? 'tenant.services.store' : 'services.store',
        'show' => $tenantId ? 'tenant.services.show' : 'services.show',
        'update' => $tenantId ? 'tenant.services.update' : 'services.update',
        'destroy' => $tenantId ? 'tenant.services.destroy' : 'services.destroy',
    ]);
    Route::patch($prefix.'services/{id}/toggle-status', [ServiceController::class, 'toggleStatus']);

    // Journals
    Route::get($prefix.'journals', [JournalController::class, 'index'])->name($tenantId ? 'tenant.journals.index' : 'journals.index');
    Route::get($prefix.'journals/{id}', [JournalController::class, 'show'])->name($tenantId ? 'tenant.journals.show' : 'journals.show');

    // Reports
    Route::prefix($prefix.'reports')->group(function () use ($tenantId) {
        Route::get('general-ledger', [\ESolution\LaravelAccounting\Http\Controllers\Api\ReportController::class, 'generalLedger'])->name($tenantId ? 'tenant.reports.general-ledger' : 'reports.general-ledger');
        Route::get('trial-balance', [\ESolution\LaravelAccounting\Http\Controllers\Api\ReportController::class, 'trialBalance'])->name($tenantId ? 'tenant.reports.trial-balance' : 'reports.trial-balance');
        Route::get('profit-loss', [\ESolution\LaravelAccounting\Http\Controllers\Api\ReportController::class, 'profitLoss'])->name($tenantId ? 'tenant.reports.profit-loss' : 'reports.profit-loss');
        Route::get('balance-sheet', [\ESolution\LaravelAccounting\Http\Controllers\Api\ReportController::class, 'balanceSheet'])->name($tenantId ? 'tenant.reports.balance-sheet' : 'reports.balance-sheet');
        Route::get('cash-flow', [\ESolution\LaravelAccounting\Http\Controllers\Api\ReportController::class, 'cashFlow'])->name($tenantId ? 'tenant.reports.cash-flow' : 'reports.cash-flow');
    });
};

Route::prefix(config('accounting.route.prefix', 'api/accounting'))
    ->middleware(config('accounting.route.middleware', ['api']))
    ->group(function () use ($defineRoutes) {
        $defineRoutes(false);
        $defineRoutes(true);
    });
