@extends('layouts.app')
@section('title', 'eBay Seller Connection')

@section('content')
<section class="content-header">
    <h1>eBay Seller Connection <small>authorise the ERP to read your eBay orders</small></h1>
</section>

<section class="content">

    @if(session('status'))
        @php $st = session('status'); @endphp
        <div class="alert alert-{{ $st['type'] === 'success' ? 'success' : 'danger' }}">{{ $st['msg'] }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Status</h3></div>
        <div class="box-body">
            @if(!$configured)
                <p class="text-danger">
                    <i class="fa fa-exclamation-triangle"></i>
                    eBay app credentials are missing. Set <code>app_id</code>, <code>cert_id</code>, and <code>dev_id</code>
                    under Business Settings → Integrations before connecting.
                </p>
            @elseif(!$connected)
                <p>The ERP has eBay app credentials but no seller refresh token yet. Click below to authorise — eBay will redirect you to its consent page, then back here.</p>
                <a class="btn btn-primary" href="{{ url('/admin/ebay-seller/connect') }}">
                    <i class="fa fa-link"></i> Connect eBay Seller Account
                </a>
            @else
                <p class="text-success"><i class="fa fa-check-circle"></i> <strong>Connected.</strong></p>
                <table class="table table-bordered" style="max-width:600px;">
                    <tr><th>Environment</th><td>{{ $environment }}</td></tr>
                    <tr><th>Connected at</th><td>{{ $seller['connected_at'] ?? '—' }}</td></tr>
                    <tr><th>Refresh token expires</th><td>{{ $seller['refresh_token_expires_at'] ?? '—' }}</td></tr>
                    <tr><th>Access token expires</th><td>{{ $seller['access_token_expires_at'] ?? '—' }}</td></tr>
                </table>
                <form method="POST" action="{{ url('/admin/ebay-seller/disconnect') }}" onsubmit="return confirm('Disconnect — clear stored eBay seller tokens?');">
                    @csrf
                    <button type="submit" class="btn btn-default"><i class="fa fa-unlink"></i> Disconnect</button>
                </form>
                <p class="text-muted" style="margin-top:12px;">Re-connect any time to refresh — tokens last ~18 months.</p>
            @endif
        </div>
    </div>

    @if($configured)
    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">eBay Developer Console setup</h3></div>
        <div class="box-body">
            <p>If this is the first time, you need to whitelist this redirect URL in your eBay app:</p>
            <ol>
                <li>Go to <a href="https://developer.ebay.com/my/keys" target="_blank">developer.ebay.com/my/keys</a> → your app → <em>User Tokens</em></li>
                <li>Add this as the "Your auth accepted URL":<br>
                    <code style="background:#f1f5f9; padding:4px 6px; border-radius:4px;">{{ url('/admin/ebay-seller/callback') }}</code>
                </li>
                <li>Save the RuName eBay generates and add it under Business Settings → Integrations → eBay → <code>ru_name</code> (production only — sandbox can use the URL directly).</li>
            </ol>
        </div>
    </div>
    @endif

</section>
@stop
