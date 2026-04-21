<?php

namespace App\Mail;

use App\Contact;
use App\CustomerWant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Hey {name}, we got your wanted item in!" email. Sent when a cashier
 * clicks "Found it!" on the POS customer-wants sidebar and checks the
 * "notify by email" box.
 *
 * Template lives in resources/views/emails/customer_want_matched.blade.php.
 * Kept intentionally short + plain — it's meant to be a nudge to come in,
 * not a newsletter.
 */
class CustomerWantMatched extends Mailable
{
    use Queueable, SerializesModels;

    public $want;
    public $contact;

    public function __construct(CustomerWant $want, Contact $contact)
    {
        $this->want = $want;
        $this->contact = $contact;
    }

    public function build()
    {
        $artist = trim((string) $this->want->artist);
        $title = trim((string) $this->want->title);
        $format = trim((string) $this->want->format);

        $label = trim(implode(' — ', array_filter([$artist, $title])));
        if ($format) $label .= ' (' . $format . ')';

        return $this->subject('We have your ' . $label . '!')
            ->view('emails.customer_want_matched');
    }
}
