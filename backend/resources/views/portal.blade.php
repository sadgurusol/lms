<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    @php($metaTitle = $metaTitle ?? 'Samchita — Learn')
    @php($metaDescription = $metaDescription ?? 'Free, interactive lessons with animated explanations and narration.')
    @php($metaUrl = $metaUrl ?? url()->current())
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="canonical" href="{{ $metaUrl }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Samchita">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $metaUrl }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">

    {{-- Structured data: a site-wide organisation, plus per-page schema (e.g. Course). --}}
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Samchita', 'url' => url('/')], JSON_UNESCAPED_SLASHES) !!}</script>
    @isset($structured)
        <script type="application/ld+json">{!! json_encode($structured, JSON_UNESCAPED_SLASHES) !!}</script>
    @endisset
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/css/portal.css', 'resources/js/portal/main.tsx'])
</head>
<body class="h-full">
    <div id="portal"></div>
</body>
</html>
