<?php

namespace App\Services;

class EventNotificationTransport
{
    public function problem(): ?string
    {
        $mailer = (string) config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');

        if (! is_string($transport) || in_array($transport, ['log', 'array'], true)) {
            return 'Email belum dikirim ke peserta: mailer aktif hanya menyimpan pesan. Konfigurasikan SMTP untuk mengirim email.';
        }

        if (! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            return 'Alamat email pengirim belum valid.';
        }

        if ($transport === 'smtp' && (
            trim((string) config('mail.mailers.'.$mailer.'.host')) === ''
            || ! filter_var(config('mail.mailers.'.$mailer.'.port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])
        )) {
            return 'Host atau port SMTP belum valid.';
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach (config('mail.mailers.'.$mailer.'.mailers', []) as $child) {
                if (in_array(config('mail.mailers.'.$child.'.transport'), ['log', 'array', null], true)) {
                    return 'Mailer cadangan hanya menyimpan pesan. Gunakan transport pengiriman email untuk seluruh mailer.';
                }
            }
        }

        return null;
    }

    /**
     * @return array{mailer: string, transport: string, ready: bool, problem: ?string, connection: string, queue: string}
     */
    public function status(): array
    {
        $mailer = (string) config('mail.default');
        $problem = $this->problem();

        return [
            'mailer' => $mailer,
            'transport' => (string) config('mail.mailers.'.$mailer.'.transport', ''),
            'ready' => $problem === null,
            'problem' => $problem,
            'connection' => (string) (config('event_notifications.connection') ?: config('queue.default')),
            'queue' => (string) config('event_notifications.queue', 'emails'),
        ];
    }
}
