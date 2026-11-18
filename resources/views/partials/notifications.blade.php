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

<template id="notification-toast-template">
    <div>
        <div class="app-toast" role="status" aria-live="polite" aria-atomic="true">
            <span class="app-toast-symbol" aria-hidden="true">
                <x-ui.icon name="circle-check" class="toast-success" />
                <x-ui.icon name="alert-circle" class="toast-error" />
                <x-ui.icon name="alert-triangle" class="toast-warning" />
                <x-ui.icon name="info-circle" class="toast-info" />
            </span>
            <div class="app-toast-copy"><strong data-notify-text="title"></strong><div data-notify-text="message"></div></div>
            <button type="button" class="app-toast-dismiss" aria-label="Tutup notifikasi" title="Tutup notifikasi"><x-ui.icon name="x" /></button>
        </div>
    </div>
</template>
