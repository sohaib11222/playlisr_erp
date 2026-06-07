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
                    under Business Settings → Integrations first.
                </p>
            @elseif($environment === 'production' && empty($ru_name))
                <p class="text-danger">
                    <i class="fa fa-exclamation-triangle"></i>
                    <strong>Production</strong> is selected but <strong>RuName</strong> is empty. eBay will return
                    <code>invalid_request</code> if you connect without it.
                </p>
                <p>Go to Business Settings → Integrations → eBay → paste your RuName (from eBay Developer → User Tokens), Save, then return here.</p>
            @elseif(!$connected)
                <p>The ERP has eBay app credentials but no seller refresh token yet. Click below to authorise — eBay will redirect you to its consent page, then back here.</p>
                <p class="text-muted">
                    Environment: <strong>{{ $environment }}</strong>
                    @if(!empty($ru_name))
                        · RuName: <code>{{ $ru_name }}</code>
                    @endif
                </p>
                @if($oauthReady)
                    <a class="btn btn-primary" href="{{ url('/admin/ebay-seller/connect') }}">
                        <i class="fa fa-link"></i> Connect eBay Seller Account
                    </a>
                @else
                    <button class="btn btn-primary" disabled>Connect eBay Seller Account</button>
                @endif
            @else
                <p class="text-success"><i class="fa fa-check-circle"></i> <strong>Connected.</strong></p>
                <table class="table table-bordered" style="max-width:600px;">
                    <tr><th>Environment</th><td>{{ $environment }}</td></tr>
                    <tr><th>RuName</th><td><code>{{ $ru_name ?: '—' }}</code></td></tr>
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
    <div class="box box-{{ !empty($listingReadiness['seller_connected']) && !empty($listingReadiness['policies_ok']) && !empty($listingReadiness['location_ok']) ? 'success' : 'warning' }}">
        <div class="box-header with-border"><h3 class="box-title">Listing readiness</h3></div>
        <div class="box-body">
            <table class="table table-bordered" style="max-width:700px;">
                <tr>
                    <th>App credentials</th>
                    <td>{!! !empty($listingReadiness['configured']) ? '<span class="text-success"><i class="fa fa-check"></i> OK</span>' : '<span class="text-danger">Missing</span>' !!}</td>
                </tr>
                <tr>
                    <th>OAuth RuName</th>
                    <td>{!! !empty($listingReadiness['oauth_ready']) ? '<span class="text-success"><i class="fa fa-check"></i> OK</span>' : '<span class="text-danger">RuName required for Production</span>' !!}</td>
                </tr>
                <tr>
                    <th>Seller connected</th>
                    <td>{!! !empty($listingReadiness['seller_connected']) ? '<span class="text-success"><i class="fa fa-check"></i> Connected</span>' : '<span class="text-warning">Not connected</span>' !!}</td>
                </tr>
                <tr>
                    <th>Business policies</th>
                    <td>{!! !empty($listingReadiness['policies_ok']) ? '<span class="text-success"><i class="fa fa-check"></i> Found</span>' : '<span class="text-warning">Missing or not checked</span>' !!}</td>
                </tr>
                <tr>
                    <th>Inventory location</th>
                    <td>{!! !empty($listingReadiness['location_ok']) ? '<span class="text-success"><i class="fa fa-check"></i> Found</span>' : '<span class="text-warning">Missing or not checked</span>' !!}</td>
                </tr>
                <tr>
                    <th>Default category ID</th>
                    <td>{!! !empty($listingReadiness['default_category_set']) ? '<span class="text-success"><i class="fa fa-check"></i> Set</span>' : '<span class="text-muted">Optional fallback — set in Integrations</span>' !!}</td>
                </tr>
            </table>
            @if(!empty($listingReadiness['errors']))
                <ul class="text-danger" style="margin-bottom:0;">
                    @foreach($listingReadiness['errors'] as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="text-muted" style="margin-top:12px;">
                OAuth scopes include <code>sell.inventory</code> and <code>sell.account</code> for listing.
                Re-connect if you connected before those scopes were added.
            </p>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">eBay Developer Console setup (fixes 400 invalid_request)</h3></div>
        <div class="box-body">
            <p>eBay <strong>Production</strong> OAuth does <em>not</em> use your HTTPS URL as <code>redirect_uri</code>. It uses your <strong>RuName</strong> string.</p>
            <ol>
                <li>Open <a href="https://developer.ebay.com/my/auth?env=production&amp;index=0" target="_blank">eBay Developer → User Tokens (Production)</a></li>
                <li>Copy the <strong>RuName</strong> (example: <code>Sohaib_Ahmad-SohaibAh-Nivess-hqyoql</code>)</li>
                <li>Paste it in <strong>Business Settings → Integrations → eBay → RuName</strong> and click Save</li>
                <li>On the same RuName page, set <strong>Your auth accepted URL</strong> to:<br>
                    <code style="background:#f1f5f9; padding:4px 6px; border-radius:4px;">{{ $callbackUrl }}</code>
                </li>
                <li>Confirm <strong>App ID</strong> in ERP matches Production Client ID (e.g. <code>SohaibAh-Nivessa-PRD-…</code>)</li>
                <li>Confirm <strong>Environment</strong> = <strong>Production</strong> (not Sandbox)</li>
            </ol>
            <p class="text-warning" style="margin-top:12px;">
                <i class="fa fa-info-circle"></i>
                If you still see <code>invalid_request</code>: wrong environment (sandbox keys + production auth), wrong App ID, or RuName not saved in ERP.
            </p>
        </div>
    </div>
    @endif

</section>
@stop
