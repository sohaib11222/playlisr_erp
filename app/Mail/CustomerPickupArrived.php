<?php

namespace App\Mail;

use App\Contact;
use App\CustomerPickup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Your order's in — ready for pickup" email. Sent when staff click
 * "Arrived" on an AMS special-order Customer Pickup and choose to notify
 * the customer by email.
 *
 * Template: resources/views/emails/customer_pickup_arrived.blade.php.
 * The label + store name are precomputed by the controller (the pickup's
 * product/variation naming lives there) and passed in.
 */
class CustomerPickupArrived extends Mailable
{
    use Queueable, SerializesModels;

    public $pickup;
    public $contact;
    public $label;
    public $storeName;

    public function __construct(CustomerPickup $pickup, Contact $contact, string $label, string $storeName)
    {
        $this->pickup = $pickup;
        $this->contact = $contact;
        $this->label = $label;
        $this->storeName = $storeName;
    }

    public function build()
    {
        return $this->subject('Your order is ready for pickup' . ($this->label !== '' ? ' — ' . $this->label : ''))
            ->view('emails.customer_pickup_arrived');
    }
}
