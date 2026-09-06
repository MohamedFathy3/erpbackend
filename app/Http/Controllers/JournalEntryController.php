<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JournalEntryController extends Controller
{
    /**
     * ✅ إنشاء قيد يومي جديد مع ربط الخزينة
     */
    public function store(Request $request)
    {
        DB::transaction(function () use ($request) {

            // ✅ جلب المستخدم الحالي
            $user = auth()->user();
            
            // ✅ تحديد الخزينة
            $treasuryId = null;
            
            if ($user instanceof Employee) {
                $treasuryId = $user->treasury_id;
            } elseif ($user instanceof Admin) {
                $mainTreasury = Treasury::where('is_main', true)->first();
                $treasuryId = $mainTreasury?->id;
            }

            // ✅ استخدام treasury_id من الطلب أو من المستخدم
            $finalTreasuryId = $request->treasury_id ?? $treasuryId;

            $entry = JournalEntry::create([
                'entry_date' => $request->entry_date,
                'description_ar' => $request->description_ar,
                'description_en' => $request->description_en,
                'notes'          => $request->notes,
                'status'         => $request->status ?? 'draft',
                'treasury_id'    => $finalTreasuryId,
                'created_by'     => auth()->id(),
            ]);

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($request->lines as $line) {

                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit'      => $line['debit'] ?? 0,
                    'credit'     => $line['credit'] ?? 0,
                    'description'=> $line['description'] ?? null,
                ]);

                $totalDebit += $line['debit'] ?? 0;
                $totalCredit += $line['credit'] ?? 0;
            }

            // ✅ التأكد من توازن القيد
            if ($totalDebit != $totalCredit) {
                throw new \Exception('القيد غير متوازن');
            }

            // ============================================================
            // 🟢 تحديث الخزينة بناءً على حركة حساب الخزينة في القيد
            // ============================================================
            if ($finalTreasuryId) {
                $this->updateTreasuryFromEntry($entry);
            }
        });

        return response()->json([
            'result' => 'Success',
            'message' => 'تم حفظ القيد بنجاح',
            'status' => 200
        ]);
    }

    /**
     * ✅ تحديث الخزينة بناءً على حركة حساب الخزينة في القيد
     * 
     * المنطق:
     * - حساب الخزينة مدين → الخزينة تزيد (فلوس داخلة)
     * - حساب الخزينة دائن → الخزينة تنقص (فلوس خارجة)
     */
   /**
 * ✅ تحديث الخزينة بناءً على حركة حساب الخزينة في القيد
 */
