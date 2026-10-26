@extends('layouts.app')

@section('title', 'Markdowns')

@section('content')
@include('bulk_transfer.partials.style')
@php
  $money = function ($n) { return '$' . number_format((float) $n, 2); };
  $qtyf = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
@endphp

<div class="tr-wrap" style="max-width:1400px;">
  <div class="tr-head">
    <h1>Markdowns</h1>
    <p>Old stock gets cheaper in steps so it sells and frees up cash. Age counts from the original listed date, every step is off the original price, and nothing goes below what we paid. Nothing changes until a manager clicks Mark down.</p>
  </div>

  @if(session('status'))
    <div class="tr-msg {{ session('status.success') ? 'ok' : 'bad' }}">
      {{ session('status.msg') }}
      @if(session('markdown_labels_url'))
        <div style="margin-top:10px;"><a class="tr-btn tr-btn-primary" target="_blank" href="{{ session('markdown_labels_url') }}">Print new tags (keep the original listed date)</a></div>
      @endif
    </div>
  @endif

  <div class="tr-card">
    <h2>The rules</h2>
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
      @foreach($rules['tiers'] as $t)
        <span class="tr-pill dup" style="font-size:13px;">{{ $t[0] }}+ days: {{ $t[1] }}% off</span>
      @endforeach
    </div>
    <p style="font-size:14px; margin:0;">
      Never below cost{{ $rules['min_margin_pct'] > 0 ? ' + ' . $rules['min_margin_pct'] . '%' : '' }}.
      No cost on file: never more than {{ $rules['no_cost_max_off'] }}% off.
      Never under {{ $money($rules['min_price']) }}.
      {{ $rules['round_99'] ? 'Prices end in .99.' : '' }}
      @if($rules['exclude_category_ids'])
        Never marked down: {{ collect($rules['exclude_category_ids'])->map(function ($id) use ($categories) { return $categories[$id] ?? ('#' . $id); })->implode(', ') }}.
      @endif
    </p>
    @if($isAdmin)
      <details style="margin-top:12px;">
        <summary class="tr-link">Change the rules</summary>
        <form method="post" action="{{ url('/markdowns/rules') }}" style="margin-top:12px;">
          @csrf
          <div class="tr-row">
            @for($i = 0; $i < 6; $i++)
              <div class="tr-field" style="min-width:150px; flex:0;">
                <label>Step {{ $i + 1 }}: days / % off</label>
                <div style="display:flex; gap:6px;">
                  <input type="number" min="1" name="tiers[{{ $i }}][days]" value="{{ $rules['tiers'][$i][0] ?? '' }}" style="width:70px; border:1px solid var(--tr-line-2); border-radius:7px; padding:6px;">
                  <input type="number" min="1" max="95" name="tiers[{{ $i }}][pct]" value="{{ $rules['tiers'][$i][1] ?? '' }}" style="width:60px; border:1px solid var(--tr-line-2); border-radius:7px; padding:6px;">
                </div>
              </div>
            @endfor
          </div>
          <div class="tr-row">
            <div class="tr-field" style="min-width:180px; flex:0;"><label>Profit over cost to keep (%)</label>
              <input type="number" min="0" step="1" name="min_margin_pct" value="{{ $rules['min_margin_pct'] }}" style="border:1px solid var(--tr-line-2); border-radius:7px; padding:6px;"></div>
            <div class="tr-field" style="min-width:180px; flex:0;"><label>No cost on file: max % off</label>
              <input type="number" min="0" max="90" name="no_cost_max_off" value="{{ $rules['no_cost_max_off'] }}" style="border:1px solid var(--tr-line-2); border-radius:7px; padding:6px;"></div>
            <div class="tr-field" style="min-width:160px; flex:0;"><label>Lowest price ($)</label>
              <input type="number" min="0" step="0.01" name="min_price" value="{{ $rules['min_price'] }}" style="border:1px solid var(--tr-line-2); border-radius:7px; padding:6px;"></div>
            <div class="tr-field" style="min-width:160px; flex:0;"><label>&nbsp;</label>
              <label style="font-weight:600;"><input type="checkbox" name="round_99" value="1" {{ $rules['round_99'] ? 'checked' : '' }}> End prices in .99</label></div>
          </div>
          <div class="tr-field" style="margin-bottom:12px;"><label>Never mark down these categories</label>
            <select name="exclude_category_ids[]" multiple size="6">
              @foreach($categories as $id => $name)
                <option value="{{ $id }}" {{ in_array($id, $rules['exclude_category_ids']) ? 'selected' : '' }}>{{ $name }}</option>
              @endforeach
            </select>
            <span class="tr-hint">Hold Cmd (Mac) or Ctrl to pick more than one.</span>
          </div>
          <button class="tr-btn tr-btn-primary" type="submit">Save rules</button>
        </form>
      </details>
    @endif
  </div>

  <div class="tr-card">
    <form method="get" action="{{ url('/markdowns') }}" class="tr-row" style="margin-bottom:0;">
      <div class="tr-field" style="min-width:160px;"><label>Store</label>
        <select name="location_id">@foreach($locations as $id => $name)<option value="{{ $id }}" {{ $id == $location_id ? 'selected' : '' }}>{{ $name }}</option>@endforeach</select></div>
      <div class="tr-field" style="min-width:200px;"><label>Category</label>
        <select name="category_id"><option value="0">All</option>@foreach($categories as $id => $name)<option value="{{ $id }}" {{ $id == $category_id ? 'selected' : '' }}>{{ $name }}</option>@endforeach</select></div>
      <div class="tr-field" style="min-width:160px;"><label>Listed at least (days ago)</label>
        <select name="min_age"><option value="0">Any due</option>@foreach($rules['tiers'] as $t)<option value="{{ $t[0] }}" {{ $min_age == $t[0] ? 'selected' : '' }}>{{ $t[0] }}+ days</option>@endforeach</select></div>
      <button type="submit" class="tr-btn tr-btn-primary" style="height:40px;">Show items</button>
    </form>
  </div>

  <div class="tr-card">
    <h2>Due for a markdown at {{ $locations[$location_id] ?? '' }}</h2>
    <div style="display:flex; gap:24px; flex-wrap:wrap; font-size:14px; margin-bottom:12px;">
      <div><strong>{{ number_format($stats['due']) }}</strong> items ({{ $qtyf($stats['qty']) }} copies)</div>
      <div>Cost tied up: <strong>{{ $money($stats['cost_tied']) }}</strong></div>
      <div>Shelf value now: <strong>{{ $money($stats['value_now']) }}</strong></div>
      <div>After markdown: <strong>{{ $money($stats['value_new']) }}</strong></div>
      <div>Stopped at cost: <strong>{{ number_format($stats['at_floor']) }}</strong></div>
    </div>
    @if($stats['cost_over_price'])
      <div class="tr-msg bad" style="font-weight:500;">{{ number_format($stats['cost_over_price']) }} old items have a cost equal to or above their price, so they are never marked down. Their cost is probably wrong.</div>
    @endif
    @if($stats['capped'])
      <div class="tr-msg bad" style="font-weight:500;">Only the oldest {{ number_format(\App\Http\Controllers\MarkdownController::SCAN_MAX) }} items were checked. Pick a category to see the rest.</div>
    @endif

    <form method="post" action="{{ url('/markdowns/apply') }}" id="md-form">
      @csrf
      <input type="hidden" name="location_id" value="{{ $location_id }}">
      @if($canApply && $shown)
        <div class="tr-actions" style="margin-bottom:12px;">
          <label style="font-weight:600; margin:0;"><input type="checkbox" id="md-all"> Select all {{ count($shown) }} shown</label>
          <button type="submit" class="tr-btn tr-btn-primary" id="md-apply" disabled>Mark down 0 items</button>
          <span class="tr-hint">Up to {{ \App\Http\Controllers\MarkdownController::APPLY_MAX }} at a time. Then print the new tags.</span>
        </div>
      @elseif(!$canApply)
        <div class="tr-msg bad" style="font-weight:500;">Only a manager can apply markdowns. You can look.</div>
      @endif
      <div class="tr-table-wrap">
        <table class="tr-table">
          <thead><tr>
            @if($canApply)<th></th>@endif
            <th>Artist - Title</th><th>Category</th><th>Listed</th><th class="num">Days</th><th class="num">Qty</th>
            <th class="num">Cost</th><th class="num">Original</th><th class="num">Now</th><th class="num">New price</th><th>Why</th>
          </tr></thead>
          <tbody>
          @forelse($shown as $r)
            <tr>
              @if($canApply)<td><input type="checkbox" class="md-cb" name="variation_ids[]" value="{{ $r['variation_id'] }}"></td>@endif
              <td>{{ $r['name'] }}<div style="font-size:12px;">{{ $r['sku'] }}</div></td>
              <td>{{ $r['category'] }}</td>
              <td>{{ $r['listed'] }}</td>
              <td class="num">{{ $r['age_days'] }}</td>
              <td class="num">{{ $qtyf($r['qty']) }}</td>
              <td class="num">{{ $r['no_cost'] ? 'none' : $money($r['cost']) }}</td>
              <td class="num">{{ $money($r['original_price']) }}</td>
              <td class="num">{{ $money($r['price']) }}</td>
              <td class="num"><strong>{{ $money($r['new_price']) }}</strong></td>
              <td>
                <span class="tr-pill dup">{{ $r['pct'] }}% step</span>
                @if($r['hit_floor'])<span class="tr-pill bad">{{ $r['no_cost'] ? 'max off, no cost' : 'stopped at cost' }}</span>@endif
                @if($r['last_markdown'])<div style="font-size:12px;">last cut {{ $r['last_markdown'] }}</div>@endif
              </td>
            </tr>
          @empty
            <tr><td colspan="11" class="tr-empty">Nothing is due for a markdown here.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      @if(count($rows) > count($shown))
        <p class="tr-hint">Showing the oldest {{ count($shown) }} of {{ number_format(count($rows)) }}. Mark these down and the next ones show up.</p>
      @endif
    </form>
  </div>

  <div class="tr-card">
    <h2>Markdown history</h2>
    <div class="tr-table-wrap">
      <table class="tr-table">
        <thead><tr><th>Date</th><th>Artist - Title</th><th>Listed</th><th class="num">Original</th><th class="num">Was</th><th class="num">Now</th><th class="num">Cost</th><th>Step</th><th>By</th></tr></thead>
        <tbody>
        @forelse($history as $h)
          <tr><td>{{ $h->date }}</td><td>{{ $h->name }}</td><td>{{ $h->listed }}</td><td class="num">{{ $money($h->original) }}</td>
            <td class="num">{{ $money($h->old) }}</td><td class="num">{{ $money($h->new) }}</td><td class="num">{{ $h->cost > 0 ? $money($h->cost) : 'none' }}</td>
            <td>{{ $h->pct }}%{{ $h->floor ? ', stopped at cost' : '' }}</td><td>{{ $h->by }}</td></tr>
        @empty
          <tr><td colspan="9" class="tr-empty">No markdowns yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection

@section('javascript')
<script type="text/javascript">
(function () {
  function refresh() {
    var n = $('.md-cb:checked').length;
    $('#md-apply').prop('disabled', n === 0).text('Mark down ' + n + ' item' + (n === 1 ? '' : 's'));
  }
  $('#md-all').on('change', function () { $('.md-cb').prop('checked', this.checked); refresh(); });
  $(document).on('change', '.md-cb', refresh);
  $('#md-form').on('submit', function (e) {
    var n = $('.md-cb:checked').length;
    if (!n) { e.preventDefault(); return; }
    $('#md-apply').prop('disabled', true).text('Marking down...');
  });
})();
</script>
@endsection
