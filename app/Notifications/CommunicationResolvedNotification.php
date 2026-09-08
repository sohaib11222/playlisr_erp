<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Fired when a Communications Hub inquiry is marked resolved. */
class CommunicationResolvedNotification extends Notification
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
