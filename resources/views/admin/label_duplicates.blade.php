@extends('layouts.app')
@section('title', 'Label Print Duplicates')

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
body.role-picker .content-header p { color: #5A5045; margin: 0; font-size: 14px; max-width: 820px; }
body.role-picker .ld-wrap { max-width: 1040px; padding: 0 16px 60px; }
body.role-picker .ld-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker .ld-card h3 { margin: 0 0 12px; font-size: 18px; font-weight: 700; color: #1F1B16; }
body.role-picker .ld-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
body.role-picker .ld-field { display:flex; flex-direction:column; gap:5px; }
body.role-picker .ld-field label { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#8E8273; }
body.role-picker .ld-field input { padding:11px 14px; border:1px solid #D7CDB6; border-radius:8px; font-size:15px; background:#FFFCF5; font-family:inherit; }
body.role-picker .ld-btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 20px; border:0; border-radius:8px; font-family:inherit; font-weight:700; font-size:14px; cursor:pointer; background:#1F1B16; color:#FAF6EE; }
body.role-picker .ld-btn.danger { background:#8A3A2E; }
body.role-picker .ld-btn:hover { background:#000; }
body.role-picker .ld-btn.danger:hover { background:#5A1A14; }
body.role-picker table.ld-table { width: 100%; border-collapse: collapse; }
body.role-picker table.ld-table th, body.role-picker table.ld-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid #ECE3CF; font-size: 14px; vertical-align: middle; }
body.role-picker table.ld-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; }
body.role-picker .ld-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
body.role-picker .ld-alert.success { background: #D9F0D3; border-left: 4px solid #2F6B3E; color: #1F4421; }
body.role-picker .ld-alert.error { background: #F8D7DA; border-left: 4px solid #8A3A2E; color: #5A1A14; }
body.role-picker .ld-muted { color:#5A5045; font-size:13px; }
body.role-picker .ld-flag { color:#8A3A2E; font-weight:700; }
body.role-picker .ld-clean { color:#2F6B3E; font-weight:700; }
body.role-picker .ld-emp { font-weight:700; font-size:15px; margin:18px 0 8px; }
</style>

<section class="content-header">
    <h1>Label Print Duplicates</h1>
    <p>Scans every employee's label print runs (<code>activity_log → labels_printed</code>) and flags suspected duplicates — a run matching the same employee's immediately previous run by item count <em>and</em> value within the time window. Duplicates inflate the "labeled" totals the productivity report credits, which affects commission. Review, then remove (snapshotted + undoable at <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>).</p>
</section>

<section class="content">
    <div class="ld-wrap">
        @if(session('status') && is_array(session('status')))
            <div class="ld-alert {{ session('status.success') ? 'success' : 'error' }}">
                {{ session('status.msg') }}
            </div>
        @endif

        <div class="ld-card">
            <form method="GET" action="{{ url('/admin/label-duplicates') }}">
                <div class="ld-row">
                    <div class="ld-field">
                        <label>From</label>
                        <input type="date" name="from" value="{{ $from }}">
                    </div>
                    <div class="ld-field">
                        <label>To</label>
                        <input type="date" name="to" value="{{ $to }}">
                    </div>
                    <div class="ld-field">
                        <label>Window (seconds)</label>
                        <input type="number" name="window" min="1" value="{{ $window }}" style="width:130px;">
                    </div>
                    <button type="submit" class="ld-btn">Scan</button>
                </div>
                <p class="ld-muted" style="margin:12px 0 0;">A run is flagged when it repeats the previous run's count + value within this many seconds. Widen to catch slower re-prints; narrow to only catch instant double-fires.</p>
            </form>
        </div>

        <div class="ld-card">
            <h3>Summary ({{ $from }} → {{ $to }})</h3>
            @if($totalDupRuns === 0)
                <p class="ld-clean">No duplicate label runs found for any employee in this range. ✓</p>
            @else
                <p class="ld-flag" style="margin:0 0 6px;">{{ $totalDupRuns }} suspected duplicate run(s) across all staff — {{ $totalDupItems }} items, ${{ number_format($totalDupValue, 2) }} of inflated labeled value.</p>
            @endif
            <table class="ld-table">
                <thead>
                    <tr><th>Employee</th><th>Runs</th><th>Items</th><th>Value</th><th>Dup runs</th><th>Dup items</th><th>Dup value</th></tr>
                </thead>
                <tbody>
                    @foreach($employees as $e)
                        <tr>
                            <td>{{ $e->name }}</td>
                            <td>{{ $e->runs }}</td>
                            <td>{{ $e->items }}</td>
                            <td>${{ number_format($e->value, 2) }}</td>
                            <td class="{{ $e->dup_runs > 0 ? 'ld-flag' : 'ld-muted' }}">{{ $e->dup_runs }}</td>
                            <td class="{{ $e->dup_runs > 0 ? 'ld-flag' : 'ld-muted' }}">{{ $e->dup_items }}</td>
                            <td class="{{ $e->dup_runs > 0 ? 'ld-flag' : 'ld-muted' }}">${{ number_format($e->dup_value, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($totalDupRuns > 0)
            <form method="POST" action="{{ url('/admin/label-duplicates/remove') }}">
                @csrf
                <div class="ld-card">
                    <h3>Suspected duplicates — review before removing</h3>
                    <p class="ld-muted" style="margin:-4px 0 14px;">Each flagged row is the <em>second</em> copy of an identical back-to-back run. Unchecking leaves it in place. Removing deletes only the checked duplicate rows (the originals are never touched) and is undoable.</p>

                    @foreach($employees as $e)
                        @if($e->dup_runs > 0)
                            <div class="ld-emp">{{ $e->name }} — {{ $e->dup_runs }} dup(s), {{ $e->dup_items }} items, ${{ number_format($e->dup_value, 2) }}</div>
                            <table class="ld-table">
                                <thead>
                                    <tr><th style="width:36px;"><input type="checkbox" checked onclick="this.closest('table').querySelectorAll('.dup-cb').forEach(c=>c.checked=this.checked)"></th><th>Duplicate at</th><th>Matches run at</th><th>Items</th><th>Value</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($e->dups as $d)
                                        <tr>
                                            <td><input type="checkbox" class="dup-cb" name="dup_ids[]" value="{{ $d->id }}" checked></td>
                                            <td>{{ \Carbon::parse($d->time)->format('M j, g:i:s A') }}</td>
                                            <td class="ld-muted">{{ \Carbon::parse($d->prev_time)->format('g:i:s A') }}</td>
                                            <td>{{ $d->qty }}</td>
                                            <td>${{ number_format($d->value, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    @endforeach
                </div>
                <div class="ld-card">
                    <button type="submit" class="ld-btn danger" onclick="return confirm('Remove the checked duplicate label runs? Snapshotted and undoable.')">Remove checked duplicates</button>
                </div>
            </form>
        @endif
    </div>
</section>
@endsection
