<?php
namespace App\Services;
use App\Models\Account;
use App\Models\Bank;
use App\Models\JournalEntry;
use App\Models\Transfer;
use App\Models\Treasury;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class TransferPostingService
{
    public function post(Transfer $transfer): JournalEntry
    {
        if ($transfer->journal_entry_id) return $transfer->journalEntry()->firstOrFail();
        return DB::transaction(function () use ($transfer) {
            [$debit, $credit] = $this->accounts($transfer);
            if (!$debit || !$credit || $debit->id === $credit->id) throw ValidationException::withMessages(['accounting'=>'تعذر تحديد حسابي المصدر والوجهة للتحويل.']);
            $journal = JournalEntry::create(['entry_date'=>$transfer->created_at?->toDateString() ?? now()->toDateString(),'description_ar'=>'ترحيل تحويل خزينة/بنك #'.$transfer->id,'description_en'=>'Treasury/bank transfer #'.$transfer->id,'status'=>'posted','source_type'=>Transfer::class,'source_id'=>$transfer->id,'posted_by'=>auth()->id(),'posted_at'=>now(),'branch_id'=>$transfer->fromTreasury?->branch_id ?? $transfer->toTreasury?->branch_id ?? $transfer->fromBank?->branch_id ?? $transfer->toBank?->branch_id]);
            $amount=(float)$transfer->amount;
            $journal->lines()->createMany([['account_id'=>$debit->id,'debit'=>$amount,'credit'=>0,'description'=>'الطرف المدين للتحويل'],['account_id'=>$credit->id,'debit'=>0,'credit'=>$amount,'description'=>'الطرف الدائن للتحويل']]);
            Account::whereKey($debit->id)->increment('debit',$amount); Account::whereKey($credit->id)->increment('credit',$amount);
            $transfer->update(['journal_entry_id'=>$journal->id]);
            return $journal->load('lines');
        });
    }
    private function accounts(Transfer $transfer): array
    {
        $type=$transfer->type;
        $source=$this->entityAccount($transfer->from_treasury_id?Treasury::find($transfer->from_treasury_id):null,$transfer->from_bank_id?Bank::find($transfer->from_bank_id):null);
        $destination=$this->entityAccount($transfer->to_treasury_id?Treasury::find($transfer->to_treasury_id):null,$transfer->to_bank_id?Bank::find($transfer->to_bank_id):null);
        if (in_array($type,['treasury_to_treasury','treasury_to_bank','bank_to_treasury','bank_to_bank'],true)) return [$destination,$source];
        if (in_array($type,['treasury_deposit','bank_deposit'],true)) return [$destination ?: $source, $this->defaultAccount('revenue','9000','إيرادات أخرى','Other income')];
        if (in_array($type,['treasury_withdraw','bank_withdraw'],true)) return [$this->defaultAccount('expense','6000','مصروفات أخرى','Other expenses'), $source];
        return [null,null];
    }
    private function entityAccount($treasury, $bank): ?Account
    {
        if (!$treasury && !$bank) return null;
        $entity=$treasury ?: $bank; $entityType=$treasury?'treasury':'bank';
        if ($entity->account_id) return Account::find($entity->account_id);
        $code=strtoupper($entityType).'-'.$entity->id;
        $account=$this->defaultAccount($treasury?'treasury':'asset',$code,$entity->name ?: ucfirst($entityType).' '.$entity->id,$entity->name ?: ucfirst($entityType).' '.$entity->id);
        $entity->forceFill(['account_id'=>$account->id])->save(); return $account;
    }
    private function defaultAccount(string $type,string $code,string $name,string $nameEn): Account
    { return Account::firstOrCreate(['code'=>$code],['name'=>$nameEn,'name_ar'=>$name,'account_type'=>$type,'normal_balance'=>in_array($type,['liability','equity','revenue'])?'credit':'debit','is_header'=>false,'is_active'=>true,'debit'=>0,'credit'=>0,'balance'=>0]); }
}
