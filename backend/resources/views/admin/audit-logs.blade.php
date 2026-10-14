@extends('admin.layout')
@section('page_title', 'Audit Logs')
@section('title', 'KHWWC — Audit Logs')
@section('content')
<section class="page-head spread">
  <div><h1 class="page-head__title">Audit Logs</h1><p class="page-head__subtitle">Security and administrative events, including all backup &amp; restore operations.</p></div>
  <div class="row">
    <label class="form-label" style="margin:0"><input type="checkbox" id="backup-only" onchange="load()"> Backup &amp; restore only</label>
  </div>
</section>
<div class="card"><div class="card__head"><h2 class="card__title">Events</h2></div>
<div style="overflow:auto"><table class="table" id="t"><thead><tr><th>Time</th><th>Action</th><th>Admin</th><th>Details</th><th>IP</th></tr></thead><tbody><tr><td colspan="5" class="muted">Loading…</td></tr></tbody></table></div>
</div>
@endsection
@push('scripts')
<script>
function load(){
  var only=document.getElementById('backup-only').checked;
  KH.get('/admin/api/audit-logs?backup_only='+(only?'1':'0')).then(function(r){
    if(!r.ok) return;
    var tb=document.querySelector('#t tbody');
    tb.innerHTML=r.data.audit_logs.map(function(a){
      var d='';
      try { d=JSON.stringify(JSON.parse(a.details||'{}')); } catch(e){ d=a.details||''; }
      var act=a.action.replace(/_/g,' ').toLowerCase();
      return '<tr><td class="muted">'+KH.esc(a.created_at||'')+'</td><td><span class="badge badge--muted">'+KH.esc(a.action)+'</span></td><td>'+KH.esc(a.super_admin_id?a.super_admin_id.slice(0,8):'—')+'</td><td class="text-sm">'+KH.esc(d)+'</td><td class="muted">'+KH.esc(a.ip_address||'—')+'</td></tr>';
    }).join('');
  });
}
load();
</script>
@endpush
