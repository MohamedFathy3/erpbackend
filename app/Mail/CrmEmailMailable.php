<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
class CrmEmailMailable extends Mailable { use Queueable, SerializesModels; public function __construct(public string $subjectLine, public string $bodyHtml) {} public function build(): static { return $this->subject($this->subjectLine)->html($this->bodyHtml); } }
