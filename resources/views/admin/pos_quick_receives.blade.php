@extends('layouts.app')
@section('title', 'POS Quick Receives')

@section('content')
<section class="content-header">
    <h1>
        POS Quick Receives
        <small>every time a cashier received an out-of-stock item at the register</small>
    </h1>
</section>

<section class="content">

    @if(session('status_success'))
        <div class="alert alert-success">{{ session('status_success') }}</div>
    @endif
    @if(session('status_error'))
        <div class="alert alert-danger">{{ session('status_error') }}</div>
    @endif

    @if(!$tableExists)
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">One-time setup</h3>
            </div>
            <div class="box-body">
                <p>This page needs one new empty table (<code>pos_quick_receives</code>) and one permission grant
                   (<em>quick receive at POS</em>) before cashiers can use it. Click below — it creates only this new
                   table and updates role permissions. <strong>It doesn't touch any existing data.</strong> Safe to re-click.</p>
                <form method="POST" action="{{ url('/admin/pos-quick-receives/setup') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-warning"><i class="fa fa-cog"></i> Set it up</button>
                </form>
            </div>
        </div>
    @else
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Filters</h3>
            </div>
            <div class="box-body">
                <form method="GET" action="{{ url('/admin/pos-quick-receives') }}" class="form-inline">
                    <div class="form-group" style="margin-right:12px;">
                        <label>Last</label>
                        <select name="days" class="form-control input-sm">
                            @foreach([1,3,7,14,30,60,90,180,365] as $d)
                                <option value="{{ $d }}" {{ (int)$filters['days']===$d ? 'selected' : '' }}>{{ $d }} day{{ $d===1?'':'s' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" style="margin-right:12px;">
                        <label>Cashier</label>
                        <input type="text" name="user" value="{{ $filters['user'] }}" class="form-control input-sm" placeholder="name or username">
                    </div>
                    <div class="form-group" style="margin-right:12px;">
                        <label>Store</label>
                        <select name="location" class="form-control input-sm">
                            <option value="0">All stores</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ (int)$filters['location']===(int)$loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-filter"></i> Apply</button>
                    <a href="{{ url('/admin/pos-quick-receives') }}" class="btn btn-default btn-sm">Reset</a>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-aqua">
                    <span class="info-box-icon"><i class="fa fa-archive"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Quick receives</span>
                        <span class="info-box-number">{{ number_format($totals->count) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-green">
                    <span class="info-box-icon"><i class="fa fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Units received</span>
                        <span class="info-box-number">{{ number_format($totals->qty_total) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="fa fa-music"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Distinct titles</span>
                        <span class="info-box-number">{{ number_format($totals->distinct_products) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-purple">
                    <span class="info-box-icon"><i class="fa fa-user"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Cashiers</span>
                        <span class="info-box-number">{{ number_format($totals->distinct_cashiers) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Recent quick-receives (latest 500)</h3>
            </div>
            <div class="box-body table-responsive">
                @if($rows->isEmpty())
                    <p class="text-muted" style="margin:0;">No quick-receives in this window. Try widening the date range or removing the filters.</p>
                @else
                    <table class="table table-condensed table-striped">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Cashier</th>
                                <th>Store</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-right">Qty</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $r)
                            @php
                                $cashier = trim(($r->cashier_first ?? '') . ' ' . ($r->cashier_last ?? ''));
                                if ($cashier === '') { $cashier = $r->cashier_username ?? '—'; }
                                $productLabel = trim(($r->artist ? $r->artist . ' — ' : '') . ($r->product_name ?? ''));
                                if ($productLabel === '— ' || $productLabel === '') { $productLabel = '(unnamed)'; }
                            @endphp
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($r->created_at)->format('M j, g:i a') }}</td>
                                <td>{{ $cashier }}</td>
                                <td>{{ $r->location_name ?? '—' }}</td>
                                <td>
                                    @if($r->product_id)
                                        <a href="{{ url('/products/' . $r->product_id . '/edit') }}" target="_blank">{{ $productLabel }}</a>
                                    @else
                                        {{ $productLabel }}
                                    @endif
                                </td>
                                <td style="font-family: monospace; font-size:12px; color:#666;">{{ $r->sub_sku ?? '—' }}</td>
                                <td class="text-right"><strong>{{ rtrim(rtrim(number_format($r->qty, 2), '0'), '.') }}</strong></td>
                                <td style="max-width:280px; font-size:13px; color:#555;">
                                    @if(!empty($r->note))
                                        {{ $r->note }}
                                    @else
                                        <span class="text-muted" style="font-style:italic;">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="box box-info">
            <div class="box-body" style="font-size:13px; color:#555;">
                <i class="fa fa-info-circle"></i>
                <strong>What this is:</strong> every row is one in-the-moment receive a cashier did at the POS for an item the system said was out of stock. Stock at that store was bumped by the listed qty and the line was added to the sale. Use this to spot patterns &mdash; one cashier doing 50/day, one title being received over and over, items that should have been on the floor from a recent purchase batch but weren't.
            </div>
        </div>
    @endif

</section>
@endsection
