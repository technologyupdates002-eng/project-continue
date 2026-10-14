@extends('admin.layout')

@section('title', 'KHWWC — Super Admin Sign In')

@section('content')
<div class="login-wrap">
    <form class="login-card" method="POST" action="{{ route('admin.login.post') }}">
        @csrf
        <div class="login-brand">
            <span class="sidebar__mark">KH</span>
            <span>
                <span class="sidebar__name">KHWWC</span><br>
                <span class="sidebar__sub">Super Admin</span>
            </span>
        </div>
        <h1>Sign in</h1>
        <p>Super Admin access only. Backup &amp; Restore management.</p>

        @if (session('error'))
            <div class="alert alert--danger">{{ session('error') }}</div>
        @endif

        <div class="form-row">
            <label class="form-label" for="email">Email</label>
            <input class="form-input" id="email" name="email" type="text" required autofocus
                   value="{{ old('email') }}" placeholder="254700000001@welfare.local" autocomplete="username">
        </div>
        <div class="form-row">
            <label class="form-label" for="password">Password</label>
            <input class="form-input" id="password" name="password" type="password" required
                   autocomplete="current-password" placeholder="••••••••">
        </div>
        <button class="btn btn--primary" style="width:100%; justify-content:center;" type="submit">Sign in</button>
        <p class="text-sm muted mt-2">Regular members, treasurers and group admins cannot access this area.</p>
    </form>
</div>
@endsection
