@extends('admin.layout')

@section('page_title', 'Settings')
@section('title', 'KHWWC — Backup Settings')

@section('content')
<section class="page-head">
    <h1 class="page-head__title">Backup Settings</h1>
    <p class="page-head__subtitle">Configure what is backed up, retention and automatic backups.</p>
</section>

<div id="banner"></div>

<div class="card" style="max-width:680px">
    <div class="card__head"><h2 class="card__title">Backup configuration</h2></div>

    <div class="form-row">
        <label class="form-label">Database backups enabled</label>
        <select class="form-select" id="database_enabled">
            <option value="1">Enabled</option><option value="0">Disabled</option>
        </select>
    </div>
    <div class="form-row">
        <label class="form-label">Storage backups enabled</label>
        <select class="form-select" id="storage_enabled">
            <option value="1">Enabled</option><option value="0">Disabled</option>
        </select>
    </div>
    <div class="form-row">
        <label class="form-label">Retention — keep last N backups (blank = manual only, nothing auto-deleted)</label>
        <input class="form-input" id="retention_keep_last" type="number" min="1" placeholder="e.g. 30">
    </div>
    <div class="form-row">
        <label class="form-label">Automatic pruning (run scheduled prune)</label>
        <select class="form-select" id="auto_prune">
            <option value="1">Enabled</option><option value="0">Disabled</option>
        </select>
    </div>
    <div class="form-row">
        <label class="form-label">Automatic daily backup (02:00, requires scheduler)</label>
        <select class="form-select" id="automatic_backup_enabled">
            <option value="1">Enabled</option><option value="0">Disabled</option>
        </select>
    </div>
    <div class="form-row">
        <label class="form-label">Minimum free disk space (MB) before backup</label>
        <input class="form-input" id="min_free_space_mb" type="number" min="10" value="200">
    </div>

    <div class="row mt-2">
        <button class="btn btn--primary" onclick="saveSettings()">Save settings</button>
        <button class="btn" onclick="KH.post('/admin/api/backups/prune').then(function(r){ showBanner(r.ok ? 'Prune: '+r.data.message : 'Prune failed', r.ok?'ok':'danger'); render(); })">Run prune now</button>
    </div>
</div>

<div class="card mt-3" style="max-width:680px">
    <div class="card__head"><h2 class="card__title">Operational notes</h2></div>
    <ul class="text-sm muted">
        <li>Backups are stored on the private <b>{{ 'backups' }}</b> disk — never web-served.</li>
        <li>Restore requires typing the confirmation word <b id="cword">RESTORE</b>.</li>
        <li>Automatic backups and pruning require a running scheduler: <code>php artisan schedule:run</code> via cron.</li>
        <li>Long backups run on the queue (<code>{{ 'sync' }}</code> locally).</li>
    </ul>
</div>
@endsection

@push('scripts')
<script>
function render() {
    KH.get('/admin/api/backups/settings').then(function (r) {
        if (!r.ok) { return; }
        var s = r.data.settings;
        document.getElementById('database_enabled').value = s.database_enabled ? '1' : '0';
        document.getElementById('storage_enabled').value = s.storage_enabled ? '1' : '0';
        document.getElementById('retention_keep_last').value = s.retention_keep_last ?? '';
        document.getElementById('auto_prune').value = s.auto_prune ? '1' : '0';
        document.getElementById('automatic_backup_enabled').value = s.automatic_backup_enabled ? '1' : '0';
        document.getElementById('min_free_space_mb').value = s.min_free_space_mb;
        document.getElementById('cword').textContent = s.restore_confirmation_word || 'RESTORE';
    });
}

function saveSettings() {
    var body = {
        database_enabled: document.getElementById('database_enabled').value === '1',
        storage_enabled: document.getElementById('storage_enabled').value === '1',
        retention_keep_last: document.getElementById('retention_keep_last').value === '' ? null : parseInt(document.getElementById('retention_keep_last').value, 10),
        auto_prune: document.getElementById('auto_prune').value === '1',
        automatic_backup_enabled: document.getElementById('automatic_backup_enabled').value === '1',
        min_free_space_mb: parseInt(document.getElementById('min_free_space_mb').value, 10)
    };
    KH.put('/admin/api/backups/settings', body).then(function (r) {
        if (!r.ok) { showBanner('Save failed', 'danger'); return; }
        showBanner('Settings saved.', 'ok');
        render();
    });
}

function showBanner(msg, type) {
    var b = document.getElementById('banner');
    b.innerHTML = '<div class="alert alert--' + (type || 'info') + '">' + KH.esc(msg) + '</div>';
    setTimeout(function () { b.innerHTML = ''; }, 5000);
}

render();
</script>
@endpush
