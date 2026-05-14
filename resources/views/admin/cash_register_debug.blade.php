@extends('layouts.app')
@section('title', 'Cash Register Debug')

@section('content')
<section class="content-header">
    <h1>Cash Register Debug</h1>
    <p class="text-muted">
        Cross-references each <code>cash_registers</code> row against the
        <code>activity_log</code> entry from the duty picker so you can see
        whether the recorded opening matches what the cashier typed.
        Read-only.
    </p>
    <form method="GET" action="{{ url('/admin/cash-register-debug') }}" style="margin-top:8px;">
        <label style="font-size:12px; color:#666;">Hours back:</label>
        <input type="number" name="hours" value="{{ $hours }}" min="1" max="720" style="width:80px; padding:4px 8px;">
        <input type="text" name="user" value="{{ $highlight }}" placeholder="Highlight (name or username)…" style="padding:4px 8px; width:240px; margin-left:8px;">
        <button type="submit" class="btn btn-default btn-sm">Refresh</button>
    </form>
</section>

<section class="content">
<div class="box box-solid">
    <div class="box-body">
        <p style="font-size:12px; color:#666;">
            <strong>Counted</strong> = initial credit + safe drop (reconstructed value typed in "Cash in hand").
            <strong>Typed at duty</strong> = the opening_cash field on the duty picker (logged to activity_log).
            <strong>Δ</strong> = counted − typed. A non-zero Δ means the cashier changed
            the prefilled value on the /cash-register/create page (or the
            page was reached without going through the duty picker).
            @if(!$has_safedrop)
                <br><span style="color:#b91c1c;">Note: <code>safe_drop_amount</code> column missing — install via /admin/install-safe-drop-column.</span>
            @endif
        </p>

        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>Reg id</th>
                    <th>Cashier</th>
                    <th>Store</th>
                    <th>Opened</th>
                    <th>Status</th>
                    <th style="text-align:right;">Initial (saved opening)</th>
                    <th style="text-align:right;">Safe drop (open)</th>
                    <th style="text-align:right;">Counted (reconstructed)</th>
                    <th style="text-align:right;">Typed at duty</th>
                    <th style="text-align:right;">Δ</th>
                    <th>Closed</th>
                    <th style="text-align:right;">Closing</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr style="{{ $r->highlight ? 'background:#fff8d6;' : ($r->suspicious ? 'background:#fde8e8;' : '') }}">
                        <td><code>{{ $r->id }}</code></td>
                        <td>
                            <strong>{{ $r->name }}</strong>
                            @if($r->username)
                                <br><small class="text-muted">{{ $r->username }}</small>
                            @endif
                        </td>
                        <td>{{ $r->location_name ?: '—' }}</td>
                        <td><small>{{ $r->created_at }}</small></td>
                        <td>
                            <span class="label label-{{ $r->status === 'open' ? 'success' : 'default' }}">{{ $r->status }}</span>
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            ${{ number_format((float) $r->initial_amount, 2) }}
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            @if($r->safe_drop !== null && $r->safe_drop > 0)
                                ${{ number_format((float) $r->safe_drop, 2) }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums; font-weight:600;">
                            @if($r->counted !== null)
                                ${{ number_format((float) $r->counted, 2) }}
                            @else
                                <span class="text-muted">n/a</span>
                            @endif
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            @if($r->typed_opening !== null)
                                ${{ number_format((float) $r->typed_opening, 2) }}
                                @if($r->duty_log_at)
                                    <br><small class="text-muted">{{ $r->duty_log_at }}</small>
                                @endif
                            @else
                                <span class="text-muted">no duty log</span>
                            @endif
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            @if($r->delta !== null && abs($r->delta) > 0.01)
                                <span style="color:{{ abs($r->delta) > 1 ? '#b91c1c' : '#888' }}; font-weight:{{ $r->suspicious ? '700' : '400' }};">
                                    {{ $r->delta >= 0 ? '+' : '' }}${{ number_format($r->delta, 2) }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><small>{{ $r->closed_at ?: '—' }}</small></td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            @if($r->closing_amount !== null)
                                ${{ number_format((float) $r->closing_amount, 2) }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="text-center text-muted">No registers opened in this window.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</section>
@endsection
