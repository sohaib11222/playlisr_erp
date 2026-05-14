@extends('layouts.app')
@section('title', 'Update Product Cost')

@section('content')
<script>document.body.classList.add('role-picker');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.role-picker { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.role-picker .content-wrapper { background: #FAF6EE !important; }
body.role-picker .content-header { background: transparent; padding: 28px 16px 8px; }
body.role-picker .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.role-picker .content-header p { color: #5A5045; margin: 0; font-size: 14px; max-width: 760px; }
body.role-picker .upc-wrap { max-width: 980px; padding: 0 16px 60px; }
body.role-picker .upc-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker .upc-card h3 { margin: 0 0 12px; font-size: 18px; font-weight: 700; color: #1F1B16; }
body.role-picker .upc-search { display:flex; gap:10px; align-items:center; }
body.role-picker .upc-search input[type=text] { flex:1; padding:11px 14px; border:1px solid #D7CDB6; border-radius:8px; font-size:15px; background:#FFFCF5; }
body.role-picker .upc-btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:8px 18px; border:0; border-radius:8px; font-family:inherit; font-weight:700; font-size:14px; cursor:pointer; background:#1F1B16; color:#FAF6EE; }
body.role-picker .upc-btn.save { background:#2F6B3E; }
body.role-picker .upc-btn:hover { background:#000; }
body.role-picker .upc-btn.save:hover { background:#1F4421; }
body.role-picker table.upc-table { width: 100%; border-collapse: collapse; }
body.role-picker table.upc-table th, body.role-picker table.upc-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ECE3CF; font-size: 14px; vertical-align: middle; }
body.role-picker table.upc-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; }
body.role-picker table.upc-table code { background: #F7F1E3; padding: 2px 6px; border-radius: 4px; color: #1F1B16; font-size: 13px; }
body.role-picker table.upc-table .new-cost { width: 110px; padding: 7px 10px; border:1px solid #D7CDB6; border-radius:6px; font-size:14px; }
body.role-picker .upc-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
body.role-picker .upc-alert.success { background: #D9F0D3; border-left: 4px solid #2F6B3E; color: #1F4421; }
body.role-picker .upc-alert.error { background: #F8D7DA; border-left: 4px solid #8A3A2E; color: #5A1A14; }
body.role-picker .upc-cost { color:#5A5045; font-size:13px; }
</style>

<section class="content-header">
    <h1>Update Product Cost</h1>
    <p>Direct cost edit by SKU or product name. Skips the product-edit form entirely. Writes both <code>default_purchase_price</code> and <code>dpp_inc_tax</code> to the new value (per Nivessa resale-cert mirror). Each save is snapshotted to <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a> for one-click undo.</p>
</section>

<section class="content">
    <div class="upc-wrap">
        @if(session('status') && is_array(session('status')))
            <div class="upc-alert {{ session('status.success') ? 'success' : 'error' }}">
                {{ session('status.msg') }}
            </div>
        @endif

        <div class="upc-card">
            <h3>Find product</h3>
            <form method="GET" action="{{ url('/admin/update-product-cost') }}" class="upc-search">
                <input type="text" name="q" value="{{ $q }}" placeholder="SKU or product name (e.g. AT-SP3X or Bookshelf)" autofocus>
                <button type="submit" class="upc-btn">Search</button>
            </form>
        </div>

        @if($q !== '')
            <div class="upc-card">
                <h3>Results ({{ $rows->count() }})</h3>

                @if($rows->isEmpty())
                    <p class="upc-cost">No products match <code>{{ $q }}</code>. Try a different SKU or partial name.</p>
                @else
                    <table class="upc-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th>Current Cost</th>
                                <th>Sell Price</th>
                                <th>New Cost</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr>
                                    <td>{{ $r->name }}</td>
                                    <td><code>{{ $r->sku }}</code></td>
                                    <td class="upc-cost">{{ $r->category ?? '—' }}</td>
                                    <td>${{ number_format((float)$r->inc_tax, 2) }}<br><span class="upc-cost">exc: ${{ number_format((float)$r->exc_tax, 2) }}</span></td>
                                    <td class="upc-cost">${{ number_format((float)$r->sell, 2) }}</td>
                                    <td>
                                        <form method="POST" action="{{ url('/admin/update-product-cost/run') }}" style="margin:0;">
                                            @csrf
                                            <input type="hidden" name="variation_id" value="{{ $r->variation_id }}">
                                            <input type="number" step="0.01" min="0.01" name="new_cost" class="new-cost" placeholder="0.00" required>
                                    </td>
                                    <td>
                                            <button type="submit" class="upc-btn save">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    </div>
</section>
@endsection
