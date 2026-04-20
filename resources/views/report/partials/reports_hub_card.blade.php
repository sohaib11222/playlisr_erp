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
    <button type="button" class="rh-fav-btn {{ $is_fav ? 'is-fav' : '' }}" data-key="{{ $r['key'] }}" title="{{ $is_fav ? 'Remove from favorites' : 'Add to favorites' }}">
        <i class="fa {{ $is_fav ? 'fa-star' : 'fa-star-o' }}"></i>
    </button>
</a>
