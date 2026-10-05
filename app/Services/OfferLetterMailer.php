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
    public static function send(string $toEmail, string $firstName, string $jobTitle, string $pdfBinary, string $pdfFilename)
    {
        $html = view('emails.cashier_offer_letter', ['firstName' => $firstName, 'jobTitle' => $jobTitle])->render();

        self::sendHtml($toEmail, 'Nivessa Offer Letter & Next Steps', $html, [
            [$pdfBinary, $pdfFilename, 'application/pdf'],
        ]);
    }

    /**
     * Generic sender on the same sarah@ transport: any subject/HTML body plus
     * a list of attachments, each [binary, filename, mime]. Used by the offer
     * letter above and the WOTC tax-saving form email (EmployeeChecklistController).
     */
    public static function sendHtml(string $toEmail, string $subject, string $html, array $attachments = [])
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

        $message = (new \Swift_Message($subject))
            ->setFrom([$username => 'Sarah Hedvat'])
            ->setTo([$toEmail])
            ->setCc([$username => 'Sarah Hedvat'])
            ->setBody($html, 'text/html');

        foreach ($attachments as $att) {
            $message->attach(new \Swift_Attachment($att[0], $att[1], $att[2] ?? 'application/pdf'));
        }

        $sent = $mailer->send($message, $failures);
        if (!$sent) {
            throw new \RuntimeException('SMTP send failed for: ' . implode(', ', $failures));
        }
    }
}
