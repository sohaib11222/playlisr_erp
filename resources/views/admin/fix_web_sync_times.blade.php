@extends('layouts.app')
@section('title', 'Fix Website/Discogs Sale Times')

@section('content')
<section class="content-header">
    <h1>Fix Website / Discogs Sale Times</h1>
    <p class="text-muted">
        Website and Discogs sales the website pushed into the ERP <strong>before the
        2026-07-06 timezone fix</strong> stored their time in UTC, so they show about
        7 hours ahead on the recon feed (a 10:44am sale reads as 5:44pm). This sets
        each one back to its correct local time. It only touches live website/Discogs
        rows that are off by 2h+, and it is fully undoable.
    </p>
</section>

<section class="content">

    @if(session('status'))
        @php $st = session('status'); @endphp
        <div class="alert {{ ($st['success'] ?? 0) ? 'alert-success' : 'alert-danger' }}">{{ $st['msg'] ?? '' }}</div>
    @endif

    @if($mode === 'commit')
        <div class="alert alert-success">
            <strong>Fixed {{ $updated }} sale(s).</strong>
            @if($snapshot_key)
                Undo any time at <a href="{{ url('/admin/admin-action-history') }}">Admin Action History</a> (snapshot <code>{{ $snapshot_key }}</code>).
            @endif
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <p style="font-size:16px;">
                <strong>{{ number_format($count) }}</strong> website/Discogs sale(s) currently have a shifted (UTC) time.
            </p>

            @if($count > 0)
                <form method="POST" action="{{ url('/admin/fix-web-sync-times/run') }}" style="margin-bottom:16px;"
                      onsubmit="return confirm('Set {{ $count }} website/Discogs sale(s) to their correct local time? A snapshot is saved first so you can undo.');">
                    @csrf
                    <input type="hidden" name="commit" value="1">
                    <button type="submit" class="btn btn-primary">Fix {{ $count }} sale time(s)</button>
                    <a href="{{ url('/pos/recent-feed') }}" class="btn btn-default">Back to feed</a>
                </form>

                <p class="text-muted">Preview (up to 15) - current time &rarr; corrected time:</p>
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr><th>Invoice</th><th>Now shows</th><th>Will become</th><th>Amount</th><th>Note</th></tr>
                    </thead>
                    <tbody>
                        @foreach($samples as $s)
                            <tr>
                                <td>#{{ $s->invoice_no }}</td>
                                <td class="text-danger">{{ \Carbon\Carbon::parse($s->transaction_date)->format('M j, Y g:i a') }}</td>
                                <td class="text-success">{{ \Carbon\Carbon::parse($s->created_at)->format('M j, Y g:i a') }}</td>
                                <td>${{ number_format((float) $s->final_total, 2) }}</td>
                                <td><small>{{ \Illuminate\Support\Str::limit($s->additional_notes, 40) }}</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-success">Nothing to fix - all website/Discogs sale times look correct.</p>
                <a href="{{ url('/pos/recent-feed') }}" class="btn btn-default">Back to feed</a>
            @endif
        </div>
    </div>

</section>
@endsection
