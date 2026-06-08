<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends BaseController
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function generalLedger(Request $request)
    {
        $request->validate([
            'account_id' => 'required|uuid',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $data = $this->reportService->generalLedger(
            $request->account_id,
            $request->start_date,
            $request->end_date
        );

        return $this->successResponse('General Ledger retrieved successfully', $data);
    }

    public function trialBalance(Request $request)
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->trialBalance($request->year, $request->month);

        return $this->successResponse('Trial Balance retrieved successfully', $data);
    }

    public function profitLoss(Request $request)
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->profitLoss($request->year, $request->month);

        return $this->successResponse('Profit & Loss retrieved successfully', $data);
    }

    public function balanceSheet(Request $request)
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->balanceSheet($request->year, $request->month);

        return $this->successResponse('Balance Sheet retrieved successfully', $data);
    }

    public function cashFlow(Request $request)
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $data = $this->reportService->cashFlow($request->year, $request->month);

        return $this->successResponse('Cash Flow retrieved successfully', $data);
    }
}
