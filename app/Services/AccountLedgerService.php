<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\Treasury;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AccountLedgerService
{
    public function treasuryAccount(Treasury $treasury): Account
    {
        return $this->linkedAccount($treasury, 'treasury', 'الخزينة', 'Treasury');
    }

    public function initializeTreasuryAccount(Treasury $treasury): Account
    {
        return $this->linkedAccount($treasury, 'treasury', 'الخزينة', 'Treasury', true);
    }

    public function bankAccount(Bank $bank): Account
    {
        return $this->linkedAccount($bank, 'asset', 'البنك', 'Bank');
    }

    public function initializeBankAccount(Bank $bank): Account
    {
        return $this->linkedAccount($bank, 'asset', 'البنك', 'Bank', true);
    }

    public function cashAccount(?int $treasuryId = null, ?int $bankId = null): ?Account
    {
        if ($treasuryId) {
            $treasury = Treasury::query()->find($treasuryId);
            return $treasury ? $this->treasuryAccount($treasury) : null;
        }
        if ($bankId) {
            $bank = Bank::query()->find($bankId);
            return $bank ? $this->bankAccount($bank) : null;
        }
        return null;
    }

    public function updateTotals(Account $account, float $debit, float $credit): void
    {
        $account = Account::query()->lockForUpdate()->findOrFail($account->id);
        $normal = $account->normal_balance ?: (in_array($account->account_type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit');
        $balanceDelta = $normal === 'credit' ? $credit - $debit : $debit - $credit;

        $account->increment('debit', $debit);
        $account->increment('credit', $credit);
        $account->increment('balance', $balanceDelta);
    }

    public function defaultAccount(string $type, string $code, string $nameAr, string $nameEn, ?int $parentId = null): Account
    {
        if (Schema::hasColumn('accounts', 'tenant_id')) {
            $tenantId = auth()->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
            if ($tenantId) $code .= '-T' . $tenantId;
        }
        return Account::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $nameEn,
                'name_ar' => $nameAr,
                'account_type' => $type,
                'parent_id' => $parentId,
                'normal_balance' => in_array($type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit',
                'is_header' => false,
                'is_active' => true,
                'debit' => 0,
                'credit' => 0,
                'balance' => 0,
            ]
        );
    }

    private function linkedAccount(Model $entity, string $accountType, string $labelAr, string $labelEn, bool $seedOpeningBalance = false): Account
    {
        if ($entity->account_id) {
            $account = Account::query()->find($entity->account_id);
            if ($account) return $account;
        }

        $kind = $entity instanceof Treasury ? 'TREASURY' : 'BANK';
        $name = $entity->name ?: $labelEn . ' ' . $entity->getKey();
        $code = $kind . '-' . $entity->getKey();
        $isNewAccount = !Account::query()->where('code', $code)->exists();
        $account = $this->defaultAccount(
            $accountType,
            $code,
            $entity->name ?: $labelAr . ' ' . $entity->getKey(),
            $name
        );
        $openingBalance = $seedOpeningBalance ? round((float) ($entity->balance ?? 0), 2) : 0.0;
        if (($isNewAccount || ($seedOpeningBalance && !$account->journalEntryLines()->exists())) && $openingBalance > 0) {
            $account->forceFill(['debit' => $openingBalance, 'credit' => 0, 'balance' => $openingBalance])->save();
        }
        $entity->forceFill(['account_id' => $account->id])->save();

        return $account;
    }
}
