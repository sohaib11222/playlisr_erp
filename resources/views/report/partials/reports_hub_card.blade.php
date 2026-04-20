@php
    $url = '#';
    try {
        $url = action($r['action']);
    } catch (\Throwable $e) {
        $url = '#';
    }
@endphp
<a href="{{ $url }}" class="rh-card" data-key="{{ $r['key'] }}">
    <span class="rh-card-icon"><i class="fa {{ $r['icon'] }}"></i></span>
    <span class="rh-card-body">
        <span class="rh-card-title">{{ $r['name'] }}</span>
        <span class="rh-card-desc">{{ $r['desc'] ?? '' }}</span>
    </span>
    {{-- Always use the solid .fa-star. The outline icon (.fa-star-o) was
         renamed to .far.fa-star in Font Awesome 5+ and renders invisible in
         this app's FA build — Sarah kept asking "where's the star?" because
         unfavorited cards were drawing a glyph that doesn't exist. Color on
         .rh-fav-btn already differentiates state (gray #cbd5e1 vs gold #f59e0b). --}}
    <button type="button" class="rh-fav-btn {{ $is_fav ? 'is-fav' : '' }}" data-key="{{ $r['key'] }}" title="{{ $is_fav ? 'Remove from favorites' : 'Add to favorites' }}">
        <i class="fa fa-star"></i>
    </button>
</a>
