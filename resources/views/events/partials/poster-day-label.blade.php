@if ($poster->isEventWide())
    Semua Hari
@elseif ($poster->days->isEmpty())
    Belum ada hari yang ditetapkan
@else
    {{ $poster->days->map(fn ($day) => 'Day '.$day->day_number.($day->title ? ' · '.$day->title : ''))->implode(', ') }}
@endif
