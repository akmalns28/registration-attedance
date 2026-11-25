<?php

namespace App\Services;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\View\ViewException;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Throwable;

class EventNotificationFailure
{
    private const TEMPLATE = 'Invitation template could not be rendered.';

    private const CONFIGURATION = 'Mail transport configuration is invalid.';

    private const DELIVERY = 'Mail transport failed. Check the relay configuration.';

    private const UNKNOWN = 'Email could not be prepared or sent. See the worker log for the exception type.';

    private const QUEUE_DISPATCH = 'Queue dispatch failed. Check the queue connection.';

    private const QUEUE_FAILURE = 'Queue job permanently failed.';

    public const RETRY_LIMIT = 'Notification retry limit exhausted.';

    private const TIMEOUT = 'The email worker timed out.';

    public function describe(Throwable $exception): string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $message = match (true) {
                $cause instanceof ViewException => self::TEMPLATE,
                $cause instanceof TransportExceptionInterface => $this->smtpError($cause->getMessage(),
                    $cause instanceof UnexpectedResponseException ? (int) $cause->getCode() : 0) ?? self::DELIVERY,
                $cause instanceof UnsupportedSchemeException, $cause instanceof IncompleteDsnException => self::CONFIGURATION,
                $cause instanceof TimeoutExceededException => self::TIMEOUT,
                $cause instanceof MaxAttemptsExceededException => self::RETRY_LIMIT,
                default => null,
            };

            if ($message !== null) {
                return $message;
            }
        }

        return self::UNKNOWN;
    }

    public function forHistory(?string $storedError): ?string
    {
        if ($storedError === null || $storedError === '') {
            return null;
        }

        if (in_array($storedError, [
            self::TEMPLATE, self::CONFIGURATION, self::DELIVERY, self::UNKNOWN, self::TIMEOUT,
            self::QUEUE_DISPATCH, self::QUEUE_FAILURE, self::RETRY_LIMIT,
            EventNotificationEligibility::MISSING_EMAIL, 'Registration not found.', 'Participant not found.',
            'Event not found.', 'Invalid notification type.', 'Notification event does not match registration.',
            'Registration is no longer cancelled.', 'Registration or event is no longer active.',
            'Peserta belum memiliki hari undangan aktif.', 'Alamat email pengirim belum valid.', 'Host atau port SMTP belum valid.',
            'Email belum dikirim ke peserta: mailer aktif hanya menyimpan pesan. Konfigurasikan SMTP untuk mengirim email.',
            'Mailer cadangan hanya menyimpan pesan. Gunakan transport pengiriman email untuk seluruh mailer.',
        ], true)) {
            return $storedError;
        }

        return match (true) {
            str_starts_with($storedError, 'Queue dispatch failed:') => self::QUEUE_DISPATCH,
            str_contains($storedError, '(View:') || str_contains($storedError, 'Undefined variable') => self::TEMPLATE,
            default => $this->smtpError($storedError) ?? self::UNKNOWN,
        };
    }

    private function smtpError(string $message, int $reply = 0): ?string
    {
        if ($reply < 400 || $reply > 599) {
            preg_match('/(?:got code ["\']?|SMTP reply |^)([45]\d{2})\b/i', $message, $replyMatch);
            $reply = (int) ($replyMatch[1] ?? 0);
        }

        $summary = match (true) {
            $reply === 535 || preg_match('/authenticat(?:e|ion)|SMTP auth/i', $message) === 1 => 'SMTP authentication failed.',
            preg_match('/STARTTLS|TLS required|SMTP TLS|certificate verif/i', $message) === 1 => 'SMTP TLS negotiation failed.',
            preg_match('/connect|timed out|timeout/i', $message) === 1 => 'SMTP connection failed. Check relay reachability.',
            $reply >= 500 => 'SMTP server rejected the message.',
            $reply >= 400 => 'SMTP server reported a temporary failure.',
            default => null,
        };

        if ($summary === null || $reply === 0) {
            return $summary;
        }

        preg_match('/\b'.$reply.'[ -]([45]\.\d{1,3}\.\d{1,3})\b/', $message, $enhancedStatus);
        $status = isset($enhancedStatus[1]) ? ' '.$enhancedStatus[1] : '';

        return $summary.' SMTP reply '.$reply.$status.'.';
    }
}
