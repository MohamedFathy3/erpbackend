<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CrmEmailMailable extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public string $subjectLine, public string $htmlBody, public ?string $bcc = null) {}
    public function envelope(): Envelope { return new Envelope(subject: $this->subjectLine, bcc: $this->bcc ? array_filter(array_map('trim', explode(',', $this->bcc))) : null); }
    public function content(): Content { return new Content(htmlString: $this->htmlBody); }
}
