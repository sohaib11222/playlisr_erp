@extends('layouts.app')
@section('title', 'Artist fills')

@section('content')
<section class="content-header">
    <h1>Artist fills — everything set by the backfills</h1>
    <p class="text-muted">
        Every product whose Artist field was filled by the Discogs / name backfills, combined.
        "Before" is what was there (usually blank); "After" is the artist that was written.
    </p>
    <a href="{{ url('/admin/admin-action-history') }}" class="btn btn-default btn-sm">&larr; Back to history</a>
</section>

<section class="content">
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <div style="margin-bottom:12px;">
                    <input type="text" id="afSearch" class="form-control" style="max-width:420px;display:inline-block;"
                           placeholder="Search product or artist…">
                    <span class="text-muted" style="margin-left:8px;">
                        <strong id="afCount">{{ number_format(count($rows)) }}</strong> rows
                        @if ($capped) (showing the most recent {{ number_format($cap) }}) @endif
                    </span>
                </div>
                @if (empty($rows))
                    <p class="text-muted">No artist fills recorded yet.</p>
                @else
                    <table class="table table-striped" id="afTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Before</th>
                                <th>After (artist written)</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td>{{ $r['name'] }}</td>
                                    <td style="color:#8E8273;">{{ ($r['old'] === null || trim((string) $r['old']) === '') ? '(blank)' : $r['old'] }}</td>
                                    <td><strong>{{ $r['new'] }}</strong></td>
                                    <td>{{ $r['source'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
</section>

<script>
(function () {
    var box = document.getElementById('afSearch');
    var table = document.getElementById('afTable');
    var count = document.getElementById('afCount');
    if (!box || !table) { return; }
    var rows = Array.prototype.slice.call(table.tBodies[0].rows);
    var t = null;
    box.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
            var q = box.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (tr) {
                var hit = q === '' || tr.textContent.toLowerCase().indexOf(q) !== -1;
                tr.style.display = hit ? '' : 'none';
                if (hit) { shown++; }
            });
            count.textContent = shown.toLocaleString();
        }, 150);
    });
})();
</script>
@endsection
