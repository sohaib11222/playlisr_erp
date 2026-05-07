@extends('layouts.app')

@section('title', 'Help & Handbook')

@section('content')

<section class="content-header">
    <h1>Help &amp; Handbook
        <small>How to do anything in the ERP</small>
    </h1>
</section>

<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'Search the handbook'])
        <form method="GET" action="{{ route('help.index') }}" class="form-inline" style="margin-bottom: 8px;">
            <div class="input-group" style="width: 100%;">
                <input type="text" name="q" value="{{ $q }}" placeholder="Search how to do something… e.g. ‘print labels’, ‘store credit’, ‘ship Discogs order’" class="form-control" style="width: 100%;" autofocus>
                <span class="input-group-btn">
                    <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> Search</button>
                </span>
            </div>
            @if($q !== '')
                <p class="help-block" style="margin-top: 6px;">
                    <a href="{{ route('help.index') }}">Clear search</a>
                </p>
            @endif
        </form>

        @if($q !== '')
            <h4>Results for &ldquo;{{ $q }}&rdquo; <small>({{ count($results) }})</small></h4>
            @if(empty($results))
                <div class="alert alert-warning" style="margin-top: 8px;">
                    <i class="fa fa-info-circle"></i>
                    No matches. Try a simpler word, or
                    <a href="mailto:sarah@nivessa.com?subject=Help%20request:%20{{ urlencode($q) }}">email Sarah</a>.
                </div>
            @else
                <ul class="list-unstyled">
                    @foreach($results as $r)
                        <li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                            <a href="{{ route('help.show', $r['slug']) }}" style="font-size: 16px;">
                                <strong>{{ $r['title'] }}</strong>
                            </a>
                            @if(!empty($r['section']))
                                <span class="label label-default" style="margin-left: 6px;">{{ $r['section'] }}</span>
                            @endif
                            @if(!empty($r['summary']))
                                <div class="text-muted">{{ $r['summary'] }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <hr>
        @endif

        <h4>Browse by section</h4>
        @if(empty($sections))
            <p class="text-muted">No help articles yet.</p>
        @else
            <div class="row">
                @foreach($sections as $sectionName => $items)
                    <div class="col-md-6">
                        <div class="panel panel-default" style="margin-top: 10px;">
                            <div class="panel-heading"><strong>{{ $sectionName }}</strong></div>
                            <ul class="list-group">
                                @foreach($items as $item)
                                    <li class="list-group-item">
                                        <a href="{{ route('help.show', $item['slug']) }}">{{ $item['title'] }}</a>
                                        @if(!empty($item['summary']))
                                            <div class="text-muted small">{{ $item['summary'] }}</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="text-muted" style="margin-top: 14px;">
            <i class="fa fa-info-circle"></i>
            Don't see what you need? Email <a href="mailto:sarah@nivessa.com">sarah@nivessa.com</a> with what you were looking for and we'll add it.
        </p>
    @endcomponent
</section>

@endsection
