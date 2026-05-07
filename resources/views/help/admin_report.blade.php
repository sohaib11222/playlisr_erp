@extends('layouts.app')

@section('title', 'Help search report')

@section('content')

<section class="content-header">
    <h1>Help search report
        <small>What people are searching for in the handbook</small>
    </h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            <form method="GET" class="form-inline" style="margin-bottom: 12px;">
                <label>Window:</label>
                <select name="days" class="form-control" onchange="this.form.submit()">
                    @foreach([7, 14, 30, 60, 90, 180, 365] as $d)
                        <option value="{{ $d }}" {{ $days == $d ? 'selected' : '' }}>Last {{ $d }} days</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Total searches'])
                <h2 style="margin: 0;">{{ number_format($totalSearches) }}</h2>
            @endcomponent
        </div>
        <div class="col-md-3">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Unique users'])
                <h2 style="margin: 0;">{{ number_format($uniqueUsers) }}</h2>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-7">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Top searches'])
                @if($top->isEmpty())
                    <p class="text-muted">No searches yet in this window.</p>
                @else
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th class="text-right">Searches</th>
                                <th class="text-right">Zero-hits</th>
                                <th class="text-right">Click-throughs</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($top as $row)
                                <tr>
                                    <td><a href="{{ route('help.index', ['q' => $row->query]) }}" target="_blank">{{ $row->query }}</a></td>
                                    <td class="text-right">{{ $row->cnt }}</td>
                                    <td class="text-right" style="{{ $row->zero_hits > 0 ? 'color:#d9534f;font-weight:600;' : '' }}">{{ $row->zero_hits }}</td>
                                    <td class="text-right">{{ $row->clicks }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endcomponent
        </div>
        <div class="col-md-5">
            @component('components.widget', ['class' => 'box-warning', 'title' => 'Searches with zero results — gaps to fill'])
                @if($zeroResult->isEmpty())
                    <p class="text-muted">No zero-result searches. The handbook is covering everyone's questions.</p>
                @else
                    <p class="help-block">These are the things people looked for but couldn't find. Each one is a candidate for a new help article.</p>
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th class="text-right">Times asked</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($zeroResult as $row)
                                <tr>
                                    <td>{{ $row->query }}</td>
                                    <td class="text-right">{{ $row->cnt }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endcomponent
        </div>
    </div>
</section>

@endsection
