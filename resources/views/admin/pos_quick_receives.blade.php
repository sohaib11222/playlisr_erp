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
        @php $hasUndoCol = \Illuminate\Support\Facades\Schema::hasColumn('pos_quick_receives', 'undone_at'); @endphp
        @if(!$hasUndoCol)
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">Schema update available</h3>
                </div>
                <div class="box-body">
                    <p>The <code>undone_at</code> column was added so you can undo a quick-receive directly from this page
                       (decrements stock back + marks the row as undone). Click below to add the columns — it's a no-data ALTER TABLE.</p>
                    <form method="POST" action="{{ url('/admin/pos-quick-receives/setup') }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-warning"><i class="fa fa-cog"></i> Add undo columns</button>
                    </form>
                </div>
            </div>
        @endif

        @if(!empty($pendingRepairCount) && $pendingRepairCount > 0)
            <div class="box box-danger">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ $pendingRepairCount }} receive(s) need repair to be sellable</h3>
                </div>
                <div class="box-body">
                    <p>These were received before the purchase-trail fix shipped. Stock is on the shelf, but the POS rejects
                       them at checkout with <em>"Mismatch between sold and purchase quantity."</em> Click below to create
                       the missing purchase trail (cost defaults to the variation's purchase price). Safe to re-click.</p>
                    <form method="POST" action="{{ url('/admin/pos-quick-receives/setup') }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-danger"><i class="fa fa-wrench"></i> Repair {{ $pendingRepairCount }} receive(s) now</button>
                    </form>
                </div>
            </div>
        @endif

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

        @if(isset($totals->undone_count) && $totals->undone_count > 0)
            <div class="alert alert-info" style="margin-bottom:14px;">
                <i class="fa fa-undo"></i>
                {{ number_format($totals->undone_count) }} receive(s) in this window were undone &mdash; stock at the matching store(s) was decremented back.
            </div>
        @endif

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
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $r)
                            @php
                                $cashier = trim(($r->cashier_first ?? '') . ' ' . ($r->cashier_last ?? ''));
                                if ($cashier === '') { $cashier = $r->cashier_username ?? '—'; }
                                $productLabel = trim(($r->artist ? $r->artist . ' — ' : '') . ($r->product_name ?? ''));
                                if ($productLabel === '— ' || $productLabel === '') { $productLabel = '(unnamed)'; }
                                $isUndone = !empty($r->undone_at);
                                $rowStyle = $isUndone ? 'opacity:0.55; text-decoration:line-through;' : '';
                                $qtyDisplay = rtrim(rtrim(number_format($r->qty, 2), '0'), '.');
                            @endphp
                            <tr style="{{ $rowStyle }}">
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
                                <td class="text-right"><strong>{{ $qtyDisplay }}</strong></td>
                                <td style="max-width:240px; font-size:13px; color:#555;">
                                    @if(!empty($r->note))
                                        {{ $r->note }}
                                    @else
                                        <span class="text-muted" style="font-style:italic;">—</span>
                                    @endif
                                </td>
                                <td style="white-space:nowrap;">
                                    @if($isUndone)
                                        <span class="text-muted" style="font-size:12px;">
                                            <i class="fa fa-undo"></i> Undone
                                            <br><span style="font-size:11px;">{{ \Carbon\Carbon::parse($r->undone_at)->format('M j, g:i a') }}</span>
                                        </span>
                                    @elseif($hasUndoCol)
                                        <form method="POST" action="{{ url('/admin/pos-quick-receives/undo') }}"
                                              style="display:inline;"
                                              onsubmit="return confirm('Undo this quick-receive?\n\nThis will DECREASE stock for &quot;{{ addslashes($productLabel) }}&quot; at {{ addslashes($r->location_name ?? 'this store') }} by {{ $qtyDisplay }}.\n\nUse this only if the unit was never actually sold — e.g. cashier clicked Receive by mistake and deleted the line, or you confirmed nothing of this title exists at the store.');">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $r->id }}">
                                            <button type="submit" class="btn btn-xs btn-warning" title="Decrease stock back to where it was before this quick-receive">
                                                <i class="fa fa-undo"></i> Undo
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-muted" style="font-size:11px;">—</span>
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
