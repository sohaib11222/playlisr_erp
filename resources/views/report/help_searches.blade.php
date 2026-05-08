@extends('layouts.app')
@section('title', 'Handbook Searches Report')

@section('content')
<section class="content-header">
    <h1>Handbook Searches <small>what employees are looking up in the handbook</small></h1>
</section>

<section class="content">

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Window</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('HelpReportController@index') }}" class="row">
                <div class="col-md-4">
                    <label>Period</label>
                    <select name="period" class="form-control" onchange="this.form.submit()">
                        <option value="today" @if($period==='today') selected @endif>Today</option>
                        <option value="yesterday" @if($period==='yesterday') selected @endif>Yesterday</option>
                        <option value="this_week" @if($period==='this_week') selected @endif>This week</option>
                        <option value="last_7" @if($period==='last_7') selected @endif>Last 7 days</option>
                        <option value="this_month" @if($period==='this_month') selected @endif>This month</option>
                        <option value="last_30" @if($period==='last_30') selected @endif>Last 30 days</option>
                        <option value="this_quarter" @if($period==='this_quarter') selected @endif>This quarter</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label style="display:block;">&nbsp;</label>
                    <span class="text-muted">Window: <strong>{{ $start->format('M j, Y') }}</strong> → <strong>{{ $end->format('M j, Y') }}</strong></span>
                </div>
            </form>
        </div>
    </div>

    @if(!empty($migration_pending))
        <div class="alert alert-warning" style="border-left: 4px solid #f0ad4e;">
            <strong>Migration not yet run.</strong> The <code>help_searches</code> table doesn't exist yet — run <code>php artisan migrate</code> on the server to start collecting data. This page will populate as employees use the handbook search.
        </div>
    @endif

    <div class="row">
        <div class="col-md-4">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-search"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Total searches</span>
                    <span class="info-box-number">{{ number_format($total_searches) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-users"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Unique employees searching</span>
                    <span class="info-box-number">{{ number_format($unique_users) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-red">
                <span class="info-box-icon"><i class="fa fa-exclamation-circle"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Zero-result searches</span>
                    <span class="info-box-number">{{ number_format($zero_result_total) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info" style="border-left: 4px solid #3c8dbc;">
        <strong>Zero-result searches are gold.</strong> They tell you what the handbook is missing — every one is a topic an employee tried to look up and didn't find. Add an article (or expand an existing one) to fix it.
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-exclamation-triangle text-red"></i> Zero-result queries</h3>
                </div>
                <div class="box-body table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th class="text-right">Hits</th>
                                <th>Last searched</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($zero_result_queries as $r)
                                <tr>
                                    <td><code>{{ $r->query }}</code></td>
                                    <td class="text-right"><strong>{{ $r->hits }}</strong></td>
                                    <td><span class="text-muted">{{ \Carbon::parse($r->last_searched)->diffForHumans() }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center">No zero-result queries — the handbook is covering what employees are asking.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-fire text-orange"></i> Top queries</h3>
                </div>
                <div class="box-body table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th class="text-right">Hits</th>
                                <th class="text-right">Avg results</th>
                                <th>Last searched</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($top_queries as $r)
                                <tr>
                                    <td><code>{{ $r->query }}</code></td>
                                    <td class="text-right"><strong>{{ $r->hits }}</strong></td>
                                    <td class="text-right">{{ number_format((float) $r->avg_results, 1) }}</td>
                                    <td><span class="text-muted">{{ \Carbon::parse($r->last_searched)->diffForHumans() }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center">No searches in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title"><i class="fa fa-user"></i> By employee</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th class="text-right">Searches</th>
                        <th class="text-right">Zero-result</th>
                        <th>Last searched</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($by_user as $r)
                        <tr>
                            <td>
                                @if(empty(trim($r->employee)))
                                    <span class="text-muted"><em>(not signed in)</em></span>
                                @else
                                    {{ trim($r->employee) }}
                                @endif
                            </td>
                            <td class="text-right"><strong>{{ $r->searches }}</strong></td>
                            <td class="text-right">
                                @if($r->zero_results > 0)
                                    <span class="text-red">{{ $r->zero_results }}</span>
                                @else
                                    {{ $r->zero_results }}
                                @endif
                            </td>
                            <td><span class="text-muted">{{ \Carbon::parse($r->last_searched)->diffForHumans() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center">No searches in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title"><i class="fa fa-history"></i> Recent searches (last 50)</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Employee</th>
                        <th>Query</th>
                        <th class="text-right">Results</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent as $r)
                        <tr>
                            <td><span class="text-muted">{{ \Carbon::parse($r->created_at)->format('M j, g:i a') }}</span></td>
                            <td>
                                @if(empty(trim($r->employee)))
                                    <span class="text-muted"><em>(not signed in)</em></span>
                                @else
                                    {{ trim($r->employee) }}
                                @endif
                            </td>
                            <td><code>{{ $r->query }}</code></td>
                            <td class="text-right">
                                @if($r->result_count == 0)
                                    <span class="text-red"><strong>0</strong></span>
                                @else
                                    {{ $r->result_count }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center">No searches in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@endsection
