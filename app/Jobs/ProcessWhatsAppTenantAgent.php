<?php

namespace App\Jobs;

use App\Services\WhatsAppTenantAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWhatsAppTenantAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 45;

    public function __construct(public readonly int $conversationMessageId) {}

    public function handle(WhatsAppTenantAgent $agent): void
    {
        $agent->handleInbound($this->conversationMessageId);
    }
}
