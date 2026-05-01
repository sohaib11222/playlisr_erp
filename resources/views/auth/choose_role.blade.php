@extends('layouts.app')
@section('title', "What are you working on?")

@section('content')
{{-- POS-create aesthetic for the role picker: cream surface, Inter Tight,
     yellow accent, soft cards. Scoped under body.role-picker so it doesn't
     bleed to other screens. Mirrors the token system from
     sale_pos.partials._redesign_v2 but inlined here since this page has its
     own simple layout. --}}
<script>document.body.classList.add('role-picker');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.role-picker { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.role-picker .content-wrapper { background: #FAF6EE !important; }
body.role-picker .content-header { background: transparent; padding: 28px 16px 8px; }
body.role-picker .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.role-picker .content-header p { color: #5A5045; margin: 0; font-size: 14px; }
body.role-picker .rp-wrap { max-width: 760px; margin: 0 auto; padding: 0 16px 60px; }
body.role-picker .rp-hello { text-align: center; font-size: 15px; color: #5A5045; margin: 16px 0 22px; }
body.role-picker .rp-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); position: relative; overflow: hidden; }
body.role-picker .rp-card::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: var(--rp-accent, #1F8FE0); }
body.role-picker .rp-card.rp-cashier { --rp-accent: #1F8FE0; }
body.role-picker .rp-card.rp-manager { --rp-accent: #8E5BA8; }
body.role-picker .rp-card.rp-inventory { --rp-accent: #2F6B3E; }
body.role-picker .rp-card.rp-shipping { --rp-accent: #C97A2A; }
body.role-picker .rp-card h3 { margin: 0 0 4px; font-size: 18px; font-weight: 700; color: #1F1B16; }
body.role-picker .rp-card h3 small { font-weight: 500; color: #8E8273; font-size: 13px; margin-left: 6px; }
body.role-picker .rp-card p { margin: 0 0 12px; color: #5A5045; font-size: 14px; }
body.role-picker .rp-card .rp-hint { font-size: 12px; color: #8E8273; margin-bottom: 8px; }
body.role-picker .rp-buttons { display: flex; flex-wrap: wrap; gap: 10px; }
body.role-picker .rp-btn { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; min-width: 200px; min-height: 64px; padding: 12px 18px; border: 0; border-radius: 10px; font-family: inherit; font-weight: 700; font-size: 15px; cursor: pointer; transition: transform .06s ease, box-shadow .12s ease, background .12s ease; box-shadow: 0 1px 2px rgba(31,27,22,.08); }
body.role-picker .rp-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(31,27,22,.12); }
body.role-picker .rp-btn:active { transform: translateY(0); }
body.role-picker .rp-btn-cashier { background: #1F8FE0; color: #fff; }
body.role-picker .rp-btn-cashier:hover { background: #1A7BC4; }
body.role-picker .rp-btn-cashier .rp-btn-sub { font-size: 11px; font-weight: 500; opacity: 0.9; margin-top: 4px; text-transform: none; letter-spacing: 0; }
body.role-picker .rp-btn-soft { background: #F7F1E3; color: #1F1B16; border: 1px solid #ECE3CF; }
body.role-picker .rp-btn-soft:hover { background: #FFF2B3; border-color: #E8CF68; }
body.role-picker .rp-foot { text-align: center; color: #8E8273; font-size: 12px; margin-top: 18px; }
body.role-picker .rp-foot code { background: #FFF9DB; padding: 2px 6px; border-radius: 4px; color: #5A4410; }
body.role-picker .rp-alert { max-width: 760px; margin: 0 auto 16px; padding: 12px 16px; background: #FFF2B3; border-left: 4px solid #E8CF68; border-radius: 8px; color: #5A4410; font-size: 14px; }
</style>

<section class="content-header" style="text-align:center;">
    <h1>What are you working on today?</h1>
    <p>Pick what you are doing right now. Your choice tells the POS who to attribute sales to.</p>
</section>

<section class="content">
@if(session('status') && is_array(session('status')) && empty(session('status.success')))
<div class="rp-alert">{{ session('status.msg') }}</div>
@endif

<div class="rp-wrap">
    @php $hello = optional(auth()->user())->first_name; @endphp
    @if($hello)
        <p class="rp-hello">Hi <strong>{{ $hello }}</strong> — choose your role for this shift:</p>
    @endif

    <div class="rp-card rp-cashier">
        <h3>Cashier <small>— Front Desk, ringing up customers</small></h3>
        <p>All sales rung at this store while you are the cashier will be attributed to <strong>you</strong>.</p>
        <div class="rp-hint">Pick the store you are at:</div>
        <div class="rp-buttons">
            @forelse($locations as $loc)
                <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                    {{ csrf_field() }}
                    <input type="hidden" name="role" value="cashier">
                    <input type="hidden" name="location_id" value="{{ $loc->id }}">
                    <button type="submit" class="rp-btn rp-btn-cashier">
                        {{ $loc->name }}
                        @if(!empty($current_cashiers[$loc->id]))
                            <span class="rp-btn-sub">Currently: {{ $current_cashiers[$loc->id] }}</span>
                        @endif
                    </button>
                </form>
            @empty
                <em style="color:#8E8273;">No active locations configured.</em>
            @endforelse
        </div>
    </div>

    @if($can_manager)
    <div class="rp-card rp-manager">
        <h3>Manager <small>— admin / oversight</small></h3>
        <p>Full access. Sales will <strong>not</strong> be attributed to you, even if you ring something on the POS.</p>
        <div class="rp-buttons">
            <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="role" value="manager">
                <button type="submit" class="rp-btn rp-btn-soft">I'm in Manager mode</button>
            </form>
        </div>
    </div>
    @endif

    <div class="rp-card rp-inventory">
        <h3>Inventory / Receiving <small>— stocking, recording, photo upload</small></h3>
        <p>You can use the inventory pages. Sales will <strong>not</strong> be attributed to you.</p>
        <div class="rp-buttons">
            <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="role" value="inventory">
                <button type="submit" class="rp-btn rp-btn-soft">I'm doing Inventory</button>
            </form>
        </div>
    </div>

    <div class="rp-card rp-shipping">
        <h3>Shipping <small>— packing online / Discogs / eBay orders</small></h3>
        <p>Pack and dispatch orders. Sales will <strong>not</strong> be attributed to you.</p>
        <div class="rp-buttons">
            <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="role" value="shipping">
                <button type="submit" class="rp-btn rp-btn-soft">I'm doing Shipping</button>
            </form>
        </div>
    </div>

    <p class="rp-foot">
        Need to switch later? Visit <code>/choose-role</code> any time, or just log out and back in.
    </p>
</div>
</section>
@stop
