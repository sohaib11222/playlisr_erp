@extends('layouts.app')
@section('title', 'Sling Connection')

@section('content')
<section class="content-header">
    <h1>Sling Connection <small>used for "Hours Worked" on the productivity report</small></h1>
</section>

<section class="content">

    @if(session('status_success'))
        <div class="alert alert-success">{{ session('status_success') }}</div>
    @endif
    @if(session('status_error'))
        <div class="alert alert-danger">{{ session('status_error') }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Status</h3></div>
        <div class="box-body">
            @if($connected)
                <p class="text-success"><i class="fa fa-check-circle"></i> <strong>Connected.</strong></p>
                <table class="table table-bordered" style="max-width:600px;">
                    <tr><th>Account email</th><td>{{ $email ?: '—' }}</td></tr>
                    <tr><th>Org id</th><td>{{ $orgId ?: '—' }}</td></tr>
                    <tr><th>Token saved at</th><td>{{ $savedAt ?: '—' }}</td></tr>
                </table>
                <form method="POST" action="{{ url('/admin/sling/disconnect') }}" onsubmit="return confirm('Disconnect — clear stored Sling token?');">
                    @csrf
                    <button type="submit" class="btn btn-default"><i class="fa fa-unlink"></i> Disconnect</button>
                </form>
                <hr>
                <p class="text-muted">If hours stop showing up on the report, the token has likely expired. Re-enter your password below to refresh it.</p>
            @else
                <p>Not connected. Type your Sling login below — the ERP will exchange it for an access token via Sling's API. <strong>Your password is not stored</strong>; only the resulting token is kept.</p>
            @endif
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Get your token in 4 clicks</h3></div>
        <div class="box-body">
            <ol>
                <li>
                    Drag this orange button up to your Chrome bookmarks bar:<br><br>
                    <a class="btn btn-warning"
                       style="cursor:move;"
                       href="javascript:(function(){function show(t){prompt('Sling token (Cmd+C to copy):',t);}var got=false;var of=window.fetch;window.fetch=function(){var a=arguments;var r=a[0] instanceof Request?a[0]:new Request(a[0],a[1]||{});var h=r.headers.get('Authorization');if(!got&&h&&/api\.getsling/.test(r.url||a[0])){got=true;show(h);}return of.apply(this,a);};var ox=XMLHttpRequest.prototype.setRequestHeader;XMLHttpRequest.prototype.setRequestHeader=function(n,v){if(!got&&n&&n.toLowerCase()==='authorization'&&v){got=true;show(v);}return ox.apply(this,arguments);};alert('Listening. Click any tab in Sling (Schedule, Reports) to surface the token.');})();">
                        <i class="fa fa-bookmark"></i> Get Sling Token
                    </a>
                    <br><small class="text-muted">If your bookmarks bar isn't showing, View → Always Show Bookmarks Bar.</small>
                </li>
                <li>Open <a href="https://app.getsling.com" target="_blank">app.getsling.com</a> in a new tab and make sure you're logged in.</li>
                <li>Click the <strong>Get Sling Token</strong> bookmark you just dragged. An alert says it's listening.</li>
                <li>Click the <strong>Schedule</strong> tab (or any tab) in Sling — a popup appears with your token already selected. Press <strong>Cmd+C</strong>, then close the popup.</li>
                <li>Come back here and paste below.</li>
            </ol>
            <form method="POST" action="{{ url('/admin/sling/save-token') }}" class="form-horizontal" autocomplete="off">
                @csrf
                <div class="form-group">
                    <label class="col-sm-3 control-label">Authorization token</label>
                    <div class="col-sm-9">
                        <textarea name="token" class="form-control" rows="3" required placeholder="paste the long token here"></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label">Org id</label>
                    <div class="col-sm-3">
                        <input type="text" name="org_id" class="form-control" value="{{ $orgId ?: '901214' }}" required>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-6">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-key"></i> Save token</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>
@endsection
