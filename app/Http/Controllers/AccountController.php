<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Http\Resources\AccountResource;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * عرض شجرة الحسابات كاملة بكل المستويات
     */
    public function index()
    {
        // جلب كل الحسابات الرئيسية مع كل الأبناء والأحفاد
        $accounts = Account::with('childrenRecursive')
            ->whereNull('parent_id')
            ->orderBy('code')
            ->get();

        return AccountResource::collection($accounts);
    }

    /**
     * عرض حساب معين بكل أبنائه وأحفاده
     */
    public function show($code)
    {
        $account = Account::with('childrenRecursive')
            ->where('code', $code)
            ->firstOrFail();

        return new AccountResource($account);
    }

    /**
     * عرض شجرة كاملة كقائمة مسطحة مع تحديد المستوى
     */
    public function flatTree()
    {
        $accounts = Account::with('parent')->orderBy('code')->get();

        $formatted = $accounts->map(function($account) {
            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'name_ar' => $account->name_ar,
                'debit' => $account->debit,
                'credit' => $account->credit,
                'balance' => $account->balance,
                'level' => $account->level,
                'parent_code' => $account->parent?->code,
                'parent_name' => $account->parent?->name,
                'full_path' => $this->getFullPath($account),
            ];
        });

        return response()->json([
            'data' => $formatted,
            'total' => $accounts->count()
        ]);
    }

    /**
     * الحصول على المسار الكامل للحساب
     */
    private function getFullPath($account)
    {
        $path = [];
        $current = $account;

        while ($current) {
            array_unshift($path, $current->name);
            $current = $current->parent;
        }

        return implode(' > ', $path);
    }

    /**
     * عرض إحصائيات الشجرة
     */
    public function treeStats()
    {
        $mainAccounts = Account::whereNull('parent_id')->count();
        $totalAccounts = Account::count();

        // حساب أقصى عمق للشجرة
        $maxLevel = 0;
        $accounts = Account::with('parent')->get();
        foreach ($accounts as $account) {
            $level = $account->level;
            if ($level > $maxLevel) {
                $maxLevel = $level;
            }
        }

        return response()->json([
            'total_accounts' => $totalAccounts,
            'main_accounts' => $mainAccounts,
            'sub_accounts' => $totalAccounts - $mainAccounts,
            'max_depth' => $maxLevel,
            'tree_levels' => $maxLevel + 1, // +1 لأن المستوى يبدأ من 0
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['code'=>'required|string|max:40','name'=>'required|string|max:150','name_ar'=>'nullable|string|max:150','account_type'=>'required|in:asset,liability,equity,revenue,expense,treasury','parent_id'=>'nullable|exists:accounts,id','normal_balance'=>'nullable|in:debit,credit','is_header'=>'boolean','description'=>'nullable|string']);
        $data['normal_balance'] = $data['normal_balance'] ?? (in_array($data['account_type'], ['liability','equity','revenue']) ? 'credit' : 'debit');
        return response()->json(['data'=>Account::create($data)], 201);
    }

    public function update(Request $request, Account $account)
    {
        $data = $request->validate(['name'=>'sometimes|string|max:150','name_ar'=>'nullable|string|max:150','account_type'=>'sometimes|in:asset,liability,equity,revenue,expense,treasury','parent_id'=>'nullable|exists:accounts,id','is_header'=>'boolean','is_active'=>'boolean','description'=>'nullable|string']);
        if (($data['parent_id'] ?? null) === $account->id) return response()->json(['message'=>'الحساب لا يمكن أن يكون أبًا لنفسه'],422);
        $account->update($data); return response()->json(['data'=>$account->fresh()]);
    }

    public function deactivate(Account $account)
    {
        if ($account->children()->where('is_active', true)->exists()) return response()->json(['message'=>'لا يمكن تعطيل حساب له حسابات فرعية نشطة'],422);
        $account->update(['is_active'=>false]); return response()->json(['data'=>$account]);
    }
}
