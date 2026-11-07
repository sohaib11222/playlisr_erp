@extends('layouts.app')
@section('title', 'Genre Bible')

@section('content')
<style>
.gb-wrap { max-width: 1000px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.gb-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.gb-intro { color: #6B6155; font-size: 14px; margin: 0 0 14px; }
.gb-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 8px; }
.gb-search { flex: 1 1 260px; height: 44px; font-size: 17px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 14px; }
.gb-select { height: 44px; font-size: 15px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 10px; background: #fff; }
.gb-check { font-size: 15px; font-weight: 600; display: inline-flex; gap: 6px; align-items: center; cursor: pointer; margin: 0; }
.gb-count { color: #6B6155; font-size: 13px; margin: 6px 0 10px; }
.gb-table { width: 100%; border-collapse: collapse; font-size: 15px; background: #fff; border: 1px solid #E6DCCF; }
.gb-table th { text-align: left; background: #FAF6EE; padding: 9px 12px; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6B6155; position: sticky; top: 0; }
.gb-table td { padding: 8px 12px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.gb-genre { font-weight: 700; }
.gb-diff td { background: #FFF8DC; }
.gb-was { color: #8E8273; text-decoration: line-through; }
.gb-filed { color: #6B6155; font-size: 13px; }
</style>
<div class="gb-wrap">
    <h1>Genre Bible</h1>
    <p class="gb-intro">
        Every artist and the genre section they go in. The genre comes from the artist sheet, unless most of
        our products by that artist are filed in a different genre ({{ $minProducts }}+ products). Then that
        genre wins, and the row is highlighted. Same list as
        <a href="https://nivessa.com/genre-bible" target="_blank" rel="noopener">nivessa.com/genre-bible</a>.
    </p>
    <div class="gb-controls">
        <input class="gb-search" id="gbQ" type="search" placeholder="Search an artist" autofocus>
        <select class="gb-select" id="gbG">
            <option value="">All genres</option>
            @foreach ($genres as $g)
                <option value="{{ $g }}">{{ $g }}</option>
            @endforeach
        </select>
        <label class="gb-check"><input type="checkbox" id="gbD"> Only where it differs ({{ $differCount }})</label>
    </div>
    <div class="gb-count" id="gbN"></div>
    <table class="gb-table">
        <thead><tr><th>Artist</th><th>Genre</th><th>How we file it</th></tr></thead>
        <tbody id="gbBody">
        @foreach ($rows as $r)
            <tr class="{{ $r['differs'] ? 'gb-diff' : '' }}" data-n="{{ mb_strtolower($r['name']) }}" data-g="{{ $r['genre'] }}" data-d="{{ $r['differs'] ? 1 : 0 }}">
                <td><a href="/stock-lookup?q={{ urlencode($r['name']) }}">{{ $r['name'] }}</a></td>
                <td>
                    <span class="gb-genre">{{ $r['genre'] }}</span>
                    @if ($r['differs'])
                        <br><span class="gb-was">Sheet: {{ $r['sheet'] }}</span>
                    @endif
                </td>
                <td class="gb-filed">
                    @if ($r['counts'])
                        {{ implode(' · ', array_map(function ($bin, $n) { return $bin . ' ' . $n; }, array_keys($r['counts']), $r['counts'])) }}
                    @else
                        No products yet
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var q = document.getElementById('gbQ'), g = document.getElementById('gbG'), d = document.getElementById('gbD'),
        n = document.getElementById('gbN'), rows = document.getElementById('gbBody').rows, timer = null;
    function run() {
        var term = q.value.trim().toLowerCase(), genre = g.value, only = d.checked, shown = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i], ok = (!term || r.getAttribute('data-n').indexOf(term) !== -1)
                && (!genre || r.getAttribute('data-g') === genre)
                && (!only || r.getAttribute('data-d') === '1');
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        }
        n.textContent = shown.toLocaleString() + ' of ' + rows.length.toLocaleString() + ' artists';
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 120); });
    g.addEventListener('change', run);
    d.addEventListener('change', run);
    run();
})();
</script>
@endsection
