@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sc-wrap { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sc-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 6px; }
.sc-sub { color: #6B6155; font-size: 15px; margin: 0 0 14px; }
.sc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E6DCCF; font-size: 15px; }
.sc-table th { text-align: left; background: #FAF6EE; padding: 9px 12px; font-size: 13px; color: #6B6155; position: sticky; top: 0; }
.sc-table td { padding: 9px 12px; border-top: 1px solid #F3ECE1; vertical-align: middle; }
.sc-in { width: 70px; height: 40px; font-size: 18px; text-align: center; border: 1px solid #E1D7C4; border-radius: 8px; }
.sc-small { color: #8E8273; font-size: 12px; }
.sc-msg { font-size: 12px; }
.sc-msg.miss { color: #8E1B1B; font-weight: 700; }
.sc-msg.ok { color: #2E6B2E; }
</style>
<div class="sc-wrap">
    <p><a href="/reports/shelf-count?location_id={{ $loc }}">Back to sections</a></p>
    <h1>{{ $name }} at {{ $locations[$loc] ?? '' }}</h1>
    <p class="sc-sub">{{ $items->count() }} titles the ERP says are here. Type how many you find of each (0 if none). It saves when you leave the box.</p>
    <table class="sc-table">
        <thead><tr><th>Title</th><th>ERP says</th><th>Counted</th><th></th></tr></thead>
        <tbody>
        @foreach ($items as $i)
            @php $c = $counted[$i->id] ?? null; @endphp
            <tr>
                <td><a href="/products/{{ $i->id }}/edit" target="_blank">{{ $i->name }}</a>@if($i->artist && stripos($i->name, $i->artist) === false)<div class="sc-small">{{ $i->artist }}</div>@endif
                    <div class="sc-small">{{ $i->sku }}@if($c) , last counted {{ \Carbon\Carbon::parse($c['at'])->format('n/j') }} by {{ $c['by'] }}@endif</div></td>
                <td class="sc-erp">{{ rtrim(rtrim(number_format($i->qty, 2), '0'), '.') }}</td>
                <td><input class="sc-in" type="number" min="0" inputmode="numeric" data-id="{{ $i->id }}" data-erp="{{ (float) $i->qty }}"></td>
                <td><span class="sc-msg"></span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    document.querySelectorAll('.sc-in').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var v = inp.value.trim(); if (v === '') return;
            var msg = inp.closest('tr').querySelector('.sc-msg'), erp = parseFloat(inp.getAttribute('data-erp'));
            msg.className = 'sc-msg'; msg.textContent = 'Saving...';
            fetch('/reports/reorder/count', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: inp.getAttribute('data-id'), qty: v, location_id: {{ (int) $loc }} }) })
                .then(function (r) { return r.json(); }).then(function (j) {
                    if (!j.ok) { msg.textContent = 'Did not save'; return; }
                    var n = parseInt(v, 10);
                    inp.closest('tr').querySelector('.sc-erp').textContent = n;
                    if (n < erp) { msg.className = 'sc-msg miss'; msg.textContent = (erp - n) + ' missing'; }
                    else { msg.className = 'sc-msg ok'; msg.textContent = 'Saved'; }
                }).catch(function () { msg.textContent = 'Did not save, try again'; });
        });
    });
})();
</script>
@endsection
