<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="color-scheme" content="light">

    <title>{{ $heading }} - {{ config('app.name') }}</title>
</head>

<body
    style="
    margin: 0;
    padding: 0;
    background-color: #f4f6f9;
    font-family: Arial, Helvetica, sans-serif;
    color: #1f2937;
">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color: #f4f6f9;">
        <tr>
            <td align="center" style="padding: 32px 16px;">

                {{-- Main Container --}}

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                    style="
                    width: 100%;
                    max-width: 600px;
                    background-color: #ffffff;
                    border-radius: 10px;
                    overflow: hidden;
                ">

                    {{-- Header --}}

                    <tr>
                        <td
                            style="
                        background-color: #182433;
                        padding: 30px 32px;
                        text-align: center;
                    ">

                            @isset($event_logo['contents'], $event_logo['mime'])
                                <img src="{{ ($invite_preview ?? false) || ! isset($message)
                                    ? 'data:'.$event_logo['mime'].';base64,'.base64_encode($event_logo['contents'])
                                    : $message->embedData($event_logo['contents'], $event_logo['mime'] === 'image/png' ? 'event-logo.png' : 'event-logo.jpg', $event_logo['mime']) }}"
                                    alt="Logo {{ $event_title }}" width="72" height="72"
                                    style="display: inline-block; object-fit: contain; background-color: #ffffff; border-radius: 6px; margin-bottom: 12px;">
                            @endisset
                            <div
                                style="
                            color: #ffffff;
                            font-size: 22px;
                            font-weight: 700;
                            letter-spacing: .3px;
                        ">
                                {{ config('app.name') }}
                            </div>

                            <div
                                style="
                            color: #cbd5e1;
                            font-size: 13px;
                            margin-top: 8px;
                        ">
                                Notifikasi registrasi dan kehadiran event
                            </div>

                        </td>
                    </tr>

                    {{-- Content --}}

                    <tr>
                        <td style="padding: 36px 32px;">

                            {{-- Badge --}}

                            <div style="margin-bottom: 22px;">

                                <span
                                    style="
                                display: inline-block;
                                background-color: {{ $badge_color }};
                                color: #ffffff;
                                font-size: 11px;
                                font-weight: 700;
                                letter-spacing: 1px;
                                padding: 8px 14px;
                                border-radius: 6px;
                            ">
                                    {{ $badge }}
                                </span>

                            </div>

                            {{-- Heading --}}

                            <h1
                                style="
                            font-size: 25px;
                            font-weight: 700;
                            color: #182433;
                            margin: 0 0 22px;
                            line-height: 1.4;
                        ">
                                {{ $heading }}
                            </h1>

                            <div style="font-size: 15px; margin: 0 0 16px;">
                                <div style="font-size: 13px; color: #475569;">Peserta</div>
                                <strong>{{ $participant_name }}</strong>
                            </div>

                            @isset($body_text)
                                <p style="font-size: 14px; line-height: 1.8; margin: 0 0 24px; color: #475569;">
                                    {{ $body_text }}
                                </p>
                            @endisset

                            {{-- Event Information --}}

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                style="
                                background-color: #f8fafc;
                                border: 1px solid #e2e8f0;
                                border-radius: 8px;
                            ">
                                <tr>
                                    <td style="padding: 24px;">

                                        <div
                                            style="
                                        font-size: 12px;
                                        font-weight: 700;
                                        color: #64748b;
                                        margin-bottom: 10px;
                                        text-transform: uppercase;
                                    ">
                                            Informasi Event
                                        </div>

                                        <div
                                            style="
                                        font-size: 20px;
                                        font-weight: 700;
                                        color: #182433;
                                        line-height: 1.4;
                                        margin-bottom: 22px;
                                    ">
                                            {{ $event_title }}
                                        </div>

                                        @foreach ($event_posters as $poster)
                                            <img src="{{ ($invite_preview ?? false) || ! isset($message)
                                                ? 'data:'.$poster['mime'].';base64,'.base64_encode($poster['contents'])
                                                : $message->embedData($poster['contents'], $loop->first ? 'event-poster.jpg' : 'event-poster-'.$loop->iteration.'.jpg', $poster['mime']) }}"
                                                alt="Poster {{ $event_title }}" width="{{ min(500, $poster['width']) }}"
                                                style="display: block; max-width: 100%; height: auto; margin: 0 auto 18px;">
                                        @endforeach

                                        @if (filled($event_description))
                                            <p
                                                style="
                                            font-size: 13px;
                                            color: #64748b;
                                            line-height: 1.7;
                                            margin: 0 0 20px;
                                            overflow-wrap: anywhere;
                                            word-break: break-word;
                                        ">
                                                {!! nl2br(e($event_description)) !!}
                                            </p>
                                        @endif

                                        <div style="font-size: 13px; line-height: 1.55; color: #182433;">
                                            @include('events.partials.invitation-attendance')
                                            @if ($attendance['days'] === [] && $body_text === null)
                                                <p>Belum ada tanggal undangan aktif.</p>
                                            @endif
                                            @if (filled($event_location) || filled($event_maps_url))
                                                <div style="margin-top: 8px;">
                                                    <strong>Lokasi</strong>
                                                    @include('events.partials.location-link', ['location' => $event_location, 'mapsUrl' => $event_maps_url, 'showMapsHint' => true])
                                                </div>
                                            @endif
                                        </div>

                                    </td>
                                </tr>
                            </table>

                            {{-- Registration Number --}}

                            <div
                                style="
                            text-align: center;
                            padding: 24px 0;
                        ">

                                <div
                                    style="
                                font-size: 12px;
                                color: #64748b;
                                margin-bottom: 10px;
                            ">
                                    Nomor Registrasi
                                </div>

                                <div
                                    style="
                                font-size: 14px;
                                font-weight: 700;
                                color: #182433;
                                letter-spacing: .3px;
                                overflow-wrap: anywhere;
                                word-break: break-word;
                            ">
                                    {{ $registration_number }}
                                </div>

                            </div>

                            {{-- Status Information --}}

                            @isset($highlight)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                style="
                                border-left: 4px solid {{ $badge_color }};
                                background-color: #f8fafc;
                            ">
                                <tr>
                                    <td
                                        style="
                                    padding: 16px 18px;
                                    font-size: 13px;
                                    line-height: 1.8;
                                    color: #334155;
                                ">
                                        {{ $highlight }}
                                    </td>
                                </tr>
                            </table>
                            @endisset

                            {{-- QR Code Information --}}

                            @if ($has_qr_code)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            margin-top: 24px;
        ">
                                    <tr>
                                        <td style="padding: 20px;">

                                            <div
                                                style="
                    font-size: 15px;
                    font-weight: 700;
                    color: #1e40af;
                    margin-bottom: 10px;
                ">
                                                🎟️ QR Code Registrasi
                                            </div>

                                            @isset($qr_preview)
                                                <img src="data:image/png;base64,{{ $qr_preview }}" alt="QR Code registrasi"
                                                    width="160" height="160" style="display: block; margin: 0 auto 16px;">
                                            @endisset

                                            <p
                                                style="
                    font-size: 13px;
                    line-height: 1.8;
                    color: #334155;
                    margin: 0;
                ">
                                                QR Code registrasi Anda telah dilampirkan
                                                pada email ini dalam format PNG.

                                                <br><br>

                                                Simpan QR Code tersebut dan tunjukkan
                                                kepada panitia ketika melakukan check-in
                                                di lokasi event.

                                                Pemindaian QR Code dilakukan oleh panitia
                                                melalui aplikasi absensi.

                                                <br><br>

                                                <strong>
                                                    Anda tidak perlu melakukan konfirmasi
                                                    penerimaan undangan atau RSVP.
                                                </strong>
                                            </p>

                                        </td>
                                    </tr>
                                </table>
                            @endif

                            {{-- Closing --}}

                            <p
                                style="
                            font-size: 14px;
                            color: #475569;
                            line-height: 1.8;
                            margin: 28px 0 0;
                        ">
                                Terima kasih,
                                <br>

                                <strong>
                                    Event Management Team
                                </strong>
                            </p>

                        </td>
                    </tr>

                    {{-- Footer --}}

                    <tr>
                        <td
                            style="
                        background-color: #f8fafc;
                        border-top: 1px solid #e2e8f0;
                        padding: 24px 32px;
                        text-align: center;
                    ">

                            <div
                                style="
                            font-size: 12px;
                            font-weight: 700;
                            color: #334155;
                            margin-bottom: 8px;
                        ">
                                {{ config('app.name') }}
                            </div>

                            <div
                                style="
                            font-size: 11px;
                            line-height: 1.7;
                            color: #94a3b8;
                        ">
                                Email ini dibuat secara otomatis oleh sistem.
                                <br>
                                Mohon tidak membalas email ini.
                            </div>

                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>

</html>
