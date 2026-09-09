<?php
namespace App\Jobs;

use App\Mail\CrmEmailMailable;
use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendCrmEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public function __construct(public int $logId, public string $to, public string $subjectLine, public string $htmlBody, public ?string $bcc = null) {}
    public function handle(): void
    {
        $log = EmailLog::withoutGlobalScopes()->findOrFail($this->logId);
        Mail::to(new Address($this->to))->send(new CrmEmailMailable($this->subjectLine, $this->htmlBody, $this->bcc));
        $log->update(['status'=>'sent', 'sent_at'=>now(), 'error_message'=>null]);
    }
    public function failed(Throwable $exception): void
    {
        EmailLog::withoutGlobalScopes()->whereKey($this->logId)->update(['status'=>'failed','error_message'=>mb_substr($exception->getMessage(), 0, 2000)]);
    }
}
