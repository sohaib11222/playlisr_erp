@extends('layouts.app')
@section('title', 'Customer Wants')

@section('content')
<section class="content-header">
    <h1>Customer Wants <small>call-me-when-it-comes-in list</small>
        <a href="{{ action('CustomerWantController@create') }}" class="btn btn-primary pull-right"><i class="fa fa-plus"></i> Add Want</a>
    </h1>
</section>

<section class="content">

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('CustomerWantController@index') }}" class="row">
                <div class="col-md-3">
                    <label>Search</label>
                    <input type="text" class="form-control" name="q" value="{{ $q }}" placeholder="artist, title, phone, notes…">
                </div>
                <div class="col-md-2">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active" @if($status==='active') selected @endif>Active</option>
                        <option value="fulfilled" @if($status==='fulfilled') selected @endif>Fulfilled</option>
                        <option value="cancelled" @if($status==='cancelled') selected @endif>Cancelled</option>
                        <option value="" @if($status==='') selected @endif>All</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Priority</label>
                    <select name="priority" class="form-control">
                        <option value="">Any</option>
                        <option value="high" @if($priority==='high') selected @endif>High</option>
                        <option value="normal" @if($priority==='normal') selected @endif>Normal</option>
                        <option value="low" @if($priority==='low') selected @endif>Low</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Location</label>
                    <select name="location_id" class="form-control">
                        <option value="">All locations</option>
                        @foreach($business_locations as $id => $name)
                            <option value="{{ $id }}" @if((string)$location_id===(string)$id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label style="display:block;">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Priority</th>
                        <th>Artist</th>
                        <th>Title</th>
                        <th>Format</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Store</th>
                        <th>Added by</th>
                        <th>Added</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($wants as $w)
                    <tr>
                        <td>
                            @if($w->priority === 'high')
                                <span class="label label-danger">HIGH</span>
                            @elseif($w->priority === 'low')
                                <span class="label label-default">low</span>
                            @else
                                <span class="label label-info">normal</span>
                            @endif
                        </td>
                        <td>{{ $w->artist }}</td>
                        <td><strong>{{ $w->title }}</strong>@if($w->notes)<div class="text-muted"><small>{{ $w->notes }}</small></div>@endif</td>
                        <td>{{ $w->format }}</td>
                        <td>{{ $w->contact ? trim(($w->contact->first_name ?? '') . ' ' . ($w->contact->last_name ?? '')) : '—' }}</td>
                        <td>{{ $w->phone ?: ($w->contact->mobile ?? '') }}</td>
                        <td>{{ $w->location ? $w->location->name : '—' }}</td>
                        <td class="text-muted">{{ $w->creator ? trim(($w->creator->first_name ?? '') . ' ' . ($w->creator->last_name ?? '')) : '' }}</td>
                        <td class="text-muted">{{ $w->created_at->format('M j, Y') }}</td>
                        <td>
                            @if($w->status === 'active')
                                <span class="label label-warning">active</span>
                            @elseif($w->status === 'fulfilled')
                                <span class="label label-success">fulfilled</span>
                                @if($w->fulfilled_at)<div class="text-muted"><small>{{ \Carbon\Carbon::parse($w->fulfilled_at)->format('M j, Y') }}</small></div>@endif
                            @else
                                <span class="label label-default">cancelled</span>
                            @endif
                        </td>
                        <td class="text-right" style="white-space:nowrap;">
                            @if($w->status === 'active')
                                <form action="{{ action('CustomerWantController@fulfill', $w->id) }}" method="POST" style="display:inline-block;" onsubmit="return confirm('Mark as fulfilled?');">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-success"><i class="fa fa-check"></i> Fulfill</button>
                                </form>
                            @endif
                            <a href="{{ action('CustomerWantController@edit', $w->id) }}" class="btn btn-xs btn-default"><i class="fa fa-edit"></i></a>
                            <form action="{{ action('CustomerWantController@destroy', $w->id) }}" method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this want?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="text-center text-muted">No wants match these filters. Click "Add Want" to create one.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="text-center">{{ $wants->links() }}</div>
        </div>
    </div>
</section>
@stop
