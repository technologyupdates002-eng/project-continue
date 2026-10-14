@extends('admin.layout')

@section('page_title', 'Backups & Restore')
@section('title', 'KHWWC — Backups & Restore')

@section('content')
<section class="page-head spread">
    <div>
        <h1 class="page-head__title">Backups &amp; Restore</h1>
        <p class="page-head__subtitle">Create, verify, download, inspect and restore Super Admin backups.</p>
    </div>
    <div class="row">
        <button class="btn" type="button" onclick="loadBackups()">Refresh</button>
        <div class="row">
            <button class="btn btn--primary" type="button" onclick="createBackup('full')">Create Full Backup</button>
            <button class="btn btn--ghost" type="button" onclick="createBackup('database')">DB only</button>
            <button class="btn btn--ghost" type="button" onclick="createBackup('storage')">Storage only</button>
        </div>
    </div>
</section>

<div id="banner"></div>

<div class="grid grid--stats mb-2" id="stat-grid">
    <div class="card stat"><div class="stat__label">Total</div><div class="stat__value" id="s-total">—</div></div>
    <div class="card stat"><div class="stat__label">Successful</div><div class="stat__value" id="s-completed">—</div></div>
    <div class="card stat"><div class="stat__label">Failed</div><div class="stat__value" id="s-failed">—</div></div>
    <div class="card stat"><div class="stat__label">Verified</div><div class="stat__value" id="s-verified">—</div></div>
    <div class="card stat"><div class="stat__label">Latest Verified</div><div class="stat__value" id="s-latest">—</div></div>
    <div class="card stat"><div class="stat__label">Storage Used</div><div class="stat__value" id="s-storage">—</div></div>
</div>

<div class="card">
    <div class="card__head"><h2 class="card__title">Backup history</h2></div>
    <div style="overflow:auto">
        <table class="table" id="backup-table">
            <thead>
                <tr>
                    <th>Name</th><th>Type</th><th>Size</th><th>Status</th><th>Verified</th>
                    <th>Created By</th><th>Created At</th><th>Actions</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="8" class="muted">Loading…</td></tr></tbody>
        </table>
    </div>
</div>

<!-- View details modal -->
<div class="modal-backdrop" id="view-modal">
    <div class="modal">
        <div class="modal__head">Backup details</div>
        <div class="modal__body" id="view-body">Loading…</div>
        <div class="modal__foot"><button class="btn" onclick="KH.closeModal('view-modal')">Close</button></div>
    </div>
</div>

<!-- Restore confirm modal -->
<div class="modal-backdrop" id="restore-modal">
    <div class="modal">
        <div class="modal__head">Confirm Restore</div>
        <div class="modal__body">
            <div class="alert alert--danger">
                <b>WARNING</b><br>
                Restoring this backup may replace current application data. Current data may be overwritten.
                This operation should only be performed when you are certain the selected backup is correct.
            </div>
            <p class="text-sm">A safety backup of the <b>current</b> state is created automatically before restoration.</p>
            <div id="restore-info" class="muted mb-2"></div>
            <div class="form-row">
                <label class="form-label">Type <b id="restore-word">RESTORE</b> to continue</label>
                <input class="form-input" id="restore-confirm" type="text" autocomplete="off" placeholder="RESTORE">
            </div>
        </div>
        <div class="modal__foot">
            <button class="btn" onclick="KH.closeModal('restore-modal')">Cancel</button>
            <button class="btn btn--danger" id="restore-go" type="button" onclick="confirmRestore()">Restore</button>
        </div>
    </div>
</div>

<!-- Delete confirm modal -->
<div class="modal-backdrop" id="delete-modal">
    <div class="modal">
        <div class="modal__head">Delete backup</div>
        <div class="modal__body">
            <div class="alert alert--warn">This permanently removes the backup file. This cannot be undone.</div>
            <div id="delete-info" class="muted mb-2"></div>
        </div>
        <div class="modal__foot">
            <button class="btn" onclick="KH.closeModal('delete-modal')">Cancel</button>
            <button class="btn btn--danger" id="delete-go" type="button" onclick="confirmDelete()">Delete</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
var RESTORE_WORD = 'RESTORE';
var pendingRestoreId = null;
var pendingDeleteId = null;

function loadBackups() {
    KH.get('/admin/api/backups').then(function (r) {
        if (!r.ok) { showBanner('Failed to load backups.', 'danger'); return; }
        renderStats(r.data.stats);
        RESTORE_WORD = (r.data.settings && r.data.settings.restore_confirmation_word) || 'RESTORE';
        renderTable(r.data.backups);
    });
}

function renderStats(s) {
    s = s || {};
    document.getElementById('s-total').textContent = s.total ?? '—';
    document.getElementById('s-completed').textContent = s.completed ?? '—';
    document.getElementById('s-failed').textContent = s.failed ?? '—';
    document.getElementById('s-verified').textContent = s.verified ?? '—';
    document.getElementById('s-latest').textContent = s.latest_verified_backup_id ? 'yes' : 'none';
    document.getElementById('s-storage').textContent = formatBytes(s.storage_used_bytes || 0);
}

