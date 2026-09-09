<?php
namespace App\Jobs;

use App\Mail\CrmEmailMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTrialEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public function __construct(public string $to, public string $subjectLine, public string $htmlBody) {}
    public function handle(): void { Mail::to($this->to)->send(new CrmEmailMailable($this->subjectLine, $this->htmlBody)); }
}
