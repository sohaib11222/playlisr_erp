@php
  $aiMeta = ['strong' => ['Strong', 'green'], 'promising' => ['Promising', 'amber'], 'weak' => ['Weak', 'red']];
  $aiM = $aiMeta[$x['aiVerdict'] ?? ''] ?? ['Screened', 'gray'];
@endphp
@if(!isset($x['aiScore']) || $x['aiScore'] === null)
<span class="wi-muted">Pending</span>
@else
<span class="wi-pill {{ $aiM[1] }}">{{ $x['aiScore'] }}/100 &middot; {{ $aiM[0] }}</span>
 @if(empty($x['aiResumeRead']))<span title="The resume link could not be opened. Review it manually." style="color:#d97706;font-weight:700;">!</span>@endif
@endif
