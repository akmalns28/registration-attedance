@if ($attendance['days'] !== [])
    <div class="detail-row" style="margin: 4px 0;">
        <strong>Jadwal Kehadiran</strong>
        @foreach ($attendance['days'] as $day)
            <div style="margin-top: 3px;">
                {{ $day['date'] }}@if ($day['time']) | {{ $day['time'] }}@endif
                @if ($attendance['dress_code'] === null && $day['dress_code'] !== null)
                    <div style="margin: 2px 0 4px;">
                        <strong>Dress Code</strong>
                        <x-ui.dress-code :value="$day['dress_code']" />
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @if ($attendance['dress_code'] !== null)
        <div class="detail-row" style="margin: 4px 0;">
            <strong>Dress Code</strong>
            <x-ui.dress-code :value="$attendance['dress_code']" />
        </div>
    @endif
@endif
