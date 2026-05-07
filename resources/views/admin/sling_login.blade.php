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
                <form method="POST" action="{{ url('/admin/sling/test') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-info"><i class="fa fa-bolt"></i> Test connection</button>
                </form>
                <form method="POST" action="{{ url('/admin/sling/disconnect') }}" style="display:inline;" onsubmit="return confirm('Disconnect — clear stored Sling token?');">
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
                       href="javascript:(function(){var got=false;function done(h){if(got)return;got=true;navigator.clipboard&&navigator.clipboard.writeText(h).then(function(){alert('Copied:\n\n'+h);},function(){prompt('Cmd+C:',h);})||prompt('Cmd+C:',h);}var of=window.fetch;window.fetch=function(){var a=arguments;var r=a[0] instanceof Request?a[0]:new Request(a[0],a[1]||{});var h=r.headers.get('Authorization');if(h&&/api\.getsling/.test(r.url||a[0]+''))done(h);return of.apply(this,a);};var ox=XMLHttpRequest.prototype.setRequestHeader;XMLHttpRequest.prototype.setRequestHeader=function(n,v){if(n&&n.toLowerCase()==='authorization'&&v)done(v);return ox.apply(this,arguments);};alert('Listening. Now reload the Sling page (Cmd+R). Token will pop up automatically.');})();">
                        <i class="fa fa-bookmark"></i> Get Sling Token
                    </a>
                    <br><small class="text-muted">If your bookmarks bar isn't showing, View → Always Show Bookmarks Bar. If you already dragged an old version of this button, delete it and re-drag.</small>
                </li>
                <li>Open <a href="https://app.getsling.com" target="_blank">app.getsling.com</a> in a new tab and make sure you're logged in.</li>
                <li>Click the <strong>Get Sling Token</strong> bookmark on that tab. An alert says "Listening" — press OK.</li>
                <li>Press <strong>Cmd+R</strong> to reload Sling. The page reload triggers fresh API calls; the first one's <code>Authorization</code> header is captured. A popup appears with the full value (e.g. <code>Bearer eyJ...</code>). It's also auto-copied to your clipboard.</li>
                <li>Come back here and paste in the field below (Cmd+V). Save the FULL value including the <code>Bearer</code> prefix if present.</li>
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
