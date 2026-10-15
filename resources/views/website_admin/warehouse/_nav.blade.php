@php
  $whTabs = [
    'wa.warehouse.index' => 'Orders',
    'wa.warehouse.packer' => 'Packer View',
    'wa.warehouse.zones' => 'Zones',
    'wa.warehouse.locations' => 'Locations',
    'wa.warehouse.analytics' => 'Employee Analytics',
    'wa.warehouse.metrics' => 'Metrics',
    'wa.warehouse.rates' => 'Shipping Rates',
  ];
  $whCurrent = Route::currentRouteName();
@endphp
<nav class="wh-nav">
  @foreach($whTabs as $whRoute => $whLabel)
    <a href="{{ route($whRoute) }}" class="{{ $whCurrent === $whRoute || ($whRoute === 'wa.warehouse.rates' && $whCurrent === 'wa.warehouse.ratesAdd') ? 'active' : '' }}">{{ $whLabel }}</a>
  @endforeach
  <a href="{{ route('website-orders.index') }}" class="ext">ERP Orders page</a>
</nav>
@if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
@if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
