@extends('layouts.app')
@section('title', 'Install cashier columns')

@section('content')
{{-- Same brand aesthetic as the choose-role page (POS-create-derived). --}}
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
body.role-picker table.ic-table th, body.role-picker table.ic-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ECE3CF; font-size: 14px; }
body.role-picker table.ic-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; }
body.role-picker table.ic-table code { background: #F7F1E3; padding: 2px 6px; border-radius: 4px; color: #1F1B16; font-size: 13px; }
body.role-picker .ic-pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
body.role-picker .ic-pill.ok { background: #D9F0D3; color: #2F6B3E; }
body.role-picker .ic-pill.missing { background: #FFF2B3; color: #5A4410; }
body.role-picker .ic-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 220px; min-height: 48px; padding: 12px 20px; border: 0; border-radius: 10px; font-family: inherit; font-weight: 700; font-size: 15px; cursor: pointer; transition: transform .06s ease, box-shadow .12s ease, background .12s ease; box-shadow: 0 1px 2px rgba(31,27,22,.08); background: #1F1B16; color: #FAF6EE; }
body.role-picker .ic-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(31,27,22,.12); background: #000; }
body.role-picker .ic-done { color: #2F6B3E; font-weight: 600; }
body.role-picker .ic-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
body.role-picker .ic-alert.success { background: #D9F0D3; border-left: 4px solid #2F6B3E; color: #1F4421; }
body.role-picker .ic-alert.error { background: #F8D7DA; border-left: 4px solid #8A3A2E; color: #5A1A14; }
</style>

<section class="content-header">
    <h1>Install cashier columns</h1>
    <p>One-shot installer for the choose-role feature. Adds two nullable columns to <code>business_locations</code> so the POS can attribute sales to the active cashier instead of the logged-in user. Safe to run more than once — already-installed columns are skipped.</p>
</section>

<section class="content">
    <div class="ic-wrap">
        @if(session('status') && is_array(session('status')))
            <div class="ic-alert {{ session('status.success') ? 'success' : 'error' }}">
                {{ session('status.msg') }}
            </div>
        @endif

        <div class="ic-card">
            <h3>Current state</h3>
            <table class="ic-table">
                <thead>
                    <tr><th>Column</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>business_locations.current_cashier_id</code></td>
                        <td>
                            @if($has_current_cashier_id)
                                <span class="ic-pill ok">already installed</span>
                            @else
                                <span class="ic-pill missing">missing — will be added</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td><code>business_locations.cashier_assigned_at</code></td>
                        <td>
                            @if($has_cashier_assigned_at)
                                <span class="ic-pill ok">already installed</span>
                            @else
                                <span class="ic-pill missing">missing — will be added</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top:18px;">
                @if($has_current_cashier_id && $has_cashier_assigned_at)
                    <p class="ic-done">✓ Both columns exist — nothing more to do. The /choose-role page is fully active.</p>
                @else
                    <form method="POST" action="{{ url('/admin/install-cashier-columns/run') }}" style="margin:0;">
                        {{ csrf_field() }}
                        <button type="submit" class="ic-btn"
                                onclick="return confirm('Add these two nullable columns to business_locations? This is reversible — the columns can be dropped later.');">
                            Install cashier columns
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</section>
@stop
