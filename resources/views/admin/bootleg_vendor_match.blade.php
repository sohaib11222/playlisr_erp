@extends('layouts.app')
@section('title', 'Bootleg Vendor Match')

@section('content')
<section class="content-header">
    <h1>Bootleg Vendor Match</h1>
    <p class="text-muted">
        Matches known bootleg-vendor price lists ({{ number_format($catalogCount) }} titles across all uploaded
        catalogs) against real inventory by name. Check off the products that are ACTUALLY the bootleg copy, then
        Apply — nothing is touched until you check a box and submit.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="row">
        <div class="col-md-12">
            <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-warning' }}">
                {{ session('status')['msg'] }}
            </div>
        </div>
    </div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">{{ count($rows) }} candidate match(es)</h3>
            </div>
            <div class="box-body" style="padding:0;">
                @if (count($rows) === 0)
                    <p style="padding:16px;" class="text-muted">No products in inventory matched a title on the catalog.</p>
                @else
                    <form method="POST" action="{{ url('/admin/bootleg-vendor-match/apply') }}" id="bvm-form">
                        @csrf
                        <table class="table table-striped" style="margin:0;">
                            <thead>
                                <tr>
                                    <th style="width:40px;"><input type="checkbox" id="bvm-check-all"></th>
                                    <th style="width:80px;">Product ID</th>
                                    <th>Product name (ERP)</th>
                                    <th>Matched catalog title</th>
                                    <th style="width:110px;">Section</th>
                                    <th style="width:110px;">Confirmed bootleg pattern</th>
                                    <th style="text-align:right;width:110px;">Current stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr>
                                        <td><input type="checkbox" name="product_ids[]" value="{{ $r['product_id'] }}" class="bvm-row-check"></td>
                                        <td>{{ $r['product_id'] }}</td>
                                        <td>{{ $r['product_name'] }}</td>
                                        <td class="text-muted">{{ $r['catalog_title'] }}</td>
                                        <td>{{ $r['section'] }}</td>
                                        <td>{{ $r['likely_bootleg_keyword'] ? 'Yes (live/demo/rarity wording)' : '—' }}</td>
                                        <td style="text-align:right;">{{ number_format($r['stock']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div style="padding:16px;">
                            <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Zero stock on every checked product? This writes to the DB (undoable at Admin Action History).');">
                                Zero stock on checked products
                            </button>
                        </div>
                    </form>
                    <script>
                        document.getElementById('bvm-check-all').addEventListener('change', function () {
                            document.querySelectorAll('.bvm-row-check').forEach(function (cb) { cb.checked = this.checked; }.bind(this));
                        });
                    </script>
                @endif
            </div>
        </div>
    </div>
</div>

</section>
@endsection