private function updateTreasuryFromEntry($entry)
{
    // ✅ جلب الخزينة
    $treasury = Treasury::find($entry->treasury_id);
    if (!$treasury) {
        Log::warning("⚠️ Treasury not found", [
            'treasury_id' => $entry->treasury_id,
            'journal_entry_id' => $entry->id,
        ]);
        return;
    }

    // ✅ البحث عن حساب الخزينة
    // باستخدام account_type = 'treasury' أو code = 'treasury_' + id
    $treasuryAccount = Account::where('code', 'treasury_' . $entry->treasury_id)
        ->orWhere('account_type', 'treasury')
        ->first();

    if (!$treasuryAccount) {
        Log::warning("⚠️ Treasury account not found", [
            'treasury_id' => $entry->treasury_id,
            'journal_entry_id' => $entry->id,
            'searched_code' => 'treasury_' . $entry->treasury_id,
            'searched_type' => 'treasury'
        ]);
        return;
    }

    // ✅ البحث عن البند الخاص بالخزينة في بنود القيد
    $treasuryLine = null;
    foreach ($entry->lines as $line) {
        if ($line->account_id == $treasuryAccount->id) {
            $treasuryLine = $line;
            break;
        }
    }

    if (!$treasuryLine) {
        Log::info("ℹ️ No treasury account line found in this entry", [
            'journal_entry_id' => $entry->id,
            'treasury_account_id' => $treasuryAccount->id,
            'treasury_account_code' => $treasuryAccount->code,
        ]);
        return;
    }

    // ✅ تحديد نوع الحركة وتحديث الخزينة
    $oldBalance = $treasury->balance;
    $amount = 0;
    $type = null;
    $direction = null;

    if ($treasuryLine->debit > 0) {
        // الخزينة مدين → فلوس داخلة → تزيد
        $amount = $treasuryLine->debit;
        $type = 'in';
        $treasury->increment('balance', $amount);
        $direction = 'زيادة';
    } elseif ($treasuryLine->credit > 0) {
        // الخزينة دائن → فلوس خارجة → تنقص
        $amount = $treasuryLine->credit;
        $type = 'out';
        $treasury->decrement('balance', $amount);
        $direction = 'نقصان';
    } else {
        Log::info("ℹ️ Treasury line has no debit or credit", [
            'journal_entry_id' => $entry->id,
            'treasury_line_id' => $treasuryLine->id,
        ]);
        return;
    }

    // ✅ إنشاء حركة الخزينة
    TreasuryTransaction::create([
        'treasury_id' => $entry->treasury_id,
        'reference_type' => JournalEntry::class,
        'reference_id' => $entry->id,
        'type' => $type,
        'amount' => $amount,
        'description' => "قيد يومي رقم {$entry->id} - " . ($entry->description_ar ?? $entry->description_en ?? 'بدون وصف'),
        'created_by' => auth()->id(),
        'created_at' => now(),
    ]);

    // ✅ تسجيل العملية
    Log::info("💰 Treasury updated from journal entry", [
        'journal_entry_id' => $entry->id,
        'treasury_id' => $entry->treasury_id,
        'treasury_name' => $treasury->name,
        'treasury_account_id' => $treasuryAccount->id,
        'treasury_account_code' => $treasuryAccount->code,
        'type' => $type,
        'amount' => $amount,
        'direction' => $direction,
        'old_balance' => $oldBalance,
        'new_balance' => $treasury->balance,
        'debit' => $treasuryLine->debit,
        'credit' => $treasuryLine->credit,
        'created_by' => auth()->id(),
    ]);
}

    /**
     * ✅ جلب قائمة القيود اليومية مع الخزينة
     */
    public function journalEntryIndex(Request $request)
    {
        try {

            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'journal_entries.id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = JournalEntry::query()
                // حساب total_debit باستخدام subquery
                ->select('journal_entries.*')
                ->selectSub(function ($q) {
                    $q->from('journal_entry_lines')
                        ->selectRaw('SUM(debit)')
                        ->whereColumn('journal_entry_lines.journal_entry_id', 'journal_entries.id');
                }, 'total_debit')
                // حساب total_credit باستخدام subquery
                ->selectSub(function ($q) {
                    $q->from('journal_entry_lines')
                        ->selectRaw('SUM(credit)')
                        ->whereColumn('journal_entry_lines.journal_entry_id', 'journal_entries.id');
                }, 'total_credit')
                // ✅ جلب الخزينة مع القيد
                ->with('treasury')
                ->with('creator');

            // =========================
            // FILTERS
            // =========================

            if (!empty($filters['entry_number'])) {
                $query->where('journal_entries.id', 'like', '%' . $filters['entry_number'] . '%');
            }

            if (!empty($filters['status'])) {
                $query->where('journal_entries.status', $filters['status']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('journal_entries.entry_date', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('journal_entries.entry_date', '<=', $filters['date_to']);
            }

            if (!empty($filters['description'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('journal_entries.description_ar', 'like', '%' . $filters['description'] . '%')
                        ->orWhere('journal_entries.description_en', 'like', '%' . $filters['description'] . '%');
                });
            }

            // ✅ فلتر الخزينة
            if (!empty($filters['treasury_id'])) {
                $query->where('journal_entries.treasury_id', $filters['treasury_id']);
            }

            // ✅ فلتر البحث في اسم الخزينة
            if (!empty($filters['treasury_name'])) {
                $query->whereHas('treasury', function ($q) use ($filters) {
                    $q->where('name', 'like', '%' . $filters['treasury_name'] . '%')
                        ->orWhere('name_ar', 'like', '%' . $filters['treasury_name'] . '%');
                });
            }

            // =========================
            // SORT
            // =========================

            $query->orderBy($orderBy, $orderByDirection);

            // =========================
            // PAGINATION MODE
            // =========================

            if ($paginate) {
                $entries = $query->paginate($perPage);

                return response()->json([
                    'data' => $entries->items(),

                    'links' => [
                        'first' => $entries->url(1),
                        'last' => $entries->url($entries->lastPage()),
                        'prev' => $entries->previousPageUrl(),
                        'next' => $entries->nextPageUrl(),
                    ],

                    'meta' => [
                        'current_page' => $entries->currentPage(),
                        'from' => $entries->firstItem(),
                        'last_page' => $entries->lastPage(),
                        'path' => $entries->path(),
                        'per_page' => $entries->perPage(),
                        'to' => $entries->lastItem(),
                        'total' => $entries->total(),
                    ],

                    'result' => 'Success',
                    'message' => 'Journal entries fetched successfully',
                    'status' => 200,
                ]);
            }

            // =========================
            // NON PAGINATED MODE
            // =========================

            $entries = $query->get();

            return response()->json([
                'data' => $entries,
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Journal entries fetched successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    /**
     * ✅ إحصائيات القيود اليومية والخزائن
     */
    public function reports()
    {
        try {
            $totalEntries = JournalEntry::count();
            $posted = JournalEntry::where('status', 'posted')->count();
            $drafts = JournalEntry::where('status', 'draft')->count();
            $cancelled = JournalEntry::where('status', 'cancelled')->count();
            $paid = JournalEntry::where('status', 'paid')->count();

            // ✅ إحصائيات الخزينة
            $treasuryStats = Treasury::select('id', 'name', 'name_ar', 'balance', 'is_main')
                ->withCount(['journalEntries' => function ($q) {
                    $q->where('status', 'posted');
                }])
                ->get()
                ->map(function ($treasury) {
                    return [
                        'id' => $treasury->id,
                        'name' => $treasury->name,
                        'name_ar' => $treasury->name_ar,
                        'balance' => $treasury->balance,
                        'is_main' => (bool) $treasury->is_main,
                        'journal_entries_count' => $treasury->journal_entries_count,
                    ];
                });

            // ✅ إجمالي المبالغ
            $totalDebit = JournalEntry::sum('total_debit');
            $totalCredit = JournalEntry::sum('total_credit');

            return response()->json([
                'total_entries' => $totalEntries,
                'posted'        => $posted,
                'drafts'        => $drafts,
                'cancelled'     => $cancelled,
                'paid'          => $paid,
                'total_debit'   => $totalDebit,
                'total_credit'  => $totalCredit,
                'treasury_stats' => $treasuryStats,
                'result' => 'Success',
                'message' => 'Reports fetched successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    /**
     * ✅ عرض قيد يومي مع تفاصيله والخزينة
     */
    public function show($id)
    {
        try {

            $entry = JournalEntry::with([
                'lines.account',
                'treasury',
                'creator'
            ])->findOrFail($id);

            return response()->json([
                'result' => 'success',
                'data'   => $entry,
                'status' => 200,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'result'  => 'error',
                'message' => 'Journal Entry not found',
                'status'  => 404,
            ], 404);
        }
    }

    /**
     * ✅ ترحيل القيد اليومي
     */
    public function post($id)
    {
        try {
            $journalEntry = JournalEntry::findOrFail($id);

            if ($journalEntry->status === 'posted') {
                return response()->json([
                    'result' => 'error',
                    'message' => 'Journal Entry already posted',
                    'status' => 400,
                ], 400);
            }

            if ($journalEntry->status === 'cancelled') {
                return response()->json([
                    'result' => 'error',
                    'message' => 'Cannot post a cancelled entry',
                    'status' => 400,
                ], 400);
            }

            $journalEntry->update([
                'status' => 'posted'
            ]);

            Log::info("📝 Journal entry posted", [
                'journal_entry_id' => $journalEntry->id,
                'posted_by' => auth()->id(),
            ]);

            return response()->json([
                'result' => 'success',
                'message' => 'Journal Entry posted successfully',
                'data' => $journalEntry->load(['treasury', 'lines.account']),
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'error',
                'message' => $e->getMessage(),
                'status' => 404,
            ], 404);
        }
    }

    /**
     * ✅ إلغاء القيد اليومي
     */
    public function cancel($id)
    {
        try {
            $journalEntry = JournalEntry::findOrFail($id);

            if ($journalEntry->status === 'posted') {
                return response()->json([
                    'result' => 'error',
                    'message' => 'Cannot cancel a posted entry',
                    'status' => 400,
                ], 400);
            }

            if ($journalEntry->status === 'cancelled') {
                return response()->json([
                    'result' => 'error',
                    'message' => 'Entry already cancelled',
                    'status' => 400,
                ], 400);
            }

            $journalEntry->update([
                'status' => 'cancelled'
            ]);

            Log::info("❌ Journal entry cancelled", [
                'journal_entry_id' => $journalEntry->id,
                'cancelled_by' => auth()->id(),
            ]);

            return response()->json([
                'result' => 'success',
                'message' => 'Journal Entry cancelled successfully',
                'data' => $journalEntry,
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'error',
                'message' => $e->getMessage(),
                'status' => 404,
            ], 404);
        }
    }

    /**
     * ✅ حذف القيد اليومي (مسودة فقط)
     */
    public function destroy($id)
    {
        try {
            $journalEntry = JournalEntry::findOrFail($id);

            if ($journalEntry->status !== 'draft') {
                return response()->json([
                    'result' => 'error',
                    'message' => 'Only draft entries can be deleted',
                    'status' => 400,
                ], 400);
            }

            // حذف البنود المرتبطة
            $journalEntry->lines()->delete();
            
            // حذف القيد
            $journalEntry->delete();

            Log::info("🗑️ Journal entry deleted", [
                'journal_entry_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'result' => 'success',
                'message' => 'Journal Entry deleted successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'error',
                'message' => $e->getMessage(),
                'status' => 404,
            ], 404);
        }
    }

    /**
     * ✅ حذف مجموعة من القيود (مسودات فقط)
     */
    public function deleteMultiple(Request $request)
    {
        try {
            $ids = $request->input('items', []);

            if (empty($ids)) {
                return response()->json([
                    'result' => 'error',
                    'message' => 'No items selected',
                    'status' => 400,
                ], 400);
            }

            // ✅ التأكد من أن كل القيود مسودات
            $draftEntries = JournalEntry::whereIn('id', $ids)
                ->where('status', 'draft')
                ->get();

            if ($draftEntries->count() !== count($ids)) {
                return response()->json([
                    'result' => 'error',
                    'message' => 'Only draft entries can be deleted',
                    'status' => 400,
                ], 400);
            }

            foreach ($draftEntries as $entry) {
                $entry->lines()->delete();
                $entry->delete();
            }

            Log::info("🗑️ Multiple journal entries deleted", [
                'count' => count($ids),
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'result' => 'success',
                'message' => 'Entries deleted successfully',
                'deleted_count' => $draftEntries->count(),
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }
}