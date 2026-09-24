<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountingDocument;
use App\Models\FinancialPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingDocumentController extends Controller
{
    public function index(Request $request)
    {
        $documents = AccountingDocument::with(['journalEntry', 'treasury', 'sourceAccount', 'destinationAccount'])
            ->when($request->type, fn ($query) => $query->where('document_type', $request->type))
            ->when($request->from, fn ($query) => $query->whereDate('document_date', '>=', $request->from))
            ->when($request->to, fn ($query) => $query->whereDate('document_date', '<=', $request->to))
            ->latest('document_date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($documents);
    }

    public function store(Request $request, AccountLedgerService $ledger)
    {
        $data = $request->validate([
            'document_type' => 'required|in:receipt,payment,transfer,opening_balance',
            'document_date' => 'required|date',
            'document_number' => 'nullable|string|max:80',
            'amount' => 'required|numeric|gt:0',
            'source_account_id' => 'required|exists:accounts,id',
            'destination_account_id' => 'required|exists:accounts,id|different:source_account_id',
            'treasury_id' => 'nullable|exists:treasuries,id',
            'bank_id' => 'nullable|exists:banks,id',
            'party_type' => 'nullable|string',
            'party_id' => 'nullable|integer',
            'payment_method' => 'nullable|string',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'fiscal_period_id' => 'nullable|exists:financial_periods,id',
        ]);

        $periodId = $data['fiscal_period_id'] ?? FinancialPeriod::query()
            ->where('starts_on', '<=', $data['document_date'])
            ->where('ends_on', '>=', $data['document_date'])
            ->where('status', 'open')
            ->value('id');
        if (!$periodId) {
            throw ValidationException::withMessages(['document_date' => 'لا يوجد فترة مالية مفتوحة لهذا التاريخ.']);
        }

        $document = DB::transaction(function () use ($data, $periodId, $ledger) {
            $actor = auth()->user();
            $userId = $actor instanceof User ? $actor->getKey() : null;
            $documentNumber = $data['document_number'] ?? ('DOC-' . now()->format('YmdHis') . '-' . random_int(100, 999));

            $document = AccountingDocument::create(array_merge($data, [
                'fiscal_period_id' => $periodId,
                'document_number' => $documentNumber,
                'created_by' => $userId,
                'created_by_type' => $actor ? $actor::class : null,
                'created_by_id' => $actor?->getKey(),
                'status' => 'posted',
            ]));

            $journal = JournalEntry::create([
                'entry_date' => $data['document_date'],
                'entry_number' => $documentNumber,
                'description_ar' => $data['reason'],
                'description_en' => $data['reason'],
                'status' => 'posted',
                'fiscal_period_id' => $periodId,
                'branch_id' => $data['branch_id'] ?? null,
                'posted_by' => $userId,
                'posted_at' => now(),
                'source_type' => AccountingDocument::class,
                'source_id' => $document->id,
                'treasury_id' => $data['treasury_id'] ?? null,
            ]);

            $destination = Account::query()->findOrFail($data['destination_account_id']);
            $source = Account::query()->findOrFail($data['source_account_id']);
            $amount = round((float) $data['amount'], 2);
            $journal->lines()->createMany([
                ['account_id' => $destination->id, 'debit' => $amount, 'credit' => 0, 'description' => $data['reason']],
                ['account_id' => $source->id, 'debit' => 0, 'credit' => $amount, 'description' => $data['reason']],
            ]);
            $ledger->updateTotals($destination, $amount, 0);
            $ledger->updateTotals($source, 0, $amount);

            $document->update(['journal_entry_id' => $journal->id]);
            return $document->load('journalEntry');
        });

        activity()->performedOn($document)
            ->withProperties([
                'document_type' => $document->document_type,
                'amount' => $document->amount,
                'journal_entry_id' => $document->journal_entry_id,
            ])
            ->log('accounting_document_posted');

        return response()->json(['data' => $document], 201);
    }
}
