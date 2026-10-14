<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
@php
$svg = function (string $paths): string {
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$paths.'</svg>';
};
$__icon_dashboard = $svg('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>');
$__icon_users = $svg('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>');
$__icon_groups = $svg('<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>');
$__icon_transactions = $svg('<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>');
$__icon_reports = $svg('<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>');
$__icon_audit = $svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>');
$__icon_backup = $svg('<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M12 12v6"/><path d="M9 15l3 3 3-3"/>');
$__icon_settings = $svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>');
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'KHWWC — Super Admin')</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar__brand">
            <span class="sidebar__mark">KH</span>
            <span>
                <span class="sidebar__name">KHWWC</span><br>
                <span class="sidebar__sub">Super Admin</span>
            </span>
        </div>
        <nav class="sidebar__nav" aria-label="Super Admin">
            <div class="sidebar__section">Management</div>
            <a class="sidebar__link {{ Route::is('admin.dashboard') ? 'is-active' : '' }}" href="{{ route('admin.dashboard') }}">
                <span class="sidebar__icon">{!! $__icon_dashboard !!}</span> Dashboard
            </a>
            <a class="sidebar__link {{ Route::is('admin.users') ? 'is-active' : '' }}" href="{{ route('admin.users') }}">
                <span class="sidebar__icon">{!! $__icon_users !!}</span> Users
            </a>
            <a class="sidebar__link {{ Route::is('admin.groups') ? 'is-active' : '' }}" href="{{ route('admin.groups') }}">
                <span class="sidebar__icon">{!! $__icon_groups !!}</span> Groups
            </a>
            <a class="sidebar__link {{ Route::is('admin.transactions') ? 'is-active' : '' }}" href="{{ route('admin.transactions') }}">
                <span class="sidebar__icon">{!! $__icon_transactions !!}</span> Transactions
            </a>
            <a class="sidebar__link {{ Route::is('admin.reports') ? 'is-active' : '' }}" href="{{ route('admin.reports') }}">
                <span class="sidebar__icon">{!! $__icon_reports !!}</span> Reports
            </a>
            <a class="sidebar__link {{ Route::is('admin.audit-logs') ? 'is-active' : '' }}" href="{{ route('admin.audit-logs') }}">
                <span class="sidebar__icon">{!! $__icon_audit !!}</span> Audit Logs
            </a>
            <div class="sidebar__section">System</div>
            <a class="sidebar__link {{ Route::is('admin.backups') ? 'is-active' : '' }}" href="{{ route('admin.backups') }}">
                <span class="sidebar__icon">{!! $__icon_backup !!}</span> Backups &amp; Restore
            </a>
            <a class="sidebar__link {{ Route::is('admin.settings') ? 'is-active' : '' }}" href="{{ route('admin.settings') }}">
                <span class="sidebar__icon">{!! $__icon_settings !!}</span> Settings
            </a>
        </nav>
        <div class="sidebar__foot">
            KHWWC Welfare<br>Laravel {{ app()->version() }}
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="row">
                <button class="menu-toggle" type="button" onclick="KH.toggleMenu()">☰</button>
                <span class="topbar__title">@yield('page_title', 'Super Admin')</span>
            </div>
            <div class="topbar__user">
                <span>{{ session('admin_email') }}</span>
                <form method="POST" action="{{ route('admin.logout') }}" style="margin:0">
                    @csrf
                    <button class="btn btn--sm btn--ghost" type="submit">Sign out</button>
                </form>
            </div>
        </header>

        <main class="content">
            @if (session('error'))
                <div class="alert alert--danger">{{ session('error') }}</div>
            @endif
            @if (session('status'))
                <div class="alert alert--ok">{{ session('status') }}</div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

<script src="{{ asset('js/admin.js') }}"></script>
@stack('scripts')
</body>
</html>
