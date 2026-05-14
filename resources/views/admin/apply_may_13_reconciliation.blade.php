@extends('layouts.app')
@section('title', 'Apply 2026-05-13 Reconciliation')

@section('content')
<section class="content-header">
    <h1>Apply 2026-05-13 Reconciliation</h1>
    <p class="text-muted" style="max-width:900px;">
        One-shot action for the register-reconciliation findings from May 13, 2026.
        Snapshots BEFORE state to <a href="{{ url('/admin/admin-action-history') }}">admin-action-history</a>
        so the whole batch can be undone in one click.
    </p>
</section>

<section class="content">

@if (session('status'))
<div class="alert alert-warning">{{ session('status') }}</div>
@endif

@if ($mode === 'commit')
<div class="box box-solid" style="border:3px solid #00a65a;">
    <div class="box-header" style="background:#dff0d8;">
        <h3 class="box-title" style="font-size:20px;">✓ Applied — snapshot <code>{{ $snapshot_key }}</code></h3>
    </div>
    <div class="box-body">
        <ul>
            @foreach ($applied['payment_overrides'] as $po)
                <li>Flipped payment method to <strong>CASH</strong> on #{{ $po['invoice_no'] }} ({{ $po['rows_updated'] }} payment row{{ $po['rows_updated'] === 1 ? '' : 's' }}).</li>
            @endforeach
            @foreach ($applied['matches'] as $m)
                <li>Manually matched Clover <code>{{ $m['cp_payment_id'] }}</code> ↔ ERP #{{ $m['invoice_no'] }}.</li>
            @endforeach
            @if(!empty($applied['staff_notes']))
                <li>Wrote inline context note (staff_note) on {{ count($applied['staff_notes']) }} sale{{ count($applied['staff_notes']) === 1 ? '' : 's' }}.</li>
            @endif
            @foreach ($applied['rings_created'] ?? [] as $r)
                <li>
                    @if($r['tx_id'])
                        Created ERP ring #{{ $r['invoice_no'] }} ({{ $r['short'] }}).
                    @else
                        <span class="text-danger">{{ $r['short'] }}</span>
                    @endif
                </li>
            @endforeach
            @foreach ($applied['notes'] as $n)
                <li>Saved register-reconciliation note: <strong>{{ $n['short'] }}</strong>.</li>
            @endforeach
        </ul>
        <p style="margin-top:14px;">
            Undo any of this at <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
        </p>
    </div>
</div>
@endif