function renderTable(backups) {
    var tbody = document.querySelector('#backup-table tbody');
    if (!backups.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="muted">No backups yet. Create your first backup above.</td></tr>';
        return;
    }
    tbody.innerHTML = backups.map(function (b) {
        var typeBadge = '<span class="badge badge--brand">' + KH.esc(b.type) + '</span>'
            + (b.is_safety ? ' <span class="badge badge--muted">safety</span>' : '');
        var actions = [];
        actions.push('<button class="btn btn--sm" onclick="viewBackup(\'' + b.id + '\')">View</button>');
        if (b.downloadable) {
            actions.push('<a class="btn btn--sm" href="/admin/api/backups/' + b.id + '/download">Download</a>');
        }
        if (b.restorable) {
            actions.push('<button class="btn btn--sm btn--danger" onclick="openRestore(\'' + b.id + '\')">Restore</button>');
        }
        actions.push('<button class="btn btn--sm btn--ghost" onclick="openDelete(\'' + b.id + '\')">Delete</button>');
        return '<tr>'
            + '<td>' + KH.esc(b.name) + '</td>'
            + '<td>' + typeBadge + '</td>'
            + '<td>' + KH.esc(b.size_human) + '</td>'
            + '<td>' + KH.statusBadge(b.status) + '</td>'
            + '<td>' + KH.verifyBadge(b.verification_status) + '</td>'
            + '<td>' + KH.esc(b.created_by ? b.created_by.slice(0,8) : '—') + '</td>'
            + '<td>' + KH.esc(b.created_at ? b.created_at.replace('T',' ').slice(0,19) : '—') + '</td>'
            + '<td class="row">' + actions.join(' ') + '</td>'
            + '</tr>';
    }).join('');
}

function createBackup(type) {
    if (!confirm('Create a ' + type + ' backup now?')) { return; }
    showBanner('Creating ' + type + ' backup…', 'info');
    KH.post('/admin/api/backups', { type: type }).then(function (r) {
        if (!r.ok) {
            showBanner('Backup failed: ' + errMsg(r.data), 'danger');
            return;
        }
        showBanner('Backup created and verified.', 'ok');
        loadBackups();
    });
}

function viewBackup(id) {
    KH.get('/admin/api/backups/' + id).then(function (r) {
        if (!r.ok) { return; }
        var b = r.data.backup;
        var rows = [
            ['ID', b.id],
            ['Type', b.type + (b.is_safety ? ' (safety)' : '')],
            ['Status', b.status],
            ['Verification', b.verification_status + (b.verified_at ? ' @ ' + b.verified_at : '')],
            ['Size', b.size_human + ' (' + b.size_bytes + ' bytes)'],
            ['Checksum', b.checksum],
            ['App version', b.application_version],
            ['Laravel', b.laravel_version],
            ['DB engine', b.database_engine + ' ' + b.database_version],
            ['Created by', b.created_by],
            ['Created', b.created_at],
            ['Completed', b.completed_at],
        ];
        var html = '<table class="table table--compact">'
            + rows.map(function (x) { return '<tr><td class="muted">' + KH.esc(x[0]) + '</td><td>' + KH.esc(x[1] || '—') + '</td></tr>'; }).join('')
            + '</table>';
        if (b.metadata) {
            html += '<div class="mt-2"><div class="form-label">Metadata</div><pre class="code-block">' + KH.esc(JSON.stringify(b.metadata, null, 2)) + '</pre></div>';
        }
        if (b.verification_details) {
            html += '<div class="mt-2"><div class="form-label">Verification details</div><pre class="code-block">' + KH.esc(JSON.stringify(b.verification_details, null, 2)) + '</pre></div>';
        }
        if (b.failure_reason) {
            html += '<div class="mt-2 alert alert--danger">' + KH.esc(b.failure_reason) + '</div>';
        }
        document.getElementById('view-body').innerHTML = html;
        KH.openModal('view-modal');
    });
}

function openRestore(id) {
    pendingRestoreId = id;
    document.getElementById('restore-word').textContent = RESTORE_WORD;
    document.getElementById('restore-confirm').value = '';
    document.getElementById('restore-info').textContent = 'Backup: ' + id;
    KH.openModal('restore-modal');
}

function confirmRestore() {
    var val = document.getElementById('restore-confirm').value;
    if (val !== RESTORE_WORD) {
        showBanner('Confirmation word did not match. Restore aborted.', 'warn');
        return;
    }
    var btn = document.getElementById('restore-go');
    btn.disabled = true;
    KH.post('/admin/api/backups/' + pendingRestoreId + '/restore', { confirm_word: val }).then(function (r) {
        btn.disabled = false;
        KH.closeModal('restore-modal');
        if (!r.ok) {
            showBanner('Restore failed: ' + errMsg(r.data), 'danger');
        } else {
            var w = r.data.warning ? ' (warning: ' + r.data.warning + ')' : '';
            showBanner('Restore completed.' + w, 'ok');
        }
        loadBackups();
    });
}

function openDelete(id) {
    pendingDeleteId = id;
    document.getElementById('delete-info').textContent = 'Backup: ' + id;
    KH.openModal('delete-modal');
}

function confirmDelete() {
    var btn = document.getElementById('delete-go');
    btn.disabled = true;
    KH.del('/admin/api/backups/' + pendingDeleteId).then(function (r) {
        btn.disabled = false;
        KH.closeModal('delete-modal');
        if (!r.ok) { showBanner('Delete failed: ' + errMsg(r.data), 'danger'); }
        else { showBanner('Backup deleted.', 'ok'); }
        loadBackups();
    });
}

function showBanner(msg, type) {
    var b = document.getElementById('banner');
    b.innerHTML = '<div class="alert alert--' + (type || 'info') + '">' + KH.esc(msg) + '</div>';
    setTimeout(function () { b.innerHTML = ''; }, 6000);
}
function errMsg(d) { return (d && d.error && d.error.message) ? d.error.message : 'Unknown error'; }
function formatBytes(b) {
    var u = ['B','KB','MB','GB']; var i = b > 0 ? Math.floor(Math.log(b)/Math.log(1024)) : 0;
    return (b/Math.pow(1024,i)).toFixed(2)+' '+u[Math.min(i,u.length-1)];
}

loadBackups();
</script>
@endpush
