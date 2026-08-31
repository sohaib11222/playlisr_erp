<?php

namespace App\Services;

/**
 * Sends the cashier offer letter from sarah@nivessa.com specifically, using
 * its own dedicated SMTP credentials (OFFER_MAIL_* env vars) rather than the
 * app's global mail config — Sarah wants offer letters to come from her
 * personal address, and everything else (hello@nivessa.com) to keep using
 * the app's default mailer. Gmail/Workspace SMTP requires the authenticated
 * login to match the From address, so this needs its own transport, not
 * just a ->from() override on the shared one.
 */
class OfferLetterMailer
{
    public static function send(string $toEmail, string $firstName, string $pdfBinary, string $pdfFilename)
    {
        $host = env('OFFER_MAIL_HOST');
        $port = env('OFFER_MAIL_PORT');
        $encryption = env('OFFER_MAIL_ENCRYPTION');
        $username = env('OFFER_MAIL_USERNAME');
        $password = env('OFFER_MAIL_PASSWORD');

        if (empty($host) || empty($username) || empty($password)) {
            throw new \RuntimeException('Offer-letter mailbox is not configured (OFFER_MAIL_* env vars missing).');
        }

        $transport = new \Swift_SmtpTransport($host, $port, $encryption ?: null);
        $transport->setUsername($username);
        $transport->setPassword($password);
        $mailer = new \Swift_Mailer($transport);

        $html = view('emails.cashier_offer_letter', ['firstName' => $firstName])->render();

        $message = (new \Swift_Message('Nivessa Offer Letter & Next Steps'))
            ->setFrom([$username => 'Sarah Hedvat'])
            ->setTo([$toEmail])
            ->setCc([$username => 'Sarah Hedvat'])
            ->setBody($html, 'text/html')
            ->attach(new \Swift_Attachment($pdfBinary, $pdfFilename, 'application/pdf'));

        $sent = $mailer->send($message, $failures);
        if (!$sent) {
            throw new \RuntimeException('SMTP send failed for: ' . implode(', ', $failures));
        }
    }
}
