<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Services\GeneralLedgerService;
use ESolution\LaravelAccounting\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends BaseController
{
    public function __construct(
        protected ReportService $reportService,
        protected GeneralLedgerService $generalLedgerService
    )
    {
    }

    public function generalLedger(Request $request)
    {
        $this->initializeTenantIfNeeded($request->route('tenantId'));

        $validated = $request->validate([
            'account_id' => 'required|uuid',
            'year' => 'nullable|required_without:start_date|integer|min:1',
            'month' => 'nullable|required_with:year|integer|between:1,12',
            'start_date' => 'nullable|required_without:year|date',
            'end_date' => 'nullable|required_with:start_date|date|after_or_equal:start_date',
        ]);

        $data = isset($validated['year'])
            ? $this->generalLedgerService->getLedger(
                $validated['account_id'],
                (int) $validated['year'],
                (int) $validated['month']
            )
            : $this->reportService->generalLedger(
                $validated['account_id'],
                $validated['start_date'],
                $validated['end_date']
            );

        return $this->successResponse('General Ledger retrieved successfully', $data);
    }

    public function generalLedgerDetails(Request $request)
    {
        $this->initializeTenantIfNeeded($request->route('tenantId'));

        $validated = $request->validate([
            'account_id' => 'required|uuid',
            'year' => 'required|integer|min:1',
            'month' => 'required|integer|between:1,12',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return $this->generalLedgerService->getLedgerDetails(
            $validated['account_id'],
            (int) $validated['year'],
            (int) $validated['month'],
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 15)
        );
    }

    public function trialBalance(Request $request)
    {
        $this->initializeTenantIfNeeded($request->route('tenantId'));

        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->trialBalance($request->year, $request->month);

        return $this->successResponse('Trial Balance retrieved successfully', $data);
    }

    public function profitLoss(Request $request)
    {
        $this->initializeTenantIfNeeded($request->route('tenantId'));

        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->profitLoss($request->year, $request->month);

        return $this->successResponse('Profit & Loss retrieved successfully', $data);
    }

    public function balanceSheet(Request $request)
    {
        $this->initializeTenantIfNeeded($request->route('tenantId'));

        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->balanceSheet($request->year, $request->month);

        return $this->successResponse('Balance Sheet retrieved successfully', $data);
    }

    public function cashFlow(Request $request)
    {
        $this->initializeTenantIfNeeded($request->route('tenantId'));

        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->cashFlow($request->year, $request->month);

        return $this->successResponse('Cash Flow retrieved successfully', $data);
    }
}
