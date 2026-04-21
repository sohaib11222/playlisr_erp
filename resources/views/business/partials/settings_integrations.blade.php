<div class="pos-tab-content">
    <h4>API Integrations</h4>
    <p class="help-block">Configure API credentials for third-party integrations. Features will be hidden if credentials are not set.</p>
    
    @php
        $business_id = request()->session()->get('user.business_id');
        $businessUtil = new \App\Utils\BusinessUtil();
        $api_settings = $businessUtil->getApiSettings($business_id);
        $quickbooks_connection = \App\QuickBooksConnection::where('business_id', $business_id)->first();
        
        // Load business object if not already available
        if (!isset($business)) {
            $business = \App\Business::find($business_id);
        }
    @endphp

    <!-- QuickBooks Integration -->
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">QuickBooks Online Integration</h3>
        </div>
        <div class="box-body">
            <p class="help-block">
                Connect QuickBooks Online via OAuth2 to sync accounting data from ERP.
                Use the callback URL shown below when configuring your Intuit app.
            </p>

            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][client_id]', 'Client ID:') !!}
                        {!! Form::text('api_settings[quickbooks][client_id]',
                            !empty($api_settings['quickbooks']['client_id']) ? $api_settings['quickbooks']['client_id'] : null,
                            ['class' => 'form-control', 'placeholder' => 'Enter QuickBooks Client ID']) !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][client_secret]', 'Client Secret:') !!}
                        {!! Form::text('api_settings[quickbooks][client_secret]',
                            !empty($api_settings['quickbooks']['client_secret']) ? $api_settings['quickbooks']['client_secret'] : null,
                            ['class' => 'form-control', 'placeholder' => 'Enter QuickBooks Client Secret']) !!}
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][environment]', 'Environment:') !!}
                        {!! Form::select('api_settings[quickbooks][environment]',
                            ['sandbox' => 'Sandbox', 'production' => 'Production'],
                            !empty($api_settings['quickbooks']['environment']) ? $api_settings['quickbooks']['environment'] : 'production',
                            ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][redirect_uri]', 'Redirect URI (optional override):') !!}
                        {!! Form::text('api_settings[quickbooks][redirect_uri]',
                            !empty($api_settings['quickbooks']['redirect_uri']) ? $api_settings['quickbooks']['redirect_uri'] : action('QuickBooksController@callback'),
                            ['class' => 'form-control', 'placeholder' => action('QuickBooksController@callback')]) !!}
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][default_sales_item_name]', 'Default sales item name (QuickBooks):') !!}
                        {!! Form::text('api_settings[quickbooks][default_sales_item_name]',
                            !empty($api_settings['quickbooks']['default_sales_item_name']) ? $api_settings['quickbooks']['default_sales_item_name'] : 'Playlist ERP Sales Sync',
                            ['class' => 'form-control', 'placeholder' => 'Playlist ERP Sales Sync']) !!}
                        <p class="help-block">After you connect QuickBooks, we create (or find) this item and save its ID below. Change the name only if it conflicts with an existing QBO product.</p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][default_sales_item_id]', 'Default Sales Item ID (QuickBooks):') !!}
                        {!! Form::text('api_settings[quickbooks][default_sales_item_id]',
                            !empty($api_settings['quickbooks']['default_sales_item_id']) ? $api_settings['quickbooks']['default_sales_item_id'] : null,
                            ['class' => 'form-control', 'placeholder' => 'Filled automatically after connect']) !!}
                        <p class="help-block">Internal QuickBooks item ID used for synced sale lines. Usually left blank until you connect; then it is set automatically.</p>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][cogs_account_id]', 'COGS account ID (optional):') !!}
                        {!! Form::text('api_settings[quickbooks][cogs_account_id]',
                            !empty($api_settings['quickbooks']['cogs_account_id']) ? $api_settings['quickbooks']['cogs_account_id'] : null,
                            ['class' => 'form-control', 'placeholder' => 'QuickBooks account Id']) !!}
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][inventory_asset_account_id]', 'Inventory asset account ID (optional):') !!}
                        {!! Form::text('api_settings[quickbooks][inventory_asset_account_id]',
                            !empty($api_settings['quickbooks']['inventory_asset_account_id']) ? $api_settings['quickbooks']['inventory_asset_account_id'] : null,
                            ['class' => 'form-control', 'placeholder' => 'QuickBooks account Id']) !!}
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('api_settings[quickbooks][default_deposit_account_id]', 'Default deposit account ID (optional):') !!}
                        {!! Form::text('api_settings[quickbooks][default_deposit_account_id]',
                            !empty($api_settings['quickbooks']['default_deposit_account_id']) ? $api_settings['quickbooks']['default_deposit_account_id'] : null,
                            ['class' => 'form-control', 'placeholder' => 'Fallback for payments']) !!}
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        <label>Manual Sync (single sale)</label>
                        <div class="input-group">
                            <input type="number" min="1" class="form-control" id="quickbooks_transaction_id" placeholder="ERP Transaction ID">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-success" id="sync_quickbooks_sale_btn">
                                    <i class="fa fa-refresh"></i> Sync Sale
                                </button>
                            </span>
                        </div>
                        <p class="help-block">Pilot sync tool: push one finalized ERP sale to QuickBooks.</p>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <strong>OAuth Callback URL:</strong>
                <code>{{ action('QuickBooksController@callback') }}</code>
            </div>

            @if(!empty($quickbooks_connection) && $quickbooks_connection->is_active)
                <div class="alert alert-success">
                    <strong>Status:</strong> Connected
                    @if(!empty($quickbooks_connection->realm_id))
                        | <strong>Realm ID:</strong> {{ $quickbooks_connection->realm_id }}
                    @endif
                    @if(!empty($quickbooks_connection->token_expires_at))
                        | <strong>Token Expires:</strong> {{ $quickbooks_connection->token_expires_at }}
                    @endif
                </div>
            @else
                <div class="alert alert-warning">
                    <strong>Status:</strong> Not connected
                </div>
            @endif

            <div class="row">
                <div class="col-sm-12">
                    <a href="{{ action('QuickBooksController@connect') }}" class="btn btn-primary" id="connect_quickbooks_btn">
                        <i class="fa fa-link"></i> Connect QuickBooks
                    </a>
                    <button type="button" class="btn btn-info" id="test_quickbooks_connection_btn" style="margin-left: 8px;">
                        <i class="fa fa-plug"></i> Test Connection
                    </button>
                    <button type="button" class="btn btn-danger" id="disconnect_quickbooks_btn" style="margin-left: 8px;">
                        <i class="fa fa-unlink"></i> Disconnect
                    </button>
                    <a href="{{ action('QuickBooksController@dashboard') }}" class="btn btn-default" style="margin-left: 8px;">
                        <i class="fa fa-list"></i> Sync Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Clover POS Integration -->
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Clover POS Integration</h3>
        </div>
        <div class="box-body">
            <div class="alert alert-info">
                <strong>Ecommerce API Tokens Method (Recommended):</strong> Use Public Token and Private Token from Clover Dashboard > Setup > API Tokens > Ecommerce API Tokens
            </div>
            
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][public_token]', 'Public Token:') !!}
                        {!! Form::text('api_settings[clover][public_token]', 
                            !empty($api_settings['clover']['public_token']) ? $api_settings['clover']['public_token'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Public Token from Ecommerce API Tokens']) !!}
                        <p class="help-block">From Clover Dashboard > Setup > API Tokens > Ecommerce API Tokens</p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][private_token]', 'Private Token:') !!}
                        {!! Form::text('api_settings[clover][private_token]', 
                            !empty($api_settings['clover']['private_token']) ? $api_settings['clover']['private_token'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Private Token from Ecommerce API Tokens', 'type' => 'password']) !!}
                        <p class="help-block">Click the eye icon in Clover to reveal the private token</p>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][merchant_id]', 'Merchant ID:') !!}
                        {!! Form::text('api_settings[clover][merchant_id]', 
                            !empty($api_settings['clover']['merchant_id']) ? $api_settings['clover']['merchant_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Merchant ID']) !!}
                        <p class="help-block">Found in Clover Dashboard URL: /m/{merchantId}/</p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][environment]', 'Environment:') !!}
                        {!! Form::select('api_settings[clover][environment]', 
                            ['sandbox' => 'Sandbox', 'production' => 'Production'], 
                            !empty($api_settings['clover']['environment']) ? $api_settings['clover']['environment'] : 'production', 
                            ['class' => 'form-control']) !!}
                    </div>
                </div>
            </div>
            
            <hr>
            <h5>Alternative: OAuth Method (Advanced)</h5>
            <p class="help-block">Only use if you need OAuth-based authentication instead of Ecommerce API Tokens</p>
            
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][app_id]', 'App ID (OAuth):') !!}
                        {!! Form::text('api_settings[clover][app_id]', 
                            !empty($api_settings['clover']['app_id']) ? $api_settings['clover']['app_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Clover App ID (OAuth)']) !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][app_secret]', 'App Secret (OAuth):') !!}
                        {!! Form::text('api_settings[clover][app_secret]', 
                            !empty($api_settings['clover']['app_secret']) ? $api_settings['clover']['app_secret'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Clover App Secret (OAuth)']) !!}
                    </div>
                </div>
            </div>
            
            <hr>
            <h5>Per-Location Clover (multi-store)</h5>
            <p class="help-block">
                If you run multiple stores with separate Clover merchant accounts (Hollywood + Pico, for
                example), add each location's Merchant ID and Private Token below. When a cashier closes
                their register, the ERP auto-fills "Total card slips" using the per-location creds for
                that register. The single-merchant fields above are only used as a fallback when a
                location isn't configured here.
            </p>
            @php
                $locationsForClover = \App\BusinessLocation::where('business_id', $business_id)
                    ->where('is_active', 1)
                    ->orderBy('name')
                    ->get();
                $perLocationClover = $api_settings['clover']['locations'] ?? [];
            @endphp
            @if($locationsForClover->isEmpty())
                <p class="text-muted">No active business locations — add locations under <strong>Business Settings → Locations</strong> first.</p>
            @else
                @foreach($locationsForClover as $loc)
                    @php
                        $locCreds = $perLocationClover[$loc->id] ?? [];
                    @endphp
                    <div class="row" style="background:#fafafa; border:1px solid #eee; border-radius:6px; padding:10px; margin:8px 0;">
                        <div class="col-sm-12" style="margin-bottom:6px;">
                            <strong><i class="fa fa-map-marker-alt"></i> {{ $loc->name }}</strong>
                            <small class="text-muted">· location id {{ $loc->id }}</small>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                {!! Form::label("api_settings[clover][locations][{$loc->id}][merchant_id]", 'Merchant ID:') !!}
                                {!! Form::text("api_settings[clover][locations][{$loc->id}][merchant_id]",
                                    $locCreds['merchant_id'] ?? null,
                                    ['class' => 'form-control', 'placeholder' => 'Clover merchant ID']) !!}
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                {!! Form::label("api_settings[clover][locations][{$loc->id}][private_token]", 'Private Token:') !!}
                                {!! Form::text("api_settings[clover][locations][{$loc->id}][private_token]",
                                    $locCreds['private_token'] ?? null,
                                    ['class' => 'form-control', 'placeholder' => 'Ecommerce Private Token', 'type' => 'password']) !!}
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                {!! Form::label("api_settings[clover][locations][{$loc->id}][environment]", 'Environment:') !!}
                                {!! Form::select("api_settings[clover][locations][{$loc->id}][environment]",
                                    ['sandbox' => 'Sandbox', 'production' => 'Production'],
                                    $locCreds['environment'] ?? 'production',
                                    ['class' => 'form-control']) !!}
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif

            <hr>
            <h5>Customer Import</h5>
            <p class="help-block">Import customers from Clover POS to your ERP system</p>
            <div class="row">
                <div class="col-sm-12">
                    <button type="button" class="btn btn-primary" id="test_clover_connection_btn">
                        <i class="fa fa-plug"></i> Test Connection
                    </button>
                    <button type="button" class="btn btn-success" id="import_clover_customers_btn" style="margin-left: 10px;">
                        <i class="fa fa-download"></i> Import Customers from Clover
                    </button>
                </div>
            </div>
            <div id="clover_connection_status" style="margin-top: 10px;"></div>
        </div>
    </div>

    <!-- Clover Customer Import Modal -->
    <div class="modal fade" id="clover_import_modal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">Import Customers from Clover</h4>
                </div>
                <div class="modal-body">
                    <div id="clover_import_loading" style="text-align: center; padding: 20px; display: none;">
                        <i class="fa fa-spinner fa-spin fa-2x"></i>
                        <p>Loading customers...</p>
                    </div>
                    <div id="clover_import_content" style="display: none;">
                        <div class="alert alert-info">
                            <strong>Note:</strong> Customers with matching email or phone will be skipped (duplicates).
                        </div>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-bordered table-striped" id="clover_customers_preview">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="select_all_clover_customers"></th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Address</th>
                                    </tr>
                                </thead>
                                <tbody id="clover_customers_list">
                                </tbody>
                            </table>
                        </div>
                        <div id="clover_import_pagination" style="margin-top: 10px;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirm_import_clover_customers" disabled>
                        <i class="fa fa-download"></i> Import Selected
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- eBay Integration -->
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">eBay Integration</h3>
        </div>
        <div class="box-body">
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[ebay][app_id]', 'App ID (Client ID):') !!}
                        {!! Form::text('api_settings[ebay][app_id]', 
                            !empty($api_settings['ebay']['app_id']) ? $api_settings['ebay']['app_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter eBay App ID']) !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[ebay][cert_id]', 'Cert ID (Client Secret):') !!}
                        {!! Form::text('api_settings[ebay][cert_id]', 
                            !empty($api_settings['ebay']['cert_id']) ? $api_settings['ebay']['cert_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter eBay Cert ID']) !!}
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[ebay][dev_id]', 'Dev ID:') !!}
                        {!! Form::text('api_settings[ebay][dev_id]', 
                            !empty($api_settings['ebay']['dev_id']) ? $api_settings['ebay']['dev_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter eBay Dev ID']) !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[ebay][environment]', 'Environment:') !!}
                        {!! Form::select('api_settings[ebay][environment]', 
                            ['sandbox' => 'Sandbox', 'production' => 'Production'], 
                            !empty($api_settings['ebay']['environment']) ? $api_settings['ebay']['environment'] : 'sandbox', 
                            ['class' => 'form-control']) !!}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Discogs Integration -->
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Discogs Integration</h3>
        </div>
        <div class="box-body">
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[discogs][token]', 'API Token:') !!}
                        {!! Form::text('api_settings[discogs][token]', 
                            !empty($api_settings['discogs']['token']) ? $api_settings['discogs']['token'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Discogs API Token']) !!}
                        <p class="help-block">Get your token from <a href="https://www.discogs.com/settings/developers" target="_blank">Discogs Developer Settings</a></p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[discogs][user_token]', 'User Token (Optional):') !!}
                        {!! Form::text('api_settings[discogs][user_token]', 
                            !empty($api_settings['discogs']['user_token']) ? $api_settings['discogs']['user_token'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter User Token if needed']) !!}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Streetpulse Integration -->
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">StreetPulse Integration</h3>
        </div>
        <div class="box-body">
            <div class="alert alert-info">
                <strong>FTP Upload Method:</strong> Daily sales data is automatically uploaded via FTP to StreetPulse servers. Files are generated in SPULSE02 format.
                <br><br>
                <strong>Multi-Location Support:</strong> You can configure a StreetPulse acronym for each location individually. Go to <strong>Business Settings > Locations</strong> and edit each location to set its StreetPulse acronym. If you have multiple locations, each will upload separately with its own acronym.
            </div>
            
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('streetpulse_acronym', 'StreetPulse Store Acronym (Business-Level):') !!}
                        {!! Form::text('streetpulse_acronym', 
                            !empty($business->streetpulse_acronym) ? $business->streetpulse_acronym : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter 3-4 character acronym (e.g., WSQ)', 'maxlength' => 10]) !!}
                        <p class="help-block">3-4 character acronym assigned by StreetPulse (e.g., WSQ, BQ01). <strong>Note:</strong> For multi-location businesses, configure acronyms per location in Business Settings > Locations.</p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[streetpulse][check_digit_option]', 'Check Digit Option:') !!}
                        {!! Form::select('api_settings[streetpulse][check_digit_option]', 
                            ['NOCHECKDIGIT' => 'NOCHECKDIGIT (Remove check digit)', 'CHECKDIGIT' => 'CHECKDIGIT (Keep check digit)'], 
                            !empty($api_settings['streetpulse']['check_digit_option']) ? $api_settings['streetpulse']['check_digit_option'] : 'NOCHECKDIGIT', 
                            ['class' => 'form-control']) !!}
                        <p class="help-block">Whether UPCs include check digit (last digit)</p>
                    </div>
                </div>
            </div>
            
            @if(!empty($business->streetpulse_last_upload_date))
            <div class="row">
                <div class="col-sm-12">
                    <div class="alert alert-success">
                        <strong>Last Upload:</strong> {{ \Carbon\Carbon::parse($business->streetpulse_last_upload_date)->format('F j, Y') }}
                    </div>
                </div>
            </div>
            @endif
            
            <div class="row">
                <div class="col-sm-12">
                    <div class="form-group">
                        <label for="streetpulse_upload_date">Upload Date (for manual upload):</label>
                        <input type="date" id="streetpulse_upload_date" class="form-control" value="{{ date('Y-m-d', strtotime('-1 day')) }}" style="max-width: 200px; display: inline-block;">
                        <p class="help-block">Select date to upload (defaults to yesterday). Data for the selected date will be uploaded.</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-sm-12">
                    <button type="button" class="btn btn-info" id="test_streetpulse_connection">Test FTP Connection</button>
                    <button type="button" class="btn btn-success" id="sync_streetpulse_now">Upload Selected Date</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    // Wait for jQuery to be available
    if (typeof jQuery !== 'undefined') {
        (function($) {
            $(document).ready(function() {
                // Test Streetpulse connection
                $('#test_streetpulse_connection').on('click', function() {
                    $.ajax({
                        url: '/business/test-streetpulse-connection',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            if (response.success) {
                                toastr.success('Connection successful!');
                            } else {
                                toastr.error(response.msg || 'Connection failed');
                            }
                        },
                        error: function() {
                            toastr.error('Error testing connection');
                        }
                    });
                });

                // Sync Streetpulse now
                $('#sync_streetpulse_now').on('click', function() {
                    var uploadDate = $('#streetpulse_upload_date').val();
                    if (!uploadDate) {
                        toastr.error('Please select a date to upload');
                        return;
                    }
                    
                    if (confirm('Are you sure you want to upload StreetPulse data for ' + uploadDate + '?')) {
                        $.ajax({
                            url: '/business/sync-streetpulse',
                            method: 'POST',
                            data: {
                                _token: '{{ csrf_token() }}',
                                date: uploadDate
                            },
                            beforeSend: function() {
                                $('#sync_streetpulse_now').prop('disabled', true).text('Uploading...');
                            },
                            success: function(response) {
                                $('#sync_streetpulse_now').prop('disabled', false).text('Upload Selected Date');
                                if (response.success) {
                                    toastr.success(response.msg || 'Upload completed successfully!');
                                    // Reload page to show updated last upload date
                                    setTimeout(function() {
                                        location.reload();
                                    }, 2000);
                                } else {
                                    toastr.error(response.msg || 'Upload failed');
                                }
                            },
                            error: function() {
                                $('#sync_streetpulse_now').prop('disabled', false).text('Upload Selected Date');
                                toastr.error('Error uploading to StreetPulse');
                            }
                        });
                    }
                });
            });
        })(jQuery);
    } else {
        // Fallback: wait for jQuery to load
        window.addEventListener('load', function() {
            if (typeof jQuery !== 'undefined') {
                (function($) {
                    $(document).ready(function() {
                        $('#test_streetpulse_connection').on('click', function() {
                            $.ajax({
                                url: '/business/test-streetpulse-connection',
                                method: 'POST',
                                data: { _token: '{{ csrf_token() }}' },
                                success: function(response) {
                                    if (response.success) {
                                        toastr.success('Connection successful!');
                                    } else {
                                        toastr.error(response.msg || 'Connection failed');
                                    }
                                },
                                error: function() {
                                    toastr.error('Error testing connection');
                                }
                            });
                        });

                        $('#sync_streetpulse_now').on('click', function() {
                            var uploadDate = $('#streetpulse_upload_date').val();
                            if (!uploadDate) {
                                toastr.error('Please select a date to upload');
                                return;
                            }
                            
                            if (confirm('Are you sure you want to upload StreetPulse data for ' + uploadDate + '?')) {
                                $.ajax({
                                    url: '/business/sync-streetpulse',
                                    method: 'POST',
                                    data: {
                                        _token: '{{ csrf_token() }}',
                                        date: uploadDate
                                    },
                                    beforeSend: function() {
                                        $('#sync_streetpulse_now').prop('disabled', true).text('Uploading...');
                                    },
                                    success: function(response) {
                                        $('#sync_streetpulse_now').prop('disabled', false).text('Upload Selected Date');
                                        if (response.success) {
                                            toastr.success(response.msg || 'Upload completed successfully!');
                                            setTimeout(function() {
                                                location.reload();
                                            }, 2000);
                                        } else {
                                            toastr.error(response.msg || 'Upload failed');
                                        }
                                    },
                                    error: function() {
                                        $('#sync_streetpulse_now').prop('disabled', false).text('Upload Selected Date');
                                        toastr.error('Error uploading to StreetPulse');
                                    }
                                });
                            }
                        });
                    });
                })(jQuery);
            }
        });
    }
</script>

<script type="text/javascript">
    (function() {
    function initIntegrationHandlers($) {
        // Test QuickBooks connection
        $('#test_quickbooks_connection_btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');

            $.ajax({
                url: '{{ action("QuickBooksController@testConnection") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                dataType: 'json',
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
                    if (response.success) {
                        var companyName = response.company && response.company.name ? response.company.name : 'Connected';
                        toastr.success('QuickBooks connected: ' + companyName);
                    } else {
                        toastr.error(response.msg || 'QuickBooks test failed.');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
                    toastr.error('QuickBooks test failed.');
                }
            });
        });

        // Sync one sale to QuickBooks
        $('#sync_quickbooks_sale_btn').on('click', function() {
            var transactionId = $('#quickbooks_transaction_id').val();
            if (!transactionId) {
                toastr.error('Please enter ERP Transaction ID first.');
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing...');

            $.ajax({
                url: '{{ action("QuickBooksController@syncSale") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    transaction_id: transactionId
                },
                dataType: 'json',
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-refresh"></i> Sync Sale');
                    if (response.success) {
                        toastr.success(response.msg || 'Sale synced to QuickBooks.');
                    } else {
                        toastr.error(response.msg || 'QuickBooks sale sync failed.');
                    }
                },
                error: function(xhr) {
                    btn.prop('disabled', false).html('<i class="fa fa-refresh"></i> Sync Sale');
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        toastr.error(xhr.responseJSON.message);
                    } else {
                        toastr.error('QuickBooks sale sync failed.');
                    }
                }
            });
        });

        // Disconnect QuickBooks
        $('#disconnect_quickbooks_btn').on('click', function() {
            if (!confirm('Disconnect QuickBooks for this business?')) {
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true);

            $.ajax({
                url: '{{ action("QuickBooksController@disconnect") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                dataType: 'json',
                success: function(response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        toastr.success(response.msg || 'QuickBooks disconnected.');
                        setTimeout(function() {
                            location.reload();
                        }, 600);
                    } else {
                        toastr.error(response.msg || 'Unable to disconnect QuickBooks.');
                    }
                },
                error: function() {
                    btn.prop('disabled', false);
                    toastr.error('Unable to disconnect QuickBooks.');
                }
            });
        });

        // Test Clover Connection
        $('#test_clover_connection_btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');
            $('#clover_connection_status').html('');
            
            $.ajax({
                url: '/business/test-clover-connection',
                method: 'POST',
                data: { _token: '{{ csrf_token() }}' },
                dataType: 'json',
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
                    if (response.success) {
                        $('#clover_connection_status').html(
                            '<div class="alert alert-success"><i class="fa fa-check"></i> Connection successful! Found ' + 
                            (response.total || 0) + ' customers in Clover.</div>'
                        );
                    } else {
                        $('#clover_connection_status').html(
                            '<div class="alert alert-danger"><i class="fa fa-times"></i> ' + (response.msg || 'Connection failed') + '</div>'
                        );
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
                    $('#clover_connection_status').html(
                        '<div class="alert alert-danger"><i class="fa fa-times"></i> Error testing connection</div>'
                    );
                }
            });
        });

        // Open Import Modal
        $('#import_clover_customers_btn').on('click', function() {
            $('#clover_import_modal').modal('show');
            loadCloverCustomersPreview();
        });        // Load Clover Customers Preview
        window.loadCloverCustomersPreview = function(offset = 0) {
            $('#clover_import_loading').show();
            $('#clover_import_content').hide();
            $('#clover_customers_list').empty();
            
            $.ajax({
                url: '/business/preview-clover-customers',
                method: 'GET',
                data: { 
                    _token: '{{ csrf_token() }}',
                    limit: 50,
                    offset: offset
                },
                dataType: 'json',
                success: function(response) {
                    $('#clover_import_loading').hide();
                    
                    if (response.success && response.customers.length > 0) {
                        var html = '';
                        response.customers.forEach(function(customer) {
                            html += '<tr>' +
                                '<td><input type="checkbox" class="clover_customer_checkbox" value="' + customer.id + '"></td>' +
                                '<td>' + (customer.name || 'N/A') + '</td>' +
                                '<td>' + (customer.email || '-') + '</td>' +
                                '<td>' + (customer.phone || '-') + '</td>' +
                                '<td>' + (customer.address || '-') + '</td>' +
                                '</tr>';
                        });
                        $('#clover_customers_list').html(html);
                        $('#clover_import_content').show();
                        
                        // Update pagination if needed
                        if (response.has_more) {
                            $('#clover_import_pagination').html(
                                '<button class="btn btn-sm btn-default" onclick="loadCloverCustomersPreview(' + (offset + 50) + ')">Load More</button>'
                            );
                        } else {
                            $('#clover_import_pagination').html('');
                        }
                    } else {
                        $('#clover_import_content').show();
                        $('#clover_customers_list').html(
                            '<tr><td colspan="5" class="text-center text-muted">No customers found or error: ' + 
                            (response.msg || 'Unknown error') + '</td></tr>'
                        );
                    }
                },
                error: function(xhr) {
                    $('#clover_import_loading').hide();
                    $('#clover_import_content').show();
                    $('#clover_customers_list').html(
                        '<tr><td colspan="5" class="text-center text-danger">Error loading customers. Please check your Clover API credentials.</td></tr>'
                    );
                }
            });
        };

        // Select All Checkbox
        $('#select_all_clover_customers').on('change', function() {
            $('.clover_customer_checkbox').prop('checked', $(this).is(':checked'));
            updateImportButton();
        });

        // Individual Checkbox Change
        $(document).on('change', '.clover_customer_checkbox', function() {
            updateImportButton();
        });

        // Update Import Button State
        function updateImportButton() {
            var checked = $('.clover_customer_checkbox:checked').length;
            $('#confirm_import_clover_customers').prop('disabled', checked === 0);
        }

        // Confirm Import
        $('#confirm_import_clover_customers').on('click', function() {
            var selectedIds = [];
            $('.clover_customer_checkbox:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (selectedIds.length === 0) {
                toastr.warning('Please select at least one customer to import');
                return;
            }
            
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Importing...');
            
            $.ajax({
                url: '/business/import-clover-customers',
                method: 'POST',
                data: { 
                    _token: '{{ csrf_token() }}',
                    customer_ids: selectedIds
                },
                dataType: 'json',
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-download"></i> Import Selected');
                    
                    if (response.success) {
                        toastr.success(response.msg || 'Customers imported successfully');
                        $('#clover_import_modal').modal('hide');
                    } else {
                        toastr.error(response.msg || 'Import failed');
                    }
                },
                error: function(xhr) {
                    btn.prop('disabled', false).html('<i class="fa fa-download"></i> Import Selected');
                    toastr.error('Error importing customers. Please try again.');
                }
            });
        });
    }

    if (typeof jQuery !== 'undefined') {
        initIntegrationHandlers(jQuery);
    } else {
        window.addEventListener('load', function() {
            if (typeof jQuery !== 'undefined') {
                initIntegrationHandlers(jQuery);
            }
        });
    }
    })();
</script>
