@extends('layouts.app')

@section('title', 'Menu Usage')

@section('content')
<section class="content-header">
    <h1>Menu Usage <small>Most-used sections move to the top of the left menu</small></h1>
</section>
<section class="content">
    <div class="box">
        <div class="box-body">
            <p style="font-size:15px;color:#1F2937;">
                {{ count($report['days']) }} of {{ $minDays }} days of data collected.
                @if (count($report['days']) < $minDays)
                    The menu starts sorting itself once {{ $minDays }} days are in. Until then it keeps the current order.
                @else
                    The menu is sorted by these numbers (last 30 days).
                @endif
            </p>
            <table class="table table-striped" style="font-size:15px;max-width:640px;">
                <thead>
                    <tr><th>Menu section</th><th style="text-align:right;">People-days used</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr><td>{{ $r['title'] }}</td><td style="text-align:right;">{{ $r['score'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p style="color:#6B7280;">"People-days" counts each person once per day per section. Home, End Shift and Admin Tools stay pinned. Numbers refresh every 10 minutes.</p>
        </div>
    </div>
</section>
@endsection
