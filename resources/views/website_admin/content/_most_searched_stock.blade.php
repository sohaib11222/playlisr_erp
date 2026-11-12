@foreach($stockLocations as $locId => $locName)
  @if($stock === null)
    <td class="num wc-muted">–</td>
  @else
    @php $n = (float) ($stock[$locId] ?? 0); @endphp
    <td class="num" style="{{ $n > 0 ? 'font-weight:600;' : 'color:#dc2626;' }}">{{ number_format($n) }}</td>
  @endif
@endforeach
