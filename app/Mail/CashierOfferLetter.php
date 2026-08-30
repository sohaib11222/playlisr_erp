<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Nivessa Offer Letter & Next Steps" — sent from the onboarding checklist's
 * one-click offer sender for the standard Sales Cashier hire. Attaches the
 * compiled offer letter PDF (resources/views/pdf/cashier_offer_letter).
 *
 * Template: resources/views/emails/cashier_offer_letter.blade.php.
 */
class CashierOfferLetter extends Mailable
{
    use Queueable, SerializesModels;

    public $firstName;
    public $pdfBinary;
    public $pdfFilename;

    public function __construct(string $firstName, string $pdfBinary, string $pdfFilename)
    {
        $this->firstName = $firstName;
        $this->pdfBinary = $pdfBinary;
        $this->pdfFilename = $pdfFilename;
    }

    public function build()
    {
        return $this->subject('Nivessa Offer Letter & Next Steps')
            ->replyTo('sarah@nivessa.com', 'Sarah Hedvat')
            ->view('emails.cashier_offer_letter')
            ->attachData($this->pdfBinary, $this->pdfFilename, [
                'mime' => 'application/pdf',
            ]);
    }
}
