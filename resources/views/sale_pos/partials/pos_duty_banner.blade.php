@php
    $pd = session('pos_duty');
    $dutyLabels = ['cashier' => 'Cashier', 'shipping' => 'Shipping', 'inventory' => 'Inventory'];
@endphp
@if(in_array($pd, ['cashier', 'shipping', 'inventory'], true))
    <div class="alert alert-info" style="margin-bottom:12px;padding:8px 14px;border-radius:8px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between;">
        <span>
            <strong>POS today:</strong> {{ $dutyLabels[$pd] ?? $pd }}
            @if(session('pos_duty_location_label'))
                · {{ session('pos_duty_location_label') }}
            @endif
        </span>
        <a href="{{ action('SellPosController@selectPosDuty', ['intended' => request()->fullUrl()]) }}" class="btn btn-default btn-sm">Change</a>
    </div>
@endif
