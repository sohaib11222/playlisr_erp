{{-- Expects: $indexAction (URL of the current index page), $store (current filter), $storeLabels --}}
@php($baseQuery = request()->except(['store', 'page']))
<div class="btn-group" role="group" style="margin-right:15px;">
    <a href="{{ $indexAction }}?{{ http_build_query($baseQuery) }}" class="btn btn-sm {{ !$store ? 'btn-primary' : 'btn-default' }}">All Stores</a>
    @foreach($storeLabels as $key => $label)
        <a href="{{ $indexAction }}?{{ http_build_query(array_merge($baseQuery, ['store' => $key])) }}" class="btn btn-sm {{ $store===$key ? 'btn-primary' : 'btn-default' }}">{{ $label }}</a>
    @endforeach
</div>
