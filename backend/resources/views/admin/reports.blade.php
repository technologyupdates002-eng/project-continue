@extends('admin.layout')
@section('page_title', 'Reports')
@section('title', 'KHWWC — Reports')
@section('content')
<section class="page-head"><h1 class="page-head__title">Reports</h1><p class="page-head__subtitle">Key organisational metrics.</p></section>
<div id="banner"></div>
<div class="grid grid--stats mb-2" id="grid"></div>
@endsection
@push('scripts')
<script>
KH.get('/admin/api/reports').then(function(r){
  if(!r.ok){return;}
  var x=r.data.reports;
  var cards=[
    ['Members', x.members],
    ['Active members', x.active_members],
    ['Total contributions (paid)', x.total_contributions]
  ];
  document.getElementById('grid').innerHTML=cards.map(function(c){
    return '<div class="card stat"><div class="stat__label">'+c[0]+'</div><div class="stat__value">'+KH.esc(c[1])+'</div></div>';
  }).join('');
});
</script>
@endpush
