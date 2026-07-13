<?php

namespace ESolution\LaravelAccounting\Http\Controllers\Api;

use ESolution\LaravelAccounting\Http\Controllers\BaseController;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\JournalEntry;
use ESolution\LaravelAccounting\Repositories\JournalRepository;
use ESolution\LaravelAccounting\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class JournalController extends BaseController
{
    protected $cacheKey = 'acc_journals';

    public function store(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'trx_date' => 'required|date',
            'reference_no' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'details' => 'required|array|min:2',
            'details.*.account_id' => ['required', Rule::exists(Account::validationTable(), 'id')],
            'details.*.type' => 'required|in:D,K',
            'details.*.amount' => 'required|numeric|gt:0',
            'details.*.description' => 'nullable|string',
        ]);

        $journal = app(JournalService::class)->journalManual($validated);

        return $this->successResponse('Manual journal created successfully', [
            'id' => $journal->id,
            'journal_no' => $journal->journal_no,
            'trx_date' => $journal->trx_date?->toDateString(),
            'reference_no' => $journal->reference_no,
            'status' => $journal->status instanceof \BackedEnum ? $journal->status->value : $journal->status,
        ], 201);
    }

    public function index(Request $request, $tenantId = null)
    {
        $this->initializeTenantIfNeeded($tenantId);

        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 15);
        $search = $request->query('search');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status');

        $cacheParams = md5(json_encode([$page, $perPage, $search, $startDate, $endDate, $status]));
        $cacheKey = "index_{$cacheParams}";

        $journals = Cache::tags($this->getCacheTags($tenantId))->remember($cacheKey, now()->addHours(24), function () use ($search, $startDate, $endDate, $status, $perPage) {
            $data = JournalEntry::when($search, function ($query, $search) {
                $query->where('journal_no', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
                ->when($startDate, function ($query, $startDate) {
                    $query->whereDate('trx_date', '>=', $startDate);
                })
                ->when($endDate, function ($query, $endDate) {
                    $query->whereDate('trx_date', '<=', $endDate);
                })
                ->when($status, function ($query, $status) {
                    $query->where('status', $status);
                })
                ->orderBy('trx_date', 'desc')
                ->orderBy('journal_no', 'desc')
                ->paginate($perPage);

            $data->setCollection(
                $data->getCollection()->map(fn (JournalEntry $journal) => app(JournalRepository::class)->loadService($journal))
            );

            return $data;
        });

        return $journals;
    }

    public function show(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $journal = Cache::tags($this->getCacheTags($tenantId))->rememberForever("show_{$id}", function () use ($id) {
            $entry = JournalEntry::findOrFail($id);

            return app(JournalRepository::class)->attachViewRelations($entry);
        });

        return $this->successResponse('Journal retrieved successfully', $journal);
    }

    public function reverse(Request $request, $tenantId = null, $id = null)
    {
        if ($id === null) {
            $id = $tenantId;
            $tenantId = null;
        }
        $this->initializeTenantIfNeeded($tenantId);

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $reversal = app(JournalService::class)->reverse($id, $validated['reason']);

        return $this->successResponse('Journal reversed successfully', [
            'original_journal_id' => $id,
            'reversal_journal_id' => $reversal->id,
        ], 201);
    }
}
