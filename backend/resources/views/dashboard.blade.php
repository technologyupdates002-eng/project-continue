@extends('layouts.app')

@section('title', 'KHWWC — Dashboard')

@section('content')
    <section class="page-head">
        <h1 class="page-head__title">System Dashboard</h1>
        <p class="page-head__subtitle">
            Kirinyaga Health Care Workers Welfare &mdash; Laravel replacement application.
        </p>
    </section>

    <section class="card" aria-live="polite">
        <div class="card__head">
            <h2 class="card__title">API Health</h2>
            <button id="refresh-btn" class="btn" type="button">Check now</button>
        </div>
        <div id="health-status" class="status status--pending">
            Checking&hellip;
        </div>
        <pre id="health-detail" class="code-block" hidden></pre>
    </section>

    <section class="grid">
        <div class="card stat">
            <div class="stat__label">Status</div>
            <div id="stat-status" class="stat__value">&mdash;</div>
        </div>
        <div class="card stat">
            <div class="stat__label">Database</div>
            <div id="stat-db" class="stat__value">&mdash;</div>
        </div>
        <div class="card stat">
            <div class="stat__label">Cache</div>
            <div id="stat-cache" class="stat__value">&mdash;</div>
        </div>
        <div class="card stat">
            <div class="stat__label">Queue</div>
            <div id="stat-queue" class="stat__value">&mdash;</div>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Foundation Modules</h2>
        </div>
        <ul class="module-list">
            <li><span class="badge badge--ok">Ready</span> JWT Service (HS256, legacy-compatible)</li>
            <li><span class="badge badge--ok">Ready</span> Policy / Row-Level Security engine</li>
            <li><span class="badge badge--ok">Ready</span> REST Query Builder</li>
            <li><span class="badge badge--ok">Ready</span> API Serializer (Casts)</li>
            <li><span class="badge badge--ok">Ready</span> 76 Eloquent models generated</li>
            <li><span class="badge badge--warn">Planned</span> Auth &amp; REST controllers (Phase 2)</li>
            <li><span class="badge badge--warn">Planned</span> Hooks &amp; Wallet side-effects (Phase 3)</li>
            <li><span class="badge badge--warn">Planned</span> RPC functions (Phase 4)</li>
        </ul>
    </section>
@endsection
