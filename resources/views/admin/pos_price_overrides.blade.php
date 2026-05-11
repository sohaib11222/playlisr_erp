@extends('layouts.app')
@section('title', 'POS Price Overrides')

@section('content')
<section class="content-header">
    <h1>
        POS Price Overrides
        <small>every time a cashier rang a different price than the sticker</small>
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
                <p>This page needs one new empty table (<code>pos_price_overrides</code>) and one permission grant
                   (<em>edit price at POS</em>) before it can capture overrides. Click below — it creates only this new
                   table and updates role permissions. <strong>It doesn't touch any existing data.</strong> Safe to re-click.</p>
                <form method="POST" action="{{ url('/admin/pos-overrides/setup') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-warning"><i class="fa fa-cog"></i> Set it up</button>
                </form>
            </div>
        </div>
    @else
        @php $hasReason = \Illuminate\Support\Facades\Schema::hasColumn('pos_price_overrides', 'reason'); @endphp
        @if(!$hasReason)
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">Schema update available</h3>
                </div>
                <div class="box-body">
                    <p>The <code>reason</code> column was added so cashier explanations show up alongside each override.
                       Click below to add the column — it's a no-data ALTER TABLE, doesn't touch existing rows.</p>
                    <form method="POST" action="{{ url('/admin/pos-overrides/setup') }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-warning"><i class="fa fa-cog"></i> Add reason column</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Filters</h3>
            </div>
            <div class="box-body">
                <form method="GET" action="{{ url('/admin/pos-overrides') }}" class="form-inline">
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
                        <label>Direction</label>
                        <select name="direction" class="form-control input-sm">
                            <option value="" {{ $filters['direction']===''?'selected':'' }}>All</option>
                            <option value="down" {{ $filters['direction']==='down'?'selected':'' }}>Sold for LESS (charged below sticker)</option>
                            <option value="up" {{ $filters['direction']==='up'?'selected':'' }}>Sold for MORE (charged above sticker)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-filter"></i> Apply</button>
                    <a href="{{ url('/admin/pos-overrides') }}" class="btn btn-default btn-sm">Reset</a>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-aqua">
                    <span class="info-box-icon"><i class="fa fa-pencil-square-o"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Overrides</span>
                        <span class="info-box-number">{{ number_format($totals->count) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-red">
                    <span class="info-box-icon"><i class="fa fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Below sticker</span>
                        <span class="info-box-number">{{ number_format($totals->down_count) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-green">
                    <span class="info-box-icon"><i class="fa fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Above sticker</span>
                        <span class="info-box-number">{{ number_format($totals->up_count) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xs-6 col-md-3">
                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="fa fa-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net $ off sticker</span>
                        <span class="info-box-number">{{ ($totals->net >= 0 ? '+' : '') . '$' . number_format($totals->net, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Recent overrides (latest 500)</h3>
            </div>
            <div class="box-body table-responsive">
                @if($rows->isEmpty())
                    <p class="text-muted" style="margin:0;">No overrides in this window. Try widening the date range.</p>
                @else
                    <table class="table table-condensed table-striped">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Cashier</th>
                                <th>Invoice</th>
                                <th>Product</th>
                                <th class="text-right">Sticker</th>
                                <th class="text-right">Charged</th>
                                <th class="text-right">Diff</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $r)
                            @php
                                $cashier = trim(($r->cashier_first ?? '') . ' ' . ($r->cashier_last ?? ''));
                                if ($cashier === '') { $cashier = $r->cashier_username ?? '—'; }
                                $productLabel = trim(($r->artist ? $r->artist . ' — ' : '') . ($r->product_name ?? ''));
                                if ($productLabel === '— ' || $productLabel === '') { $productLabel = '(unnamed)'; }
                                $diff = (float) $r->diff;
                                $diffClass = $diff < 0 ? 'text-red' : 'text-green';
                                $diffSign = $diff > 0 ? '+' : '';
                            @endphp
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($r->created_at)->format('M j, g:i a') }}</td>
                                <td>{{ $cashier }}</td>
                                <td>
                                    @if($r->invoice_no)
                                        <a href="{{ url('/sells/' . $r->transaction_id) }}" target="_blank">{{ $r->invoice_no }}</a>
                                    @else
                                        <a href="{{ url('/sells/' . $r->transaction_id) }}" target="_blank">#{{ $r->transaction_id }}</a>
                                    @endif
                                </td>
                                <td>{{ $productLabel }}</td>
                                <td class="text-right">
                                    ${{ number_format($r->system_price, 2) }}
                                    <form method="POST" action="{{ url('/admin/pos-overrides/fix-sticker') }}"
                                          style="display:inline-block; margin-left:6px;"
                                          onsubmit="var v = prompt('Correct sticker price for this row?\n(charged: ${{ number_format($r->sold_price, 2) }})', '{{ number_format($r->system_price, 2) }}'); if (v === null) return false; this.elements.sticker.value = v; return true;">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $r->id }}">
                                        <input type="hidden" name="sticker" value="">
                                        <button type="submit" class="btn btn-xs btn-link" style="padding:0; font-size:11px; color:#aaa;" title="Fix the sticker price on this audit row">
                                            <i class="fa fa-pencil"></i>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-right"><strong>${{ number_format($r->sold_price, 2) }}</strong></td>
                                <td class="text-right {{ $diffClass }}"><strong>{{ $diffSign }}${{ number_format($diff, 2) }}</strong></td>
                                <td style="max-width:280px; font-size:13px; color:#555;">
                                    @if(!empty($r->reason))
                                        {{ $r->reason }}
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

        @if(isset($recentLines) && $recentLines->count())
        <div class="box box-info collapsed-box">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-stethoscope"></i> Recent sales — override eligibility
                    <small style="margin-left:8px;">Last 48h. Use this to see why a specific sale did/didn't produce an override row.</small>
                </h3>
                <div class="box-tools pull-right">
                    <button type="button" class="btn btn-box-tool" data-widget="collapse">
                        <i class="fa fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="box-body" style="display:none;">
                <p style="color:#666; font-size:13px;">
                    Each row is one sell line from the last 48 hours. The <strong>Verdict</strong> column
                    says whether an override row was (or should have been) logged at <code>/admin/pos-overrides</code>.
                </p>
                <table class="table table-striped table-condensed" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Invoice</th>
                            <th>Cashier</th>
                            <th>Product</th>
                            <th class="text-right">Sticker</th>
                            <th class="text-right">Sold</th>
                            <th class="text-right">Diff</th>
                            <th>Verdict</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentLines as $rl)
                            @php
                                $diffCls = abs($rl->diff) < 0.01 ? '' : ($rl->diff < 0 ? 'text-danger' : 'text-success');
                                $verdictCls = str_contains($rl->verdict, 'logged ✓') ? 'text-success'
                                    : (str_contains($rl->verdict, 'NOT logged') ? 'text-danger' : 'text-muted');
                            @endphp
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($rl->tx_at)->format('M j, g:i A') }}</td>
                                <td>
                                    @if($rl->invoice_no)
                                        <a href="{{ url('/sells/' . $rl->tx_id) }}" target="_blank">{{ $rl->invoice_no }}</a>
                                    @else
                                        <span class="text-muted">#{{ $rl->tx_id }}</span>
                                    @endif
                                </td>
                                <td>{{ trim(($rl->cashier_first ?? '') . ' ' . ($rl->cashier_last ?? '')) ?: '—' }}</td>
                                <td>{{ $rl->product_name ?? '(manual)' }}</td>
                                <td class="text-right">${{ number_format((float) $rl->sticker_inc, 2) }}</td>
                                <td class="text-right">${{ number_format((float) $rl->sold_inc, 2) }}</td>
                                <td class="text-right {{ $diffCls }}">
                                    <strong>{{ $rl->diff > 0 ? '+' : '' }}${{ number_format($rl->diff, 2) }}</strong>
                                </td>
                                <td class="{{ $verdictCls }}" style="font-size:12px;">{{ $rl->verdict }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    @endif

</section>
@endsection
