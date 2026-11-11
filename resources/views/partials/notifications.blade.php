@php
    $notifications = [
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ];
@endphp

<script type="application/json" id="flash-notifications">
    {{ Illuminate\Support\Js::encode($notifications) }}
</script>

<script>
    window.addEventListener('app:plugins-ready', function() {
        const element = document.getElementById('flash-notifications');

        if (!element || !window.notify) {
            return;
        }

        const notifications = JSON.parse(element.textContent);

        Object.entries(notifications).forEach(([type, message]) => {
            if (message && typeof window.notify[type] === 'function') {
                window.notify[type](message);
            }
        });
    });
</script>
