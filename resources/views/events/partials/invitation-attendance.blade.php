@if ($attendance['days'] !== [])
    <div class="detail-row" style="margin: 4px 0;">
        <strong>Jadwal Kehadiran</strong>
        @foreach ($attendance['days'] as $day)
            <div style="margin-top: 3px;">
                {{ $day['date'] }}@if ($day['time']) | {{ $day['time'] }}@endif
                @if ($attendance['dress_code'] === null && $day['dress_code'] !== null)
                    <div><strong>Dress Code</strong> {{ $day['dress_code'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
    @if ($attendance['dress_code'] !== null)
        <div class="detail-row" style="margin: 4px 0;">
            <strong>Dress Code</strong> {{ $attendance['dress_code'] }}
        </div>
    @endif
@endif
