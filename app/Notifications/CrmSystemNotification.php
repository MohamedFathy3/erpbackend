<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class CrmSystemNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(public string $title, public string $message, public string $category='crm', public ?string $url=null) {}
    public function via(object $notifiable): array { return ['database','broadcast']; }
    public function toArray(object $notifiable): array { return ['title'=>$this->title,'message'=>$this->message,'category'=>$this->category,'url'=>$this->url,'created_at'=>now()->toISOString()]; }
    public function toBroadcast(object $notifiable): BroadcastMessage { return new BroadcastMessage($this->toArray($notifiable)); }
}
