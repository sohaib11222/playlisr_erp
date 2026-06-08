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
            @if(!empty($listingReadiness['seller_connected']))
            <div class="box box-default" style="margin-top:16px; margin-bottom:0;">
                <div class="box-header with-border"><h4 class="box-title" style="font-size:15px;">Inventory API location</h4></div>
                <div class="box-body">
                    <p class="text-muted" style="margin-top:0;">
                        ERP listings need an <strong>Inventory API warehouse per store</strong> (Pico, Hollywood) — separate from business policies.
                        Each product lists from the store where it has stock / is assigned.
                    </p>
                    @if(!empty($listingReadiness['erp_locations']))
                        <table class="table table-condensed table-bordered" style="max-width:560px; margin-bottom:12px;">
                            <thead><tr><th>ERP store</th><th>eBay location key</th></tr></thead>
                            <tbody>
                                @foreach($listingReadiness['erp_locations'] as $store)
                                    <tr>
                                        <td>{{ $store['name'] }}</td>
                                        <td><code>{{ $store['merchant_location_key'] }}</code></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    <button type="button" class="btn btn-default" id="btn_check_ebay_locations">
                        <i class="fa fa-search"></i> Check inventory locations
                    </button>
                    <button type="button" class="btn btn-primary" id="btn_create_ebay_location" style="margin-left:8px;">
                        <i class="fa fa-plus"></i> Create store warehouse locations
                    </button>
                    <div id="ebay_location_result" style="margin-top:12px; display:none;">
                        <pre class="bg-light" style="padding:10px; border-radius:4px; white-space:pre-wrap; margin:0; max-height:240px; overflow:auto;"></pre>
                    </div>
                    @if(!empty($listingReadiness['locations']))
                        <p class="text-success" style="margin-top:12px; margin-bottom:0;">
                            <i class="fa fa-check"></i>
                            On file: {{ count($listingReadiness['locations']) }} location(s)
                            @foreach($listingReadiness['locations'] as $loc)
                                — <code>{{ $loc['merchantLocationKey'] ?? '?' }}</code>
                            @endforeach
                        </p>
                    @endif
                </div>
            </div>
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

@if(!empty($listingReadiness['seller_connected']))
@section('javascript')
<script>
$(function () {
    var $panel = $('#ebay_location_result');
    var $pre = $panel.find('pre');

    function showResult(text, isError) {
        $pre.text(text);
        $panel.show();
        $pre.css('color', isError ? '#a94442' : '#333');
    }

    $('#btn_check_ebay_locations').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        showResult('Checking eBay inventory locations…', false);
        $.getJSON('{{ url('/admin/ebay-seller/inventory-location/check') }}')
            .done(function (res) {
                if (!res || !res.success) {
                    showResult((res && res.msg) ? res.msg : 'Check failed.', true);
                    return;
                }
                if (!res.locations || !res.locations.length) {
                    showResult('No inventory locations found.\n\nClick "Create store warehouse locations" to add Pico + Hollywood.', true);
                    return;
                }
                var lines = ['Found ' + res.locations.length + ' location(s):\n'];
                res.locations.forEach(function (loc, i) {
                    lines.push((i + 1) + '. Key: ' + (loc.merchantLocationKey || '?'));
                    if (loc.name) lines.push('   Name: ' + loc.name);
                    if (loc.locationTypes) lines.push('   Types: ' + loc.locationTypes.join(', '));
                    if (loc.merchantLocationStatus) lines.push('   Status: ' + loc.merchantLocationStatus);
                });
                showResult(lines.join('\n'), false);
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : (xhr.statusText || 'Request failed');
                showResult(msg, true);
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    $('#btn_create_ebay_location').on('click', function () {
        if (!confirm('Create eBay warehouse locations for each ERP store (Pico + Hollywood)? Uses each store\'s address from Business Locations.')) {
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true);
        showResult('Creating inventory location…', false);
        $.ajax({
            url: '{{ url('/admin/ebay-seller/inventory-location/create') }}',
            method: 'POST',
            data: { _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function (res) {
            showResult((res && res.msg) ? res.msg : 'Done.', false);
            setTimeout(function () { window.location.reload(); }, 1500);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : (xhr.statusText || 'Create failed');
            showResult(msg, true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
});
</script>
@endsection
@endif
@stop
