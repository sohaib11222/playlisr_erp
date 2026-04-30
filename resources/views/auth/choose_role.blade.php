@extends('layouts.app')
@section('title', "What are you working on?")

@section('content')
<section class="content-header">
    <h1 style="text-align:center;">What are you working on today?</h1>
    <p class="text-muted" style="text-align:center;">
        Pick what you are doing right now. Your choice tells the POS who to attribute sales to.
    </p>
</section>

<section class="content">
@php
    $hello = optional(auth()->user())->first_name;
@endphp

@if(session('status') && is_array(session('status')) && empty(session('status.success')))
<div class="alert alert-warning" style="max-width:760px; margin:0 auto 18px;">{{ session('status.msg') }}</div>
@endif

<div style="max-width:760px; margin:0 auto;">
    @if($hello)
        <p style="text-align:center; font-size:16px; margin-bottom:18px;">Hi <strong>{{ $hello }}</strong> — choose your role for this shift:</p>
    @endif

    {{-- Cashier card with location buttons. This is the only role that
         changes attribution: picking a location stamps the user as the
         active cashier there. --}}
    <div class="box box-solid" style="border-top:4px solid #1F8FE0;">
        <div class="box-header"><h3 class="box-title">Cashier <small style="color:#777;">— Front Desk, ringing up customers</small></h3></div>
        <div class="box-body">
            <p style="margin-top:0;">All sales rung at this store while you are the cashier will be attributed to <strong>you</strong>.</p>
            <p style="color:#777; font-size:13px;">Pick the store you are at:</p>
            <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:6px;">
                @forelse($locations as $loc)
                    <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                        {{ csrf_field() }}
                        <input type="hidden" name="role" value="cashier">
                        <input type="hidden" name="location_id" value="{{ $loc->id }}">
                        <button type="submit" class="btn btn-primary btn-lg" style="min-width:180px;">
                            {{ $loc->name }}
                            @if(!empty($current_cashiers[$loc->id]))
                                <div style="font-size:11px; opacity:0.85; margin-top:4px;">
                                    Currently: {{ $current_cashiers[$loc->id] }}
                                </div>
                            @endif
                        </button>
                    </form>
                @empty
                    <em>No active locations configured.</em>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Manager: Sarah / Jon / Fatteen / Lashyn only. No sale attribution. --}}
    @if($can_manager)
    <div class="box box-solid" style="border-top:4px solid #8E5BA8;">
        <div class="box-header"><h3 class="box-title">Manager <small style="color:#777;">— admin / oversight</small></h3></div>
        <div class="box-body">
            <p style="margin-top:0;">Full access. Sales will <strong>not</strong> be attributed to you, even if you ring something on the POS.</p>
            <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="role" value="manager">
                <button type="submit" class="btn btn-default btn-lg" style="min-width:200px;">I'm in Manager mode</button>
            </form>
        </div>
    </div>
    @endif

    {{-- Inventory: no sale attribution. --}}
    <div class="box box-solid" style="border-top:4px solid #2C9F6F;">
        <div class="box-header"><h3 class="box-title">Inventory / Receiving <small style="color:#777;">— stocking, recording, photo upload</small></h3></div>
        <div class="box-body">
            <p style="margin-top:0;">You can use the inventory pages. Sales will <strong>not</strong> be attributed to you.</p>
            <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="role" value="inventory">
                <button type="submit" class="btn btn-default btn-lg" style="min-width:200px;">I'm doing Inventory</button>
            </form>
        </div>
    </div>

    {{-- Shipping: no sale attribution. --}}
    <div class="box box-solid" style="border-top:4px solid #C97A2A;">
        <div class="box-header"><h3 class="box-title">Shipping <small style="color:#777;">— packing online / Discogs / eBay orders</small></h3></div>
        <div class="box-body">
            <p style="margin-top:0;">Pack and dispatch orders. Sales will <strong>not</strong> be attributed to you.</p>
            <form method="POST" action="{{ url('/choose-role') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="role" value="shipping">
                <button type="submit" class="btn btn-default btn-lg" style="min-width:200px;">I'm doing Shipping</button>
            </form>
        </div>
    </div>

    <p style="text-align:center; color:#888; font-size:12px; margin-top:14px;">
        Need to switch later? Visit <code>/choose-role</code> any time, or just log out and back in.
    </p>
</div>
</section>
@stop
