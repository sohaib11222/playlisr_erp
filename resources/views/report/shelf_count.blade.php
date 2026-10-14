@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sc-wrap { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sc-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.sc-wrap h2 { font-size: 16px; margin: 18px 0 6px; color: #6B6155; }
.sc-sub { color: #6B6155; font-size: 15px; margin: 0 0 14px; }
.sc-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.sc-pill { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 6px 14px; font-weight: 600; color: #1F1B16; text-decoration: none; }
.sc-pill.on { background: #1F1B16; color: #FFE8A3; border-color: #1F1B16; }
.sc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E6DCCF; font-size: 15px; }
.sc-table td { padding: 9px 12px; border-top: 1px solid #F3ECE1; vertical-align: middle; }
.sc-in { width: 70px; height: 40px; font-size: 18px; text-align: center; border: 1px solid #E1D7C4; border-radius: 8px; }
.sc-small { color: #8E8273; font-size: 12px; }
.sc-msg { font-size: 12px; }
.sc-msg.miss { color: #8E1B1B; font-weight: 700; }
.sc-msg.ok { color: #2E6B2E; }
</style>
<div class="sc-wrap">
    <h1>This week's shelf count</h1>
    <p class="sc-sub">{{ number_format($fresh) }} of {{ number_format($total) }} titles at this store were counted in the last {{ $dueDays }} days. Below are the next {{ count($groups, COUNT_RECURSIVE) - count($groups) }} to count, sealed first, in bin order. Type how many you find (0 if none, check the wall and shelves above too). It saves when you leave the box, the ERP and website update, and anything short goes on the <a href="/reports/shelf-count/missing?location_id={{ $loc }}">Missing list</a>.</p>
    <div class="sc-bar">
        @foreach ($locations as $id => $nm)
            <a class="sc-pill {{ $id == $loc ? 'on' : '' }}" href="?location_id={{ $id }}">{{ $nm }}</a>
        @endforeach
        <a class="sc-pill" href="/reports/shelf-count/missing?location_id={{ $loc }}">Missing list</a>
    </div>
    @forelse ($groups as $g => $items)
        <h2>{{ $g }}</h2>
        <table class="sc-table"><tbody>
        @foreach ($items as $i)
            <tr>
                <td><a href="/products/{{ $i->id }}/edit" target="_blank">{{ $i->name }}</a>@if($i->artist && stripos($i->name, $i->artist) === false) <span class="sc-small">{{ $i->artist }}</span>@endif
                    <div class="sc-small">ERP says <span class="sc-erp">{{ rtrim(rtrim(number_format($i->qty, 2), '0'), '.') }}</span>, {{ $i->sku }}</div></td>
                <td style="width:90px;"><input class="sc-in" type="number" min="0" inputmode="numeric" data-id="{{ $i->id }}" data-erp="{{ (float) $i->qty }}"></td>
                <td style="width:110px;"><span class="sc-msg"></span></td>
            </tr>
        @endforeach
        </tbody></table>
    @empty
        <p>Everything here was counted in the last {{ $dueDays }} days.</p>
    @endforelse
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    document.querySelectorAll('.sc-in').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var v = inp.value.trim(); if (v === '') return;
            var tr = inp.closest('tr'), msg = tr.querySelector('.sc-msg'), erp = parseFloat(inp.getAttribute('data-erp'));
            msg.className = 'sc-msg'; msg.textContent = 'Saving...';
            fetch('/reports/reorder/count', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: inp.getAttribute('data-id'), qty: v, location_id: {{ (int) $loc }} }) })
                .then(function (r) { return r.json(); }).then(function (j) {
                    if (!j.ok) { msg.textContent = 'Did not save'; return; }
                    var n = parseInt(v, 10);
                    tr.querySelector('.sc-erp').textContent = n;
                    if (n < erp) { msg.className = 'sc-msg miss'; msg.textContent = (erp - n) + ' missing'; } else { msg.className = 'sc-msg ok'; msg.textContent = 'Saved'; }
                }).catch(function () { msg.textContent = 'Did not save, try again'; });
        });
    });
})();
</script>
@endsection
