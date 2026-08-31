<?php

namespace App\Mail;

use App\InStoreOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Your order's ready" email for an In Store Order. Sent when staff click
 * Notify on the In Store Orders page.
 *
 * Template: resources/views/emails/in_store_order_ready.blade.php.
 */
class InStoreOrderReady extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $storeName;

    public function __construct(InStoreOrder $order, string $storeName)
    {
        $this->order = $order;
        $this->storeName = $storeName;
    }

    public function build()
    {
        return $this->subject('Your order is ready — ' . $this->order->item_name)
            ->view('emails.in_store_order_ready');
    }
}
