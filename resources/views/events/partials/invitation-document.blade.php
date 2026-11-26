<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Undangan {{ $event->title }} - {{ config('app.name') }}</title>
    <style>
        @page { margin: 24pt 31pt; }
        body { margin: 0; color: #182433; font: 10pt/1.2 'DejaVu Sans', sans-serif; }
        header { border-top: 4pt solid #206bc4; padding-top: 10pt; margin-bottom: 10pt; }
        .company { margin: 0 0 4pt; font-size: 9pt; font-weight: bold; letter-spacing: 0.7pt; color: #315782; }
        .logo { float: right; max-width: 64pt; max-height: 48pt; margin-left: 10pt; }
        h1 { margin: 0; font-size: 23pt; line-height: 1.15; }
        .event-name { margin: 5pt 0 0; font-size: 15pt; line-height: 1.25; font-weight: bold; }
        .event-name, p, td { overflow-wrap: break-word; }
        p { margin: 0 0 6pt; }
        .details { margin: 6pt 0 10pt; }
        .detail-row { padding: 3pt 0; border-bottom: 1pt solid #e1eaf4; overflow-wrap: break-word; }
        .details strong { color: #315782; }
        .check-in { width: 100%; table-layout: fixed; border: 1pt solid #d6e3f2; background: #f7faff; page-break-inside: avoid; }
        .check-in-details, .check-in-qr { padding: 6pt; vertical-align: middle; }
        .check-in-details { width: 64%; line-height: 1.05; }
        .check-in-qr { width: 36%; }
        .label { margin: 0 0 2pt; color: #315782; font-size: 9pt; }
        .number { margin: 0 0 6pt; font-weight: bold; }
        .registration-number { font-size: 9pt; }
        .qr { display: block; width: 35mm; height: 35mm; background: #fff; }
        .instructions { margin: 0; font-size: 9pt; }
        .invitation-next { page-break-before: always; }
        .poster { text-align: center; margin: 0 0 6pt; page-break-inside: avoid; }
        .description { margin-top: 8pt; }
        .description p { margin: 0 0 4pt; overflow-wrap: break-word; }
    </style>
</head>
<body>
    @foreach ($pages as ['registration' => $registration, 'participantName' => $participantName, 'attendance' => $attendance, 'qrImage' => $qrImage, 'poster' => $poster])
    <article @unless ($loop->first) class="invitation-next" @endunless>
    <header>
        @if ($logoImage)
            <img class="logo" src="{{ $logoImage }}" alt="Logo {{ $event->title }}">
        @endif
        <p class="company">PT ARI UTSAHA NUSANTARA</p>
        <h1>Undangan</h1>
        <p class="event-name">{{ $event->title }}</p>
    </header>

    @if ($poster)
        <div class="poster">
            <img src="{{ $poster['image'] }}" alt="Poster {{ $event->title }}" style="width: {{ $poster['width'] }}pt; height: {{ $poster['height'] }}pt">
        </div>
    @endif

    <div class="details">
        @include('events.partials.invitation-attendance')
        @if (filled($event->location) || filled($event->google_maps_url))
            <div class="detail-row"><strong>Lokasi</strong> @include('events.partials.location-link', ['location' => $event->location, 'mapsUrl' => $event->google_maps_url, 'showMapsHint' => true])</div>
        @endif
    </div>

    <table class="check-in" role="presentation">
        <tr>
            <td class="check-in-details">
                <p class="label">Peserta</p>
                <p class="number">{{ $participantName }}</p>
                <p class="label">Nomor Registrasi</p>
                <p class="number registration-number">{{ $registration->registration_number }}</p>
                <p class="instructions">Tunjukkan QR Code ini kepada panitia saat check-in.</p>
            </td>
            <td class="check-in-qr">
                <img class="qr" src="{{ $qrImage }}" alt="QR Code registrasi">
            </td>
        </tr>
    </table>

    @if ($description !== [])
        <div class="description">
            @foreach ($description as $paragraph)
                <p>@if ($paragraph === '') &nbsp; @else {{ $paragraph }} @endif</p>
            @endforeach
        </div>
    @endif
    </article>
    @endforeach
</body>
</html>
