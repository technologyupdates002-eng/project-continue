@extends('admin.layout')
@section('page_title', 'Users')
@section('title', 'KHWWC — Users')
@section('content')
<section class="page-head"><h1 class="page-head__title">Users</h1><p class="page-head__subtitle">All authenticated accounts and their roles.</p></section>
<div class="card"><div class="card__head"><h2 class="card__title">Users</h2></div>
<div style="overflow:auto"><table class="table" id="t"><thead><tr><th>Email</th><th>Name</th><th>Phone</th><th>Roles</th><th>Status</th><th>Created</th></tr></thead><tbody><tr><td colspan="6" class="muted">Loading…</td></tr></tbody></table></div>
</div>
@endsection
@push('scripts')
<script>
KH.get('/admin/api/users').then(function(r){
  if(!r.ok) return;
  var tb=document.querySelector('#t tbody');
  tb.innerHTML=r.data.users.map(function(u){
    var roles=(u.roles||[]).map(function(x){return '<span class="badge badge--info">'+KH.esc(x)+'</span>';}).join(' ');
    var st=u.is_banned?'<span class="badge badge--danger">banned</span>':'<span class="badge badge--ok">active</span>';
    return '<tr><td>'+KH.esc(u.email)+'</td><td>'+KH.esc(u.member_name||'—')+'</td><td>'+KH.esc(u.phone||'—')+'</td><td>'+roles+'</td><td>'+st+'</td><td>'+KH.esc(u.created_at||'')+'</td></tr>';
  }).join('');
});
</script>
@endpush
