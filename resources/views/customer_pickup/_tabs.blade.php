{{-- Pickups and Holds live under one menu item, "Customer Pickups & Holds"
     (Sarah 10/23). These tabs switch between the two pages. --}}
@php
    $cphTab = request()->segment(1) === 'holds' ? 'holds' : 'pickups';
    $cphActive = 0; $cphDue = 0;
    try {
        $cphBiz = (int) request()->session()->get('user.business_id');
        if (\App\Services\ProductHolds::enabled()) {
            $cphActive = (int) \DB::table('product_holds')->where('business_id', $cphBiz)->where('status', 'active')->count();
            $cphDue = \App\Services\ProductHolds::overdueCount($cphBiz);
        }
    } catch (\Throwable $e) {}
@endphp
<style>
    .cph-tabs { display: flex; gap: 8px; margin: 0 0 16px; flex-wrap: wrap; }
    .cph-tabs a { display: inline-block; padding: 7px 16px; border-radius: 999px; border: 1px solid #DFD2B3; background: #FFFFFF; color: #1F1B16; font-weight: 700; font-size: 14px; text-decoration: none; }
    .cph-tabs a.on { background: #DCEBFF; border-color: #9DC1F0; color: #1F4E8C; }
    .cph-tabs .due { color: #7A1F1F; }
</style>
<div class="cph-tabs">
    <a href="{{ action('CustomerPickupController@index') }}" class="{{ $cphTab === 'pickups' ? 'on' : '' }}">Pickups</a>
    <a href="{{ action('HoldController@index') }}" class="{{ $cphTab === 'holds' ? 'on' : '' }}">Holds ({{ $cphActive }})@if($cphDue > 0) <span class="due">&middot; {{ $cphDue }} past due</span>@endif</a>
</div>
