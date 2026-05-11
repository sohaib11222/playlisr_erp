@php
    $pd = session('pos_duty');
    $dutyLabels = ['cashier' => 'Cashier', 'shipping' => 'Shipping', 'inventory' => 'Inventory', 'admin' => 'Admin'];
@endphp
@if(in_array($pd, ['cashier', 'shipping', 'inventory', 'admin'], true))
    <div style="margin-bottom:12px;padding:6px 12px;border-radius:6px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between;background:#FAF6EE;border:1px solid #ECE3CF;color:#5A5045;font-size:12px;">
        <span>
            <strong style="color:#1F1B16;">POS today:</strong> {{ $dutyLabels[$pd] ?? $pd }}
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
        <a href="{{ action('SellPosController@selectPosDuty', ['intended' => request()->fullUrl()]) }}" style="font-size:11px;color:#5A5045;text-decoration:underline;">Change</a>
    </div>
@endif
