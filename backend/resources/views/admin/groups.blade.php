@extends('admin.layout')
@section('page_title', 'Groups')
@section('title', 'KHWWC — Groups')
@section('content')
<section class="page-head"><h1 class="page-head__title">Groups</h1><p class="page-head__subtitle">Role distribution across the organisation.</p></section>
<div class="card"><div class="card__head"><h2 class="card__title">Roles</h2></div>
<div style="overflow:auto"><table class="table" id="t"><thead><tr><th>Role</th><th>Members</th></tr></thead><tbody><tr><td colspan="2" class="muted">Loading…</td></tr></tbody></table></div>
</div>
@endsection
@push('scripts')
<script>
KH.get('/admin/api/groups').then(function(r){
  if(!r.ok) return;
  var tb=document.querySelector('#t tbody');
  tb.innerHTML=r.data.groups.map(function(g){
    return '<tr><td><span class="badge badge--info">'+KH.esc(g.role)+'</span></td><td>'+g.total+'</td></tr>';
  }).join('');
});
</script>
@endpush
