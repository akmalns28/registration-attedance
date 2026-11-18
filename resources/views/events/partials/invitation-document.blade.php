<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Undangan {{ $event->title }} - {{ config('app.name') }}</title>
    <style>
        @page { margin: 29pt 33pt; }
        body { margin: 0; color: #182433; font: 10pt/1.4 'DejaVu Sans', sans-serif; }
        header { border-top: 4pt solid #206bc4; padding-top: 16pt; margin-bottom: 17pt; }
        .company { margin: 0 0 7pt; font-size: 9pt; font-weight: bold; letter-spacing: 0.7pt; color: #315782; }
        h1 { margin: 0; font-size: 23pt; line-height: 1.15; }
        .event-name { margin: 5pt 0 0; font-size: 15pt; line-height: 1.25; font-weight: bold; }
        .event-name, p, td { overflow-wrap: break-word; }
        p { margin: 0 0 6pt; }
        .details { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 10pt 0 16pt; }
        .details th, .details td { padding: 6pt 8pt; border-bottom: 1pt solid #e1eaf4; vertical-align: top; text-align: left; }
        .details th { width: 50pt; color: #315782; }
        .check-in { padding: 10pt 12pt; border: 1pt solid #d6e3f2; background: #f7faff; text-align: center; page-break-inside: avoid; }
        .label { margin: 0 0 2pt; color: #315782; font-size: 9pt; }
        .number { margin: 0 0 6pt; font-weight: bold; }
        .qr { display: block; width: 43mm; height: 43mm; margin: 0 auto 6pt; background: #fff; }
        .instructions { margin: 0; font-size: 9pt; }
    </style>
</head>
<body>
    <header>
        <p class="company">PT ARI UTSAHA NUSANTARA</p>
        <h1>Undangan</h1>
        <p class="event-name">{{ $event->title }}</p>
    </header>

    <p>Yth. <strong>{{ $participantName }}</strong></p>
    <p>Dengan hormat, kami mengundang Bapak/Ibu untuk menghadiri acara berikut.</p>

    <table class="details">
        <tr>
            <th scope="row">Tanggal</th>
            <td>{{ $event->start_date->locale('id')->translatedFormat('j F Y') }} &ndash; {{ $event->end_date->locale('id')->translatedFormat('j F Y') }}</td>
        </tr>
        <tr>
            <th scope="row">Lokasi</th>
            <td>{{ $event->location }}</td>
        </tr>
    </table>

    <section class="check-in">
        <p class="label">Nomor Registrasi</p>
        <p class="number">{{ $registration->registration_number }}</p>
        <img class="qr" src="{{ $qrImage }}" alt="QR Code registrasi">
        <p class="instructions">Tunjukkan QR Code ini kepada panitia saat check-in.</p>
    </section>
</body>
</html>
