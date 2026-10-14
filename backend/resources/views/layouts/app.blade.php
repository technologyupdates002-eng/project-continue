<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title>@yield('title', 'KHWWC — Welfare Management')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="app-header">
        <div class="app-header__inner">
            <div class="brand">
                <span class="brand__mark">KH</span>
                <span class="brand__name">KHWWC Welfare</span>
            </div>
            <nav class="app-nav" aria-label="Primary">
                <a href="{{ url('/') }}" class="app-nav__link is-active">Dashboard</a>
                <a href="{{ url('/api/health') }}" class="app-nav__link">API Health</a>
                <a href="{{ url('/api/system/info') }}" class="app-nav__link">System Info</a>
            </nav>
        </div>
    </header>

    <main class="app-main">
        <div class="container">
            @yield('content')
        </div>
    </main>

    <footer class="app-footer">
        <div class="container">
            <span>KHCWW Welfare Management System &middot; Laravel migration &middot; {{ date('Y') }}</span>
        </div>
    </footer>

    @stack('scripts')
    <script src="{{ asset('js/dashboard.js') }}" defer></script>
</body>
</html>
