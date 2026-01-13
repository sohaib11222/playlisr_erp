<div class="pos-tab-content">
    <h4>API Integrations</h4>
    <p class="help-block">Configure API credentials for third-party integrations. Features will be hidden if credentials are not set.</p>
    
    @php
        $business_id = request()->session()->get('user.business_id');
        $businessUtil = new \App\Utils\BusinessUtil();
        $api_settings = $businessUtil->getApiSettings($business_id);
    @endphp

    <!-- Clover POS Integration -->
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Clover POS Integration</h3>
        </div>
        <div class="box-body">
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][app_id]', 'App ID:') !!}
                        {!! Form::text('api_settings[clover][app_id]', 
                            !empty($api_settings['clover']['app_id']) ? $api_settings['clover']['app_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Clover App ID']); !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][app_secret]', 'App Secret:') !!}
                        {!! Form::text('api_settings[clover][app_secret]', 
                            !empty($api_settings['clover']['app_secret']) ? $api_settings['clover']['app_secret'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Clover App Secret']); !!}
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][merchant_id]', 'Merchant ID:') !!}
                        {!! Form::text('api_settings[clover][merchant_id]', 
                            !empty($api_settings['clover']['merchant_id']) ? $api_settings['clover']['merchant_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Merchant ID']); !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][environment]', 'Environment:') !!}
                        {!! Form::select('api_settings[clover][environment]', 
                            ['sandbox' => 'Sandbox', 'production' => 'Production'], 
                            !empty($api_settings['clover']['environment']) ? $api_settings['clover']['environment'] : 'sandbox', 
                            ['class' => 'form-control']); !!}
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('api_settings[clover][access_token]', 'Access Token (Optional):') !!}
                        {!! Form::text('api_settings[clover][access_token]', 
                            !empty($api_settings['clover']['access_token']) ? $api_settings['clover']['access_token'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Leave empty to obtain automatically']); !!}
                        <p class="help-block">Leave empty to obtain automatically via OAuth flow</p>
                    </div>
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
                            ['class' => 'form-control', 'placeholder' => 'Enter eBay App ID']); !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[ebay][cert_id]', 'Cert ID (Client Secret):') !!}
                        {!! Form::text('api_settings[ebay][cert_id]', 
                            !empty($api_settings['ebay']['cert_id']) ? $api_settings['ebay']['cert_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter eBay Cert ID']); !!}
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[ebay][dev_id]', 'Dev ID:') !!}
                        {!! Form::text('api_settings[ebay][dev_id]', 
                            !empty($api_settings['ebay']['dev_id']) ? $api_settings['ebay']['dev_id'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter eBay Dev ID']); !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[ebay][environment]', 'Environment:') !!}
                        {!! Form::select('api_settings[ebay][environment]', 
                            ['sandbox' => 'Sandbox', 'production' => 'Production'], 
                            !empty($api_settings['ebay']['environment']) ? $api_settings['ebay']['environment'] : 'sandbox', 
                            ['class' => 'form-control']); !!}
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
                            ['class' => 'form-control', 'placeholder' => 'Enter Discogs API Token']); !!}
                        <p class="help-block">Get your token from <a href="https://www.discogs.com/settings/developers" target="_blank">Discogs Developer Settings</a></p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('api_settings[discogs][user_token]', 'User Token (Optional):') !!}
                        {!! Form::text('api_settings[discogs][user_token]', 
                            !empty($api_settings['discogs']['user_token']) ? $api_settings['discogs']['user_token'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter User Token if needed']); !!}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Streetpulse Integration -->
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Streetpulse Integration</h3>
        </div>
        <div class="box-body">
            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('api_settings[streetpulse][api_key]', 'API Key:') !!}
                        {!! Form::text('api_settings[streetpulse][api_key]', 
                            !empty($api_settings['streetpulse']['api_key']) ? $api_settings['streetpulse']['api_key'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Streetpulse API Key']); !!}
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('api_settings[streetpulse][endpoint]', 'Endpoint URL:') !!}
                        {!! Form::text('api_settings[streetpulse][endpoint]', 
                            !empty($api_settings['streetpulse']['endpoint']) ? $api_settings['streetpulse']['endpoint'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter API Endpoint URL']); !!}
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('api_settings[streetpulse][username]', 'Username (Optional):') !!}
                        {!! Form::text('api_settings[streetpulse][username]', 
                            !empty($api_settings['streetpulse']['username']) ? $api_settings['streetpulse']['username'] : null, 
                            ['class' => 'form-control', 'placeholder' => 'Enter Username if needed']); !!}
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <button type="button" class="btn btn-info" id="test_streetpulse_connection">Test Connection</button>
                    <button type="button" class="btn btn-success" id="sync_streetpulse_now">Sync Now</button>
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
                    if (confirm('Are you sure you want to sync with Streetpulse now?')) {
                        $.ajax({
                            url: '/business/sync-streetpulse',
                            method: 'POST',
                            data: {
                                _token: '{{ csrf_token() }}'
                            },
                            success: function(response) {
                                if (response.success) {
                                    toastr.success('Sync completed successfully!');
                                } else {
                                    toastr.error(response.msg || 'Sync failed');
                                }
                            },
                            error: function() {
                                toastr.error('Error syncing with Streetpulse');
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
                            if (confirm('Are you sure you want to sync with Streetpulse now?')) {
                                $.ajax({
                                    url: '/business/sync-streetpulse',
                                    method: 'POST',
                                    data: { _token: '{{ csrf_token() }}' },
                                    success: function(response) {
                                        if (response.success) {
                                            toastr.success('Sync completed successfully!');
                                        } else {
                                            toastr.error(response.msg || 'Sync failed');
                                        }
                                    },
                                    error: function() {
                                        toastr.error('Error syncing with Streetpulse');
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

