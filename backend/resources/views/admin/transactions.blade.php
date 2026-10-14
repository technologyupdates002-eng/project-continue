@extends('admin.layout')
@section('page_title', 'Transactions')
@section('title', 'KHWWC — Transactions')
@section('content')
<section class="page-head"><h1 class="page-head__title">Transactions</h1><p class="page-head__subtitle">Recent member contributions.</p></section>
<div class="card"><div class="card__head"><h2 class="card__title">Contributions</h2></div>
<div style="overflow:auto"><table class="table" id="t"><thead><tr><th>Member</th><th>Amount</th><th>Status</th><th>Paid</th><th>Period</th></tr></thead><tbody><tr><td colspan="5" class="muted">Loading…</td></tr></tbody></table></div>
</div>
@endsection
@push('scripts')
<script>
KH.get('/admin/api/transactions').then(function(r){
  if(!r.ok) return;
  var tb=document.querySelector('#t tbody');
  tb.innerHTML=r.data.transactions.map(function(x){
    return '<tr><td>'+KH.esc(x.member_id||'—')+'</td><td>'+KH.esc(x.amount)+'</td><td><span class="badge '+(x.status==='paid'?'badge--ok':'badge--warn')+'">'+KH.esc(x.status)+'</span></td><td>'+KH.esc(x.paid_date||'—')+'</td><td>'+KH.esc(x.year)+'-'+KH.esc(x.month)+'</td></tr>';
  }).join('');
});
</script>
@endpush
