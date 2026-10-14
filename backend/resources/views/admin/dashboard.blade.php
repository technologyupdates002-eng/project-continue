@extends('admin.layout')

@section('page_title', 'Dashboard')
@section('title', 'KHWWC — Super Admin Dashboard')

@section('content')
<section class="page-head">
    <h1 class="page-head__title">System Dashboard</h1>
    <p class="page-head__subtitle">Kirinyaga Health Care Workers Welfare — Laravel administration.</p>
</section>

<div class="grid grid--stats mb-2" id="stat-grid">
    <div class="card stat"><div class="stat__label">Total Backups</div><div class="stat__value" id="s-total">—</div></div>
    <div class="card stat"><div class="stat__label">Successful</div><div class="stat__value" id="s-completed">—</div></div>
    <div class="card stat"><div class="stat__label">Verified</div><div class="stat__value" id="s-verified">—</div></div>
    <div class="card stat"><div class="stat__label">Failed</div><div class="stat__value" id="s-failed">—</div></div>
    <div class="card stat"><div class="stat__label">Restored</div><div class="stat__value" id="s-restored">—</div></div>
    <div class="card stat"><div class="stat__label">Storage Used</div><div class="stat__value" id="s-storage">—</div></div>
</div>

<div class="row mt-2">
    <a class="btn btn--primary" href="{{ route('admin.backups') }}">Backups &amp; Restore</a>
    <a class="btn" href="{{ route('admin.audit-logs') }}">Audit Logs</a>
</div>

<div class="card mt-3">
    <div class="card__head"><h2 class="card__title">Latest backup</h2></div>
    <div id="latest" class="muted">Loading…</div>
</div>

<div class="card mt-3">
    <div class="card__head"><h2 class="card__title">Environment</h2></div>
    <div class="row text-sm muted">
        <span>Users: <b id="e-users">—</b></span>
        <span>Members: <b id="e-members">—</b></span>
        <span>Safety backups: <b id="e-safety">—</b></span>
    </div>
</div>
@endsection

@push('scripts')
<script>
KH.get('/admin/api/dashboard/stats').then(function (r) {
    if (!r.ok) { return; }
    var s = r.data.stats;
    document.getElementById('s-total').textContent = s.total;
    document.getElementById('s-completed').textContent = s.completed;
    document.getElementById('s-verified').textContent = s.verified;
    document.getElementById('s-failed').textContent = s.failed;
    document.getElementById('s-restored').textContent = s.restored;
    document.getElementById('s-storage').textContent = formatBytes(s.storage_used_bytes || 0);
    document.getElementById('e-users').textContent = s.users;
    document.getElementById('e-members').textContent = s.members;
    document.getElementById('e-safety').textContent = s.safety_backups;
    var latest = s.latest_verified_backup || s.latest_backup;
    document.getElementById('latest').innerHTML = latest
        ? '<b>' + KH.esc(latest.name) + '</b><br><span class="muted">' + KH.esc(latest.created_at) + '</span>'
        : 'No backups yet.';
});

function formatBytes(b) {
    var u = ['B','KB','MB','GB']; var i = b > 0 ? Math.floor(Math.log(b)/Math.log(1024)) : 0;
    return (b/Math.pow(1024,i)).toFixed(2)+' '+u[Math.min(i,u.length-1)];
}
</script>
@endpush
