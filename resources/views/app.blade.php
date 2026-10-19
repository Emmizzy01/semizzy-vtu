<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @php($brandingSettings = app(\App\Services\IntegrationSettings::class))
        @php($siteName = (string) $brandingSettings->get('site.name', config('app.name', 'SEMIZZY ONE')))
        @php($socialImage = (string) $brandingSettings->get('branding.social_image', ''))
        <meta name="theme-color" content="#101828">
        @if($brandingSettings->has('branding.favicon'))
            <link rel="icon" href="{{ asset(ltrim((string) $brandingSettings->get('branding.favicon'), '/')) }}">
        @endif
        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:title" content="{{ $siteName }}">
        <meta property="og:description" content="We Design. We Develop. We Deliver. — SEMIZZY ONE, Nigerian Digital Super Platform.">
        <meta name="twitter:card" content="summary_large_image">
        @if($socialImage !== '')
            <meta property="og:image" content="{{ url($socialImage) }}">
            <meta name="twitter:image" content="{{ url($socialImage) }}">
        @endif
        <title inertia>{{ config('app.name', 'SEMIZZY VTU') }}</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="min-h-full antialiased">
        @inertia
    </body>
</html>
