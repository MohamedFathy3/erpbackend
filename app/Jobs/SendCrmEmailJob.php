<?php
namespace App\Jobs;
use App\Mail\CrmEmailMailable;
use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
class SendCrmEmailJob implements ShouldQueue { use Dispatchable, InteractsWithQueue, Queueable, SerializesModels; public function __construct(public int $emailLogId) {} public function handle(): void { $log=EmailLog::withoutGlobalScopes()->findOrFail($this->emailLogId); try { Mail::to($log->to_email)->send(new CrmEmailMailable($log->subject,$log->body ?? '')); $log->update(['status'=>'sent','sent_at'=>now(),'error'=>null]); } catch (\Throwable $exception) { $log->update(['status'=>'failed','error'=>$exception->getMessage()]); throw $exception; } } }
