@php
  $wcNav = [
    'wa.catalog.products' => 'Products',
    'wa.catalog.images' => 'Missing Images',
    'wa.catalog.metadata' => 'Metadata',
    'wa.catalog.requests' => 'Stock Requests',
    'wa.catalog.synced' => 'Synced Products',
    'wa.catalog.featured' => 'Featured Products',
    'wa.catalog.records' => 'Featured Records',
    'wa.catalog.categories' => 'Categories',
    'wa.catalog.collections' => 'Collections',
    'wa.catalog.giftcards' => 'Gift Cards',
  ];
  $wcCurrent = request()->route() ? request()->route()->getName() : '';
@endphp
<div class="wc-nav">
  @foreach($wcNav as $wcRoute => $wcLabel)
    <a href="{{ route($wcRoute) }}" class="{{ strpos((string) $wcCurrent, $wcRoute) === 0 ? 'active' : '' }}">{{ $wcLabel }}</a>
  @endforeach
</div>
