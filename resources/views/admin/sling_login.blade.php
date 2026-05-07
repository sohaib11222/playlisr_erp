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
        <div class="box-header with-border"><h3 class="box-title">Paste token directly</h3></div>
        <div class="box-body">
            <p>
                Sling's automated login now requires a captcha, so the email/password form below won't work for most accounts.
                Use this instead:
            </p>
            <ol>
                <li>Open <a href="https://app.getsling.com" target="_blank">app.getsling.com</a> in another tab and sign in.</li>
                <li>Press <strong>Cmd+Option+I</strong> to open Chrome DevTools, click the <strong>Network</strong> tab.</li>
                <li>In the <strong>Filter</strong> box at the top, type <code>concise</code>.</li>
                <li>Click any row that appears.</li>
                <li>In the side panel, click <strong>Headers</strong>, scroll to <strong>Request Headers</strong>, and copy the value next to <code>Authorization:</code>.</li>
                <li>Paste it below. The org id is the number in the URL after <code>/v1/</code> (likely <strong>901214</strong> for Nivessa).</li>
            </ol>
            <form method="POST" action="{{ url('/admin/sling/save-token') }}" class="form-horizontal" autocomplete="off">
                @csrf
                <div class="form-group">
                    <label class="col-sm-3 control-label">Authorization token</label>
                    <div class="col-sm-9">
                        <textarea name="token" class="form-control" rows="3" required placeholder="paste the long Authorization header value here"></textarea>
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

    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">Email + password (likely blocked by captcha)</h3></div>
        <div class="box-body">
            <p class="text-muted">Try this only if Sling has not yet enabled captcha on your account.</p>
            <form method="POST" action="{{ url('/admin/sling/login') }}" class="form-horizontal" autocomplete="off">
                @csrf
                <div class="form-group">
                    <label class="col-sm-3 control-label">Sling email</label>
                    <div class="col-sm-6">
                        <input type="email" name="email" class="form-control" value="{{ $email ?? '' }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label">Sling password</label>
                    <div class="col-sm-6">
                        <input type="password" name="password" class="form-control" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-6">
                        <button type="submit" class="btn btn-default"><i class="fa fa-sign-in"></i> Try email + password</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>
@endsection
