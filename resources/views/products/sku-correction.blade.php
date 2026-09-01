@extends('layouts.app')
@section('title', 'SKU Correction')

@section('content')
<script>document.body.classList.add('merge-v2');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.merge-v2 { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.merge-v2 .content-wrapper { background: #FAF6EE !important; }
body.merge-v2 .content-header { background: transparent; padding: 28px 16px 8px; }
body.merge-v2 .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.merge-v2 .content { padding: 0 16px 60px; }
.sc-wrap { max-width: 900px; }
.sc-card { background: #fff; border: 1px solid #ECE3D2; border-radius: 16px; padding: 20px 22px; box-shadow: 0 1px 2px rgba(31,27,22,.04); margin-bottom: 14px; }
.sc-name { font-size: 17px; font-weight: 700; color: #1F1B16; }
.sc-meta { font-size: 14px; color: #1F1B16; margin-top: 4px; }
.sc-row { display: flex; gap: 14px; align-items: flex-end; margin-top: 14px; flex-wrap: wrap; }
.sc-field { flex: 1 1 220px; }
.sc-field label { display: block; font-size: 12.5px; font-weight: 600; margin: 0 0 6px; color: #8E8273; text-transform: uppercase; letter-spacing: .3px; }
.sc-field input { width: 100%; height: 42px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 12px; font-size: 15px; font-family: inherit; background: #FEFCF7; }
.sc-field input:focus { outline: none; border-color: #C9A227; box-shadow: 0 0 0 3px #FFF2B3; }
.sc-btn { height: 42px; padding: 0 18px; border-radius: 10px; border: 0; font-family: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer; background: #1F1B16; color: #FFF2B3; }
.sc-btn:disabled { opacity: .5; cursor: not-allowed; }
.sc-result { margin-top: 10px; font-size: 13.5px; font-weight: 600; }
.sc-result.ok { color: #1B5E20; }
.sc-result.err { color: #B71C1C; }
.sc-empty { color: #8E8273; font-size: 14.5px; }
</style>

<section class="content-header"><h1>SKU Correction<br><small>For products with the wrong barcode — not duplicates, genuinely different items that got the same barcode by mistake (e.g. a bulk-Discogs-add that copied one release's data onto several rows). Corrects the SKU in place. Undoable at Admin Action History.</small></h1></section>

<section class="content">
<div class="sc-wrap">
    @if($products->isEmpty())
        <div class="sc-card"><p class="sc-empty">No product ids given. Open this page with <code>?ids=13216,13215,13214</code> (comma-separated ERP product ids) to load the ones that need fixing.</p></div>
    @endif
    @foreach($products as $p)
        <div class="sc-card" data-product-id="{{ $p->id }}">
            <div class="sc-name">{{ $p->name }}</div>
            <div class="sc-meta">{{ $p->artist }} — current SKU <strong>{{ $p->sku }}</strong> (id {{ $p->id }})</div>
            <div class="sc-row">
                <div class="sc-field">
                    <label>Correct barcode</label>
                    <input type="text" class="sc-new-sku" placeholder="e.g. 602445790074" autocomplete="off">
                </div>
                <button class="sc-btn sc-apply" type="button">Apply</button>
            </div>
            <div class="sc-result"></div>
        </div>
    @endforeach
</div>
</section>
@stop

@section('javascript')
<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute
        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';

    document.querySelectorAll('.sc-card').forEach(function (card) {
        var btn = card.querySelector('.sc-apply');
        var input = card.querySelector('.sc-new-sku');
        var result = card.querySelector('.sc-result');
        btn.addEventListener('click', function () {
            var newSku = input.value.trim();
            if (!newSku) { result.className = 'sc-result err'; result.textContent = 'Enter the correct barcode first.'; return; }
            btn.disabled = true;
            fetch('{{ route('products.applySkuCorrection') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: card.getAttribute('data-product-id'), new_sku: newSku }),
            }).then(function (r) { return r.json(); }).then(function (d) {
                btn.disabled = false;
                result.className = 'sc-result ' + (d.success ? 'ok' : 'err');
                result.textContent = d.msg || (d.success ? 'Done.' : 'Failed.');
                if (d.success) { btn.textContent = 'Applied'; input.disabled = true; }
            }).catch(function () {
                btn.disabled = false;
                result.className = 'sc-result err';
                result.textContent = 'Failed — try again.';
            });
        });
    });
})();
</script>
@endsection
