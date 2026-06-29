<!DOCTYPE html>
<html class="h-full bg-white" lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />

    <title>{{ ___('crafter', 'PIM') }}</title>

    @routes

    @vite(['resources/js/crafter/index.ts', 'resources/css/crafter.css'])

    {{-- [argo-mail-pkg] PWA manifest --}}
    <link rel="manifest" href="/manifest.webmanifest">

    @inertiaHead
</head>

<body class="h-full bg-white">
    @inertia

    {{-- [argo-mail-pkg] Rejestracja Service Workera (PWA / Web Push) --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function (e) {
                    console.warn('SW register failed', e);
                });
            });
        }
    </script>
</body>

</html>
