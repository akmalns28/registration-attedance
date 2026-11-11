@php
    $notifications = [
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ];
@endphp

<script type="application/json" id="flash-notifications">
    {!! Illuminate\Support\Js::encode($notifications) !!}
</script>
