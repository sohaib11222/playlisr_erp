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
        <div class="box-header with-border"><h3 class="box-title">Paste a token (Sling's bash script)</h3></div>
        <div class="box-body">
            <p>
                Sling's server-side login is captcha-gated when called from the ERP.
                The fix: run Sling's published bash login script <strong>on your own Mac</strong>
                (your residential IP doesn't trigger the captcha) and paste the resulting token here.
            </p>
            <ol>
                <li>If you only sign into Sling with Google, first set a Sling password:
                    open <a href="https://app.getsling.com" target="_blank">app.getsling.com</a> →
                    sign out → "Forgot password" → enter your Sling email → set a password from the email link.</li>
                <li>Open <strong>Terminal.app</strong> on your Mac.</li>
                <li>Paste this command, replacing <code>YOUR_PASSWORD</code> with the password you just set:
                <pre style="white-space:pre-wrap;">curl -is "https://api.getsling.com/account/login" -X POST -d '{"email":"sarahedvat@gmail.com","password":"YOUR_PASSWORD"}' -H "Content-Type: application/json" -H "accept: */*" | grep -i "^authorization: " | sed "s/^authorization: //I"</pre>
                </li>
                <li>Press Return. The token is the long string that prints out.</li>
                <li>Copy that token and paste it below.</li>
            </ol>
            <form method="POST" action="{{ url('/admin/sling/save-token') }}" class="form-horizontal" autocomplete="off">
                @csrf
                <div class="form-group">
                    <label class="col-sm-3 control-label">Authorization token</label>
                    <div class="col-sm-9">
                        <textarea name="token" class="form-control" rows="3" required placeholder="paste the long Authorization value here"></textarea>
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
