@extends('layouts.app')
@section('title', 'Reassign User Activity')

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
body.role-picker .rua-wrap { max-width: 1040px; padding: 0 16px 60px; }
body.role-picker .rua-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker .rua-card h3 { margin: 0 0 12px; font-size: 18px; font-weight: 700; color: #1F1B16; }
body.role-picker .rua-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
body.role-picker .rua-field { display:flex; flex-direction:column; gap:5px; }
body.role-picker .rua-field label { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#8E8273; }
body.role-picker .rua-field select, body.role-picker .rua-field input { padding:11px 14px; border:1px solid #D7CDB6; border-radius:8px; font-size:15px; background:#FFFCF5; font-family:inherit; min-width:200px; }
body.role-picker .rua-btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 20px; border:0; border-radius:8px; font-family:inherit; font-weight:700; font-size:14px; cursor:pointer; background:#1F1B16; color:#FAF6EE; }
body.role-picker .rua-btn.apply { background:#2F6B3E; }
body.role-picker .rua-btn:hover { background:#000; }
body.role-picker .rua-btn.apply:hover { background:#1F4421; }
body.role-picker table.rua-table { width: 100%; border-collapse: collapse; }
body.role-picker table.rua-table th, body.role-picker table.rua-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid #ECE3CF; font-size: 14px; vertical-align: middle; }
body.role-picker table.rua-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; }
body.role-picker table.rua-table code { background: #F7F1E3; padding: 2px 6px; border-radius: 4px; color: #1F1B16; font-size: 13px; }
body.role-picker .rua-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
body.role-picker .rua-alert.success { background: #D9F0D3; border-left: 4px solid #2F6B3E; color: #1F4421; }
body.role-picker .rua-alert.error { background: #F8D7DA; border-left: 4px solid #8A3A2E; color: #5A1A14; }
body.role-picker .rua-muted { color:#5A5045; font-size:13px; }
body.role-picker .rua-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; background:#EFE7D3; color:#5A5045; }
</style>

<section class="content-header">
    <h1>Reassign User Activity</h1>
    <p>Moves a day's sales (<code>transactions.created_by</code>) and listings (<code>products.created_by</code>) from one user to another — for when someone rang up or listed under the wrong login. Preview each row, uncheck any that genuinely belong to the from-user, then Apply. Snapshotted for one-click undo at <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.</p>
</section>

<section class="content">
    <div class="rua-wrap">
        @if(session('status') && is_array(session('status')))
            <div class="rua-alert {{ session('status.success') ? 'success' : 'error' }}">
                {{ session('status.msg') }}
            </div>
        @endif

        <div class="rua-card">
            <h3>Pick users and day</h3>
            <form method="GET" action="{{ url('/admin/reassign-user-activity') }}">
                <div class="rua-row">
                    <div class="rua-field">
                        <label>From (wrong account)</label>
                        <select name="from_user_id" required>
                            <option value="">— select —</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ $fromUserId == $u->id ? 'selected' : '' }}>{{ trim($u->first_name.' '.$u->last_name) ?: $u->username }} ({{ $u->username }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rua-field">
                        <label>To (correct person)</label>
                        <select name="to_user_id" required>
                            <option value="">— select —</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ $toUserId == $u->id ? 'selected' : '' }}>{{ trim($u->first_name.' '.$u->last_name) ?: $u->username }} ({{ $u->username }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rua-field">
                        <label>Day</label>
                        <input type="date" name="date" value="{{ $date }}">
                    </div>
                    <div class="rua-field">
                        <label>Store (optional)</label>
                        <select name="location_id">
                            <option value="">— all stores —</option>
                            @foreach($business_locations as $loc)
                                <option value="{{ $loc->id }}" {{ (int)($locationId ?? 0) === (int)$loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rua-field">
                        <label>After time (optional)</label>
                        <input type="time" name="after_time" value="{{ $afterTime ?? '' }}">
                    </div>
                    <div class="rua-field">
                        <label>Before time (optional)</label>
                        <input type="time" name="before_time" value="{{ $beforeTime ?? '' }}">
                    </div>
                    <button type="submit" class="rua-btn">Preview</button>
                </div>
                <p class="rua-muted" style="margin:10px 0 0;">Leave time fields blank for the whole day. Example: to move a mid-shift handover, set <strong>After time</strong> to when the next person took over (e.g. 2:30 PM) and pick the store — the preview then shows only those sales.</p>
            </form>
        </div>

        @if($previewed)
            <form method="POST" action="{{ url('/admin/reassign-user-activity/run') }}">
                @csrf
                <input type="hidden" name="from_user_id" value="{{ $fromUserId }}">
                <input type="hidden" name="to_user_id" value="{{ $toUserId }}">
                <input type="hidden" name="date" value="{{ $date }}">

                <div class="rua-card">
                    <h3>Sales on {{ $date }} ({{ $sales->count() }})</h3>
                    @if($sales->isEmpty())
                        <p class="rua-muted">No transactions found for that user on this day.</p>
                    @else
                        <table class="rua-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" checked onclick="document.querySelectorAll('.tx-cb').forEach(c=>c.checked=this.checked)"></th>
                                    <th>Time</th><th>Invoice</th><th>Type</th><th>Status</th><th>Customer</th><th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sales as $s)
                                    <tr>
                                        <td><input type="checkbox" class="tx-cb" name="tx_ids[]" value="{{ $s->id }}" checked></td>
                                        <td class="rua-muted">{{ \Carbon::parse($s->transaction_date)->format('g:i A') }}</td>
                                        <td><code>{{ $s->invoice_no ?: '#'.$s->id }}</code></td>
                                        <td><span class="rua-pill">{{ $s->type }}</span></td>
                                        <td class="rua-muted">{{ $s->status }}</td>
                                        <td>{{ trim($s->contact_name) ?: '—' }}</td>
                                        <td>${{ number_format((float)$s->final_total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                <div class="rua-card">
                    <h3>Listings on {{ $date }} ({{ $listings->count() }}, ${{ number_format((float)$listings->sum('sell_value'), 2) }} value)</h3>
                    @if($listings->isEmpty())
                        <p class="rua-muted">No products listed by that user on this day.</p>
                    @else
                        <table class="rua-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" checked onclick="document.querySelectorAll('.prod-cb').forEach(c=>c.checked=this.checked)"></th>
                                    <th>Time</th><th>SKU</th><th>Product</th><th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($listings as $p)
                                    <tr>
                                        <td><input type="checkbox" class="prod-cb" name="prod_ids[]" value="{{ $p->id }}" checked></td>
                                        <td class="rua-muted">{{ \Carbon::parse($p->created_at)->format('g:i A') }}</td>
                                        <td><code>{{ $p->sku }}</code></td>
                                        <td>{{ $p->name }}</td>
                                        <td>${{ number_format((float)$p->sell_value, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="4" style="text-align:right;">Total</th>
                                    <th>${{ number_format((float)$listings->sum('sell_value'), 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>

                <div class="rua-card">
                    <h3>Labeled on {{ $date }} ({{ $labels->sum('qty') }} items, ${{ number_format((float)$labels->sum('value'), 2) }} value)</h3>
                    @if($labels->isEmpty())
                        <p class="rua-muted">No label runs logged for that user on this day.</p>
                    @else
                        <p class="rua-muted" style="margin:-4px 0 12px;">Each row is a print run logged at the printer. Reassigning moves its credit (item count + value) to the selected person.</p>
                        <table class="rua-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" checked onclick="document.querySelectorAll('.label-cb').forEach(c=>c.checked=this.checked)"></th>
                                    <th>Time</th><th>Items</th><th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($labels as $l)
                                    <tr>
                                        <td><input type="checkbox" class="label-cb" name="label_ids[]" value="{{ $l->id }}" checked></td>
                                        <td class="rua-muted">{{ \Carbon::parse($l->created_at)->format('g:i A') }}</td>
                                        <td>{{ $l->qty }}</td>
                                        <td>${{ number_format((float)$l->value, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th style="text-align:right;" colspan="2">Total</th>
                                    <th>{{ $labels->sum('qty') }}</th>
                                    <th>${{ number_format((float)$labels->sum('value'), 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>

                @if($sales->isNotEmpty() || $listings->isNotEmpty() || $labels->isNotEmpty())
                    <div class="rua-card">
                        <p class="rua-muted" style="margin:0 0 12px;">Checked rows above will be reassigned. This is undoable.</p>
                        <button type="submit" class="rua-btn apply" onclick="return confirm('Reassign the checked sales + listings + labels to the selected user?')">Apply reassignment</button>
                    </div>
                @endif
            </form>
        @endif
    </div>
</section>
@endsection
