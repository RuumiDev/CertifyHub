<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Primary SEO Metadata Configuration Tags -->
    <title>CertifyHub</title>
    <meta name="description" content="Upload a design template and a CSV dataset to generate, live-design, and export a ZIP package of personalized certificates seamlessly for absolutely free — no account required.">

    <!-- Open Graph (Facebook / LinkedIn / Discord Link Previews) -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ config('app.url') }}">
    <meta property="og:title" content="CertifyHub">
    <meta property="og:description" content="Generate high-fidelity, resolution-independent certificates from raw CSV datasets instantly with an interactive canvas design workspace. Absolutely free.">
    <!-- Replace favicon.ico or link a stunning application layout render preview graphic file -->
    <meta property="og:image" content="{{ asset('favicon.ico') }}">

    <!-- Twitter / X Card Link Previews -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="{{ config('app.url') }}">
    <meta name="twitter:title" content="CertifyHub ">
    <meta name="twitter:description" content="Generate high-fidelity certificates from raw CSV datasets instantly with an interactive canvas design workspace. Absolutely free.">
    <meta name="twitter:image" content="{{ asset('favicon.ico') }}">

    <!-- Inertia Asset Head Handshakes -->
    @viteReactRefresh
    @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
    @inertiaHead
    </head>
    <body class="bg-slate-50 font-sans antialiased">
        @inertia
    </body>
</html>
