@extends('layouts.app')
@section('title', 'POS Requests')

@section('content')
<section class="content-header">
    <h1>POS Requests <small>what customers asked for at the register that we didn't have</small></h1>
</section>

<section class="content">

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('PosSearchRequestController@index') }}" class="row">
                <div class="col-md-3">
                    <label>Period</label>
                    <select name="period" class="form-control" onchange="this.form.submit()">
                        <option value="today" @if($period==='today') selected @endif>Today</option>
                        <option value="yesterday" @if($period==='yesterday') selected @endif>Yesterday</option>
                        <option value="this_week" @if($period==='this_week') selected @endif>This week</option>
                        <option value="last_7" @if($period==='last_7') selected @endif>Last 7 days</option>
                        <option value="this_month" @if($period==='this_month') selected @endif>This month</option>
                        <option value="last_30" @if($period==='last_30') selected @endif>Last 30 days</option>
                        <option value="this_quarter" @if($period==='this_quarter') selected @endif>This quarter</option>
                        <option value="last_90" @if($period==='last_90') selected @endif>Last 90 days</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Location</label>
                    <select name="location_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All locations</option>
                        @foreach($business_locations as $id => $name)
                            <option value="{{ $id }}" @if((string) $location_id === (string) $id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Search type</label>
                    <select name="type" class="form-control" onchange="this.form.submit()">
                        <option value="typed" @if($type==='typed') selected @endif>Typed (artist / title)</option>
                        <option value="scan" @if($type==='scan') selected @endif>Scanned barcodes only</option>
                        <option value="all" @if($type==='all') selected @endif>Everything</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label style="display:block;">&nbsp;</label>
                    <span class="text-muted">{{ $start->format('M j, Y') }} → {{ $end->format('M j, Y') }}</span>
                    @if($top_terms->count())
                        <a class="btn btn-xs btn-default"
                           href="{{ action('PosSearchRequestController@index', array_merge(request()->except('export'), ['export' => 'csv'])) }}"
                           style="margin-left:6px;"><i class="fa fa-download"></i> CSV</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    @if(!empty($migration_pending))
        <div class="alert alert-warning" style="border-left: 4px solid #f0ad4e;">
            <strong>Migration not yet run.</strong> The <code>pos_search_misses</code> table doesn't exist yet — run <code>php artisan migrate</code> on the server to start collecting data. This page will populate as cashiers search the POS.
        </div>
    @endif

    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-red">
                <span class="info-box-icon"><i class="fa fa-search-minus"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Empty searches</span>
                    <span class="info-box-number">{{ number_format($total_misses) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-yellow">
                <span class="info-box-icon"><i class="fa fa-list"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Distinct things asked for</span>
                    <span class="info-box-number">{{ number_format($unique_terms) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-barcode"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Scans with no match</span>
                    <span class="info-box-number">{{ number_format($scan_misses) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-users"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Cashiers searching</span>
                    <span class="info-box-number">{{ number_format($unique_users) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info" style="border-left: 4px solid #3c8dbc;">
        <strong>This is a buying list.</strong> Each row is a search someone ran at the register that returned nothing — a customer asked for it and we had nothing to show them. A term asked for repeatedly is real demand we're turning away. Use <em>Add to wants</em> to put one on the call-me list, or <em>Check catalog</em> to see whether we've picked it up since.
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title"><i class="fa fa-fire text-orange"></i> Most asked for</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Search term</th>
                        <th class="text-right">Times asked</th>
                        <th class="text-right">Cashiers</th>
                        <th>First asked</th>
                        <th>Last asked</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($top_terms as $r)
                        <tr>
                            <td>
                                <strong>{{ $r->term }}</strong>
                                @if($r->is_scan)
                                    <span class="label label-default" title="Numeric — barcode or SKU scan"><i class="fa fa-barcode"></i> scan</span>
                                @endif
                                <div class="pos-req-catalog text-muted" style="font-size:12px; margin-top:3px;"></div>
                            </td>
                            <td class="text-right">
                                @if($r->hits > 1)
                                    <span class="label label-danger">{{ $r->hits }}</span>
                                @else
                                    {{ $r->hits }}
                                @endif
                            </td>
                            <td class="text-right">{{ $r->staff_count }}</td>
                            <td><span class="text-muted">{{ \Carbon::parse($r->first_asked)->format('M j, Y') }}</span></td>
                            <td><span class="text-muted">{{ \Carbon::parse($r->last_asked)->diffForHumans() }}</span></td>
                            <td class="text-right" style="white-space:nowrap;">
                                <button type="button" class="btn btn-xs btn-default pos-req-check"
                                        data-term="{{ $r->term }}"
                                        title="See whether we carry it now"><i class="fa fa-search"></i> Check catalog</button>
                                <a class="btn btn-xs btn-primary" target="_blank"
                                   {{-- action(), not route(): /customer-wants/* is registered twice in web.php
                                        (hyphen names then underscore names, same URIs), so the hyphen route
                                        names lose the collision and aren't in the name lookup at all. --}}
                                   href="{{ action('CustomerWantController@create', ['artist' => $r->term, 'title' => $r->term]) }}"
                                   title="Add to the call-me-when-it-comes-in list"><i class="fa fa-heart"></i> Add to wants</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center">No empty searches in this window — everything asked for at the register was in the catalog.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row">
        @if($is_admin)
        <div class="col-md-6">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-user"></i> By cashier</h3>
                </div>
                <div class="box-body table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th class="text-right">Empty searches</th>
                                <th class="text-right">Distinct terms</th>
                                <th>Last searched</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($by_user as $r)
                                <tr>
                                    <td>
                                        @if(empty(trim($r->employee)))
                                            <span class="text-muted"><em>(unknown)</em></span>
                                        @else
                                            {{ trim($r->employee) }}
                                        @endif
                                    </td>
                                    <td class="text-right"><strong>{{ $r->misses }}</strong></td>
                                    <td class="text-right">{{ $r->unique_terms }}</td>
                                    <td><span class="text-muted">{{ \Carbon::parse($r->last_searched)->diffForHumans() }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center">Nothing in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        <div class="col-md-6">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-store"></i> By location</h3>
                </div>
                <div class="box-body table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th class="text-right">Empty searches</th>
                                <th class="text-right">Distinct terms</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($by_location as $r)
                                <tr>
                                    <td>
                                        @if(empty($r->location_name))
                                            <span class="text-muted"><em>(not recorded)</em></span>
                                        @else
                                            {{ $r->location_name }}
                                        @endif
                                    </td>
                                    <td class="text-right"><strong>{{ $r->misses }}</strong></td>
                                    <td class="text-right">{{ $r->unique_terms }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center">Nothing in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title"><i class="fa fa-history"></i> Recent (last 100)</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Search term</th>
                        @if($is_admin)<th>Cashier</th>@endif
                        <th>Location</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent as $r)
                        <tr>
                            <td><span class="text-muted">{{ \Carbon::parse($r->created_at)->format('M j, g:i a') }}</span></td>
                            <td>
                                {{ $r->term }}
                                @if($r->is_scan)
                                    <span class="label label-default"><i class="fa fa-barcode"></i> scan</span>
                                @endif
                            </td>
                            @if($is_admin)
                                <td>
                                    @if(empty(trim($r->employee)))
                                        <span class="text-muted"><em>(unknown)</em></span>
                                    @else
                                        {{ trim($r->employee) }}
                                    @endif
                                </td>
                            @endif
                            <td><span class="text-muted">{{ $r->location_name ?: '—' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $is_admin ? 4 : 3 }}" class="text-muted text-center">Nothing in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@endsection

@section('javascript')
<script>
$(document).ready(function () {
    // "Did we ever get it?" - checked one term at a time so the page load never
    // pays for a LIKE scan across every term listed.
    $(document).on('click', '.pos-req-check', function () {
        var $btn = $(this);
        var $out = $btn.closest('tr').find('.pos-req-catalog');

        $btn.prop('disabled', true);
        $out.removeClass('text-red text-green').addClass('text-muted').text('Checking...');

        $.getJSON('{{ route('reports.pos-requests.catalog-check') }}', { term: $btn.data('term') })
            .done(function (data) {
                $btn.prop('disabled', false);

                if (!data.matches || data.matches.length === 0) {
                    $out.removeClass('text-muted').addClass('text-red')
                        .text('Still not in the catalog.');
                    return;
                }

                var parts = $.map(data.matches, function (m) {
                    return m.name + ' (' + (m.qty > 0 ? m.qty + ' in stock' : 'out of stock') + ')';
                });
                if (data.more) { parts.push('and more'); }

                $out.removeClass('text-muted').addClass('text-green')
                    .text('In catalog now: ' + parts.join('; '));
            })
            .fail(function () {
                $btn.prop('disabled', false);
                $out.removeClass('text-muted').addClass('text-red').text('Check failed.');
            });
    });
});
</script>
@endsection