<div class="box">
    <div class="box-header with-border">
        <h3 class="box-title">Plan</h3>
    </div>
    <div class="box-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Reason</th>
                    <th>Resolved?</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>Flip payment method <strong>CARD → CASH</strong></td>
                    <td>
                        @if($plan['p1_payment_override']['tx_id'])
                            ERP #{{ $plan['p1_payment_override']['invoice_no'] }} (id {{ $plan['p1_payment_override']['tx_id'] }})
                            @if($plan['p1_payment_override']['amount']) · ${{ number_format($plan['p1_payment_override']['amount'], 2) }} @endif
                        @else
                            <span class="text-danger">#18694 not found</span>
                        @endif
                    </td>
                    <td>{{ $plan['p1_payment_override']['reason'] }}</td>
                    <td>{!! $plan['p1_payment_override']['tx_id'] ? '<span class="text-success">✓ ready</span>' : '<span class="text-danger">✗ skip</span>' !!}</td>
                </tr>
                <tr>
                    <td>2</td>
                    <td>Manual match Clover ↔ ERP (Interpol)</td>
                    <td>
                        Clover <code>{{ $plan['p2_manual_match']['cp_payment_id'] }}</code> ↔
                        @if($plan['p2_manual_match']['tx_id'])
                            ERP #{{ $plan['p2_manual_match']['invoice_no'] }} (id {{ $plan['p2_manual_match']['tx_id'] }})
                        @else
                            <span class="text-danger">#18696 not found</span>
                        @endif
                        @if($plan['p2_manual_match']['amount']) · ${{ number_format($plan['p2_manual_match']['amount'], 2) }} @endif
                    </td>
                    <td>{{ $plan['p2_manual_match']['reason'] }}</td>
                    <td>{!! $plan['p2_manual_match']['cp_db_id'] && $plan['p2_manual_match']['tx_id'] ? '<span class="text-success">✓ ready</span>' : '<span class="text-danger">✗ skip</span>' !!}</td>
                </tr>
                <tr>
                    <td>3</td>
                    <td>Manual match Clover ↔ ERP (Daft Punk exchange)</td>
                    <td>
                        Clover <code>{{ $plan['p5_exchange_match']['cp_payment_id'] }}</code> ↔
                        @if($plan['p5_exchange_match']['tx_id'])
                            ERP #{{ $plan['p5_exchange_match']['invoice_no'] }} (id {{ $plan['p5_exchange_match']['tx_id'] }})
                        @else
                            <span class="text-danger">#18680 not found</span>
                        @endif
                        @if($plan['p5_exchange_match']['amount']) · ${{ number_format($plan['p5_exchange_match']['amount'], 2) }} @endif
                    </td>
                    <td>{{ $plan['p5_exchange_match']['reason'] }}</td>
                    <td>{!! $plan['p5_exchange_match']['cp_db_id'] && $plan['p5_exchange_match']['tx_id'] ? '<span class="text-success">✓ ready</span>' : '<span class="text-danger">✗ skip</span>' !!}</td>
                </tr>
                <tr>
                    <td>4</td>
                    <td>Create missing ERP ring (Bonnie Raitt)</td>
                    <td>
                        New sale @ {{ $plan['p4_bonnie_raitt']['location_name'] }} · cashier <strong>{{ $plan['p4_bonnie_raitt']['user_name'] }}</strong> ·
                        ${{ number_format($plan['p4_bonnie_raitt']['amount'], 2) }} inc tax · manual line "<em>{{ $plan['p4_bonnie_raitt']['product_name'] }}</em>" ·
                        backdated to {{ \Carbon\Carbon::parse($plan['p4_bonnie_raitt']['transaction_date'])->format('M j g:i a') }} · pair w/ Clover <code>{{ $plan['p4_bonnie_raitt']['cp_payment_id'] }}</code>
                    </td>
                    <td>{{ $plan['p4_bonnie_raitt']['reason'] }}</td>
                    <td>
                        @if($plan['p4_bonnie_raitt']['already_exists'])
                            <span class="text-success">✓ done previously</span>
                        @elseif($plan['p4_bonnie_raitt']['cp_db_id'] && $plan['p4_bonnie_raitt']['location_id'] && $plan['p4_bonnie_raitt']['user_id'] && $plan['p4_bonnie_raitt']['contact_id'])
                            <span class="text-success">✓ ready</span>
                        @else
                            <span class="text-danger">✗ missing lookup
                                @if(empty($plan['p4_bonnie_raitt']['cp_db_id'])) · no Clover row @endif
                                @if(empty($plan['p4_bonnie_raitt']['location_id'])) · no Pico location @endif
                                @if(empty($plan['p4_bonnie_raitt']['user_id'])) · no Clark user @endif
                                @if(empty($plan['p4_bonnie_raitt']['contact_id'])) · no Walk-In contact @endif
                            </span>
                        @endif
                    </td>
                </tr>
                @foreach ($plan['p3_notes'] as $i => $note)
                    <tr>
                        <td>{{ 5 + $i }}</td>
                        <td>Save register-reconciliation note</td>
                        <td>
                            @if(!empty($note['invoice_no']))
                                ERP #{{ $note['invoice_no'] }}
                            @endif
                            @if(!empty($note['cp_db_id']))
                                @if(!empty($note['invoice_no'])) + @endif
                                Clover row {{ $note['cp_db_id'] }}
                            @endif
                            — <em>{{ $note['short'] }}</em>
                        </td>
                        <td><span style="white-space:pre-wrap;">{{ $note['reason'] }}</span></td>
                        <td><span class="text-success">✓ ready</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($mode === 'preview')
            <form method="POST" action="{{ url('/admin/apply-may-13-reconciliation/run') }}" onsubmit="return confirm('Apply all the resolved actions above? A snapshot will be saved so the batch can be undone.');" style="margin-top:14px;">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg">Apply all (with snapshot)</button>
                <a href="{{ url('/admin/admin-action-history') }}" class="btn btn-default">View admin history</a>
            </form>
        @else
            <a href="{{ url('/admin/apply-may-13-reconciliation') }}" class="btn btn-default" style="margin-top:14px;">Re-run preview</a>
        @endif
    </div>
</div>

</section>
@endsection
