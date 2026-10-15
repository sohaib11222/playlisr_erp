{{-- Prev/next pager. Needs $page, $totalPages; keeps the current query string. --}}
@if($totalPages > 1)
<div class="wc-pager">
  <span>Page {{ $page }} of {{ $totalPages }}@if(isset($total)) ({{ number_format($total) }} total)@endif</span>
  <div class="wc-actions">
    @if($page > 1)
      <a class="wc-btn wc-btn-sm" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>
    @else
      <button class="wc-btn wc-btn-sm" disabled>Previous</button>
    @endif
    @if($page < $totalPages)
      <a class="wc-btn wc-btn-sm" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>
    @else
      <button class="wc-btn wc-btn-sm" disabled>Next</button>
    @endif
  </div>
</div>
@endif
