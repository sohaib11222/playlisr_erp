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
            @if($pd === 'cashier' && session('pos_duty_opening_cash') !== null)
                @php
                    $openCash = (float) session('pos_duty_opening_cash');
                    $openAt = session('pos_duty_opening_cash_at');
                    $openAtLabel = '';
                    if ($openAt) {
                        try { $openAtLabel = \Carbon\Carbon::parse($openAt)->setTimezone(config('app.timezone'))->format('g:i A'); }
                        catch (\Throwable $e) {}
                    }
                @endphp
                · <strong>Opening cash:</strong> ${{ number_format($openCash, 2) }}
                @if($openAtLabel) <span class="text-muted">(logged {{ $openAtLabel }})</span> @endif
            @endif
        </span>
        <a href="{{ action('SellPosController@selectPosDuty', ['intended' => request()->fullUrl()]) }}" class="btn btn-default btn-sm">Change</a>
    </div>
@endif
