@php
  $stMeta = [
    'new' => ['New', 'blue'],
    'accepted' => ['Accepted', 'green'],
    'hold' => ['Hold', 'amber'],
    'maybe' => ['Maybe', 'purple'],
    'rejected' => ['Rejected', 'red'],
    'archived' => ['Archived', 'gray'],
  ];
  $m = $stMeta[$st] ?? $stMeta['new'];
@endphp
<span class="wi-pill {{ $m[1] }}">{{ $m[0] }}</span>
