<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'LMS Studio') }}</title>
    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/studio/main.tsx'])
    @inertiaHead
</head>
<body class="h-full bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    @inertia
</body>
</html>
