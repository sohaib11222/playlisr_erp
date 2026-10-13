{{-- Sortable column header: click toggles asc/desc, kept in the URL. --}}
@php
  $isSorted = ($sortKey ?? '') === $key;
  $nextDir = $isSorted && ($sortDir ?? 'asc') === 'asc' ? 'desc' : 'asc';
  $href = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir]);
@endphp
<a class="wo-sort" href="{{ $href }}">{{ $label }}@if($isSorted)<span class="wo-sort-arrow">{{ ($sortDir ?? 'asc') === 'asc' ? '▲' : '▼' }}</span>@endif</a>
