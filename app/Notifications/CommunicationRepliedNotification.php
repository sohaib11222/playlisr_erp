<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired when a customer inquiry in the Communications Hub actually gets a
 * reply — a live Quo text/call callback, or a manual reply sent from the
 * Hub's thread view. NOT fired for historical backfill imports (that would
 * just spam the bell with "you called back" for replies sent days ago).
 */
class CommunicationRepliedNotification extends Notification
{
    use Queueable;

    protected $communication;

    public function __construct($communication)
    {
        $this->communication = $communication;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'communication_id' => $this->communication->id,
            'channel' => $this->communication->channel,
            'contact_info' => $this->communication->contact_info,
        ];
    }
}
