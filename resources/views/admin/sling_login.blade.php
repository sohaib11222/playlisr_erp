@extends('layouts.app')
@section('title', 'Sling Connection')

@section('content')
<section class="content-header">
    <h1>
        Sling Connection
        <small>used for "Hours Worked" on the productivity report</small>
        <a href="{{ url('/admin/sling/shifts') }}" class="btn btn-default btn-sm pull-right" style="margin-top:6px;">
            <i class="fa fa-calendar"></i> View synced shifts
        </a>
    </h1>
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

    <div class="box box-success">
        <div class="box-header with-border"><h3 class="box-title">Easiest: log in with your Sling email + password</h3></div>
        <div class="box-body">
            <p>The ERP will call Sling's <code>/account/login</code> for you, take the token Sling returns, and save it. <strong>Your password is not stored</strong> — only the token. Use this whenever the connection above shows expired/401.</p>
            <form method="POST" action="{{ url('/admin/sling/login') }}" class="form-horizontal" autocomplete="off">
                @csrf
                <div class="form-group">
                    <label class="col-sm-3 control-label">Sling email</label>
                    <div class="col-sm-6">
                        <input type="email" name="email" class="form-control" required value="{{ $email ?: '' }}" placeholder="you@example.com">
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label">Sling password</label>
                    <div class="col-sm-6">
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-6">
                        <button type="submit" class="btn btn-success"><i class="fa fa-sign-in"></i> Log in &amp; save token</button>
                        <small class="text-muted" style="margin-left:8px;">If Sling rejects this with "captcha required", use one of the paste options below.</small>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">Backup option: paste a "Copy as cURL"</h3></div>
        <div class="box-body">
            <p>This is the most reliable path. The cURL Chrome generates contains the exact <code>Authorization</code> header Sling expects.</p>
            <ol>
                <li>Open <a href="https://app.getsling.com" target="_blank">app.getsling.com</a> and make sure you're logged in.</li>
                <li>Press <strong>Cmd+Option+I</strong> → click the <strong>Network</strong> tab.</li>
                <li>Press <strong>Cmd+R</strong> to reload Sling so the network log fills up.</li>
                <li>In the Filter box at the top of the Network tab, type <code>api.getsling</code>.</li>
                <li><strong>Right-click any row</strong> → hover <strong>Copy</strong> → click any one of: <strong>Copy as cURL</strong>, <strong>Copy as fetch</strong>, or <strong>Copy as PowerShell</strong> (whichever your Chrome shows).</li>
                <li>Paste the entire thing in the box below and click Save. The ERP extracts the token automatically.</li>
            </ol>
            <form method="POST" action="{{ url('/admin/sling/save-curl') }}" autocomplete="off">
                @csrf
                <textarea name="curl" class="form-control" rows="6" placeholder='paste the full curl command starting with: curl "https://api.getsling.com/v1/..."' required></textarea>
                <br>
                <button type="submit" class="btn btn-primary"><i class="fa fa-magic"></i> Extract & save token</button>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Alternative: bookmarklet (sometimes works)</h3></div>
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
