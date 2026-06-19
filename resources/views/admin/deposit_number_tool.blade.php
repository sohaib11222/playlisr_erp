@extends('layouts.app')
@section('title', 'Set next deposit number')

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
body.role-picker .ic-wrap { max-width: 760px; padding: 0 16px 60px; }
body.role-picker .ic-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker .ic-card h3 { margin: 0 0 12px; font-size: 18px; font-weight: 700; color: #1F1B16; }
body.role-picker table.ic-table { width: 100%; border-collapse: collapse; }
body.role-picker table.ic-table th, body.role-picker table.ic-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ECE3CF; font-size: 14px; vertical-align: middle; }
body.role-picker table.ic-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; }
body.role-picker .ic-next { font-weight: 800; font-variant-numeric: tabular-nums; }
body.role-picker .ic-input { width: 90px; padding: 8px 10px; border: 1px solid #DFD2B3; border-radius: 8px; font-family: inherit; font-size: 15px; font-weight: 700; background: #fff; }
body.role-picker .ic-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 9px 16px; border: 0; border-radius: 10px; font-family: inherit; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 1px 2px rgba(31,27,22,.08); background: #1F1B16; color: #FAF6EE; }
body.role-picker .ic-btn:hover { background: #000; }
body.role-picker .ic-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
body.role-picker .ic-alert.success { background: #D9F0D3; border-left: 4px solid #2F6B3E; color: #1F4421; }
body.role-picker .ic-alert.error { background: #F8D7DA; border-left: 4px solid #8A3A2E; color: #5A1A14; }
</style>

<section class="content-header">
    <h1>Set next deposit number</h1>
    <p>Sets where each store's safe-drop deposit numbering continues from. Use this when deposits happened before the log existed — e.g. set Hollywood to <strong>3</strong> so the next envelope is #3, not #1. You can only move a counter up, never down.</p>
</section>

<section class="content">
    <div class="ic-wrap">
        @if(session('status') && is_array(session('status')))
            <div class="ic-alert {{ session('status.success') ? 'success' : 'error' }}">
                {{ session('status.msg') }}
            </div>
        @endif

        @if(!$has_table)
            <div class="ic-alert error">
                The <code>cash_deposits</code> table isn't installed yet — install it first at
                <a href="{{ url('/admin/install-cash-deposits-table') }}">/admin/install-cash-deposits-table</a>.
            </div>
        @else
        <div class="ic-card">
            <h3>Stores</h3>
            <table class="ic-table">
                <thead>
                    <tr><th>Store</th><th>Next deposit #</th><th>Set next # to</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr>
                            <td><strong>{{ $r['name'] }}</strong></td>
                            <td class="ic-next">#{{ $r['next'] }}</td>
                            <td colspan="2">
                                <form method="POST" action="{{ url('/admin/deposit-number-tool/run') }}" style="margin:0; display:flex; gap:10px; align-items:center;"
                                      onsubmit="return confirm('Set the next deposit at {{ $r['name'] }} to #' + this.next_number.value + '?');">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="location_id" value="{{ $r['id'] }}">
                                    <input type="number" name="next_number" min="{{ $r['next'] }}" value="{{ $r['next'] }}" class="ic-input">
                                    <button type="submit" class="ic-btn">Set</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</section>
@stop
