@extends('layouts.app')
@section('title', __('business.business_settings'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('business.business_settings')</h1>
    <br>
    @include('layouts.partials.search_settings')
</section>

<!-- Main content -->
<section class="content">
{!! Form::open(['url' => action('BusinessController@postBusinessSettings'), 'method' => 'post', 'id' => 'bussiness_edit_form',
           'files' => true ]) !!}
    <div class="row">
        <div class="col-xs-12">
       <!--  <pos-tab-container> -->
        <div class="col-xs-12 pos-tab-container">
            <div class="col-lg-2 col-md-2 col-sm-2 col-xs-2 pos-tab-menu">
                <div class="list-group">
                    <a href="#" class="list-group-item text-center active">@lang('business.business')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.tax') @show_tooltip(__('tooltip.business_tax'))</a>
                    <a href="#" class="list-group-item text-center">@lang('business.product')</a>
                    <a href="#" class="list-group-item text-center">@lang('contact.contact')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.sale')</a>
                    <a href="#" class="list-group-item text-center">@lang('sale.pos_sale')</a>
                    <a href="#" class="list-group-item text-center">@lang('purchase.purchases')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.payment')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.dashboard')</a>
                    <a href="#" class="list-group-item text-center">@lang('business.system')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.prefixes')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.email_settings')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.sms_settings')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.reward_point_settings')</a>
                    <a href="#" class="list-group-item text-center">Gift Cards & Loyalty</a>
                    <a href="#" class="list-group-item text-center">Integrations</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.modules')</a>
                    <a href="#" class="list-group-item text-center">@lang('lang_v1.custom_labels')</a>
                    <a href="#" id="tab-trigger-data-tools" class="list-group-item text-center">Data Tools</a>
                </div>
            </div>
            <div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 pos-tab">
                <!-- tab 1 start -->
                @include('business.partials.settings_business')
                <!-- tab 1 end -->
                <!-- tab 2 start -->
                @include('business.partials.settings_tax')
                <!-- tab 2 end -->
                <!-- tab 3 start -->
                @include('business.partials.settings_product')

                @include('business.partials.settings_contact')
                <!-- tab 3 end -->
                <!-- tab 4 start -->
                @include('business.partials.settings_sales')
                @include('business.partials.settings_pos')
                <!-- tab 4 end -->
                <!-- tab 5 start -->
                @include('business.partials.settings_purchase')

                @include('business.partials.settings_payment')
                <!-- tab 5 end -->
                <!-- tab 6 start -->
                @include('business.partials.settings_dashboard')
                <!-- tab 6 end -->
                <!-- tab 7 start -->
                @include('business.partials.settings_system')
                <!-- tab 7 end -->
                <!-- tab 8 start -->
                @include('business.partials.settings_prefixes')
                <!-- tab 8 end -->
                <!-- tab 9 start -->
                @include('business.partials.settings_email')
                <!-- tab 9 end -->
                <!-- tab 10 start -->
                @include('business.partials.settings_sms')
                <!-- tab 10 end -->
                <!-- tab 11 start -->
                @include('business.partials.settings_reward_point')
                <!-- tab 11 end -->
                <!-- tab 12 start -->
                @include('business.partials.settings_gift_cards_loyalty')
                <!-- tab 12 end -->
                <!-- tab 13 start -->
                @include('business.partials.settings_integrations')
                <!-- tab 13 end -->
                <!-- tab 14 start -->
                @include('business.partials.settings_modules')
                <!-- tab 14 end -->
                @include('business.partials.settings_custom_labels')
                @include('business.partials.settings_data_tools')
            </div>
        </div>
        <!--  </pos-tab-container> -->
        </div>
    </div>

    <div class="row">
        <div class="col-sm-12">
            <button class="btn btn-danger pull-right" type="submit">@lang('business.update_settings')</button>
        </div>
    </div>
{!! Form::close() !!}
</section>
<!-- /.content -->
@stop
@section('javascript')
<script type="text/javascript">
    __page_leave_confirmation('#bussiness_edit_form');
    
    $(document).on('ifToggled', '#use_superadmin_settings', function() {
        if ($('#use_superadmin_settings').is(':checked')) {
            $('#toggle_visibility').addClass('hide');
            $('.test_email_btn').addClass('hide');
        } else {
            $('#toggle_visibility').removeClass('hide');
            $('.test_email_btn').removeClass('hide');
        }
    });
    
    // Show/hide plastic bag price field based on enable toggle
    $(document).ready(function() {
        function togglePlasticBagPrice() {
            if ($('#enable_plastic_bag_charge').is(':checked') || $('#enable_plastic_bag_charge').prop('checked')) {
                $('#plastic_bag_price_container').show();
                $('#plastic_bag_price_input').prop('required', false); // Price is optional
            } else {
                $('#plastic_bag_price_container').hide();
                $('#plastic_bag_price_input').prop('required', false);
            }
        }
        
        // Wait for iCheck to initialize, then check initial state
        if ($('#enable_plastic_bag_charge').length) {
            setTimeout(function() {
                togglePlasticBagPrice();
            }, 500);
            
            // Toggle on change (works with iCheck)
            $(document).on('ifToggled ifChanged change', '#enable_plastic_bag_charge', function() {
                togglePlasticBagPrice();
            });
        }
    });

    $('#test_email_btn').click( function() {
        var data = {
            mail_driver: $('#mail_driver').val(),
            mail_host: $('#mail_host').val(),
            mail_port: $('#mail_port').val(),
            mail_username: $('#mail_username').val(),
            mail_password: $('#mail_password').val(),
            mail_encryption: $('#mail_encryption').val(),
            mail_from_address: $('#mail_from_address').val(),
            mail_from_name: $('#mail_from_name').val(),
        };
        $.ajax({
            method: 'post',
            data: data,
            url: "{{ action('BusinessController@testEmailConfiguration') }}",
            dataType: 'json',
            success: function(result) {
                if (result.success == true) {
                    swal({
                        text: result.msg,
                        icon: 'success'
                    });
                } else {
                    swal({
                        text: result.msg,
                        icon: 'error'
                    });
                }
            },
        });
    });

    $('#test_sms_btn').click( function() {
        var test_number = $('#test_number').val();
        if (test_number.trim() == '') {
            toastr.error('{{__("lang_v1.test_number_is_required")}}');
            $('#test_number').focus();

            return false;
        }

        var data = {
            url: $('#sms_settings_url').val(),
            send_to_param_name: $('#send_to_param_name').val(),
            msg_param_name: $('#msg_param_name').val(),
            request_method: $('#request_method').val(),
            param_1: $('#sms_settings_param_key1').val(),
            param_2: $('#sms_settings_param_key2').val(),
            param_3: $('#sms_settings_param_key3').val(),
            param_4: $('#sms_settings_param_key4').val(),
            param_5: $('#sms_settings_param_key5').val(),
            param_6: $('#sms_settings_param_key6').val(),
            param_7: $('#sms_settings_param_key7').val(),
            param_8: $('#sms_settings_param_key8').val(),
            param_9: $('#sms_settings_param_key9').val(),
            param_10: $('#sms_settings_param_key10').val(),

            param_val_1: $('#sms_settings_param_val1').val(),
            param_val_2: $('#sms_settings_param_val2').val(),
            param_val_3: $('#sms_settings_param_val3').val(),
            param_val_4: $('#sms_settings_param_val4').val(),
            param_val_5: $('#sms_settings_param_val5').val(),
            param_val_6: $('#sms_settings_param_val6').val(),
            param_val_7: $('#sms_settings_param_val7').val(),
            param_val_8: $('#sms_settings_param_val8').val(),
            param_val_9: $('#sms_settings_param_val9').val(),
            param_val_10: $('#sms_settings_param_val10').val(),
            test_number: test_number
        };

        $.ajax({
            method: 'post',
            data: data,
            url: "{{ action('BusinessController@testSmsConfiguration') }}",
            dataType: 'json',
            success: function(result) {
                if (result.success == true) {
                    swal({
                        text: result.msg,
                        icon: 'success'
                    });
                } else {
                    swal({
                        text: result.msg,
                        icon: 'error'
                    });
                }
            },
        });
    });

    // Data Tools: Update Artist Names
    var artistUpdateUrl = '{{ action("BusinessController@updateArtistNames") }}';

    $('#btn_preview_artists').click(function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#artist_update_spinner').removeClass('hide');
        $('#artist-update-results').html('');

        $.ajax({
            method: 'POST',
            url: artistUpdateUrl,
            data: { mode: 'preview' },
            dataType: 'json',
            success: function(result) {
                $btn.prop('disabled', false);
                $('#artist_update_spinner').addClass('hide');

                if (!result.success) {
                    $('#artist-update-results').html(
                        '<div class="alert alert-danger">' + result.msg + '</div>'
                    );
                    return;
                }

                if (result.count === 0) {
                    $('#artist-update-results').html(
                        '<div class="alert alert-info">No products found that need artist extraction.</div>'
                    );
                    return;
                }

                var html = '<div class="alert alert-warning">'
                    + '<strong>' + result.count + '</strong> products can have their artist extracted from the title.'
                    + '</div>';
                html += '<table class="table table-bordered table-striped table-condensed">';
                html += '<thead><tr><th>ID</th><th>Current Title</th><th>Extracted Artist</th></tr></thead><tbody>';
                for (var i = 0; i < result.samples.length; i++) {
                    var s = result.samples[i];
                    html += '<tr><td>' + s.id + '</td><td>' + $('<span>').text(s.current_name).html()
                        + '</td><td><strong>' + $('<span>').text(s.extracted_artist).html() + '</strong></td></tr>';
                }
                html += '</tbody></table>';

                if (result.count > result.samples.length) {
                    html += '<p class="text-muted">Showing ' + result.samples.length + ' of ' + result.count + ' products.</p>';
                }

                $('#artist-update-results').html(html);
                $('#btn_update_artists').prop('disabled', false);
            },
            error: function() {
                $btn.prop('disabled', false);
                $('#artist_update_spinner').addClass('hide');
                $('#artist-update-results').html(
                    '<div class="alert alert-danger">An error occurred while fetching the preview.</div>'
                );
            }
        });
    });

    $('#btn_update_artists').click(function() {
        var $btn = $(this);
        swal({
            title: 'Are you sure?',
            text: 'This will update the artist field for all matching products. This action cannot be undone easily.',
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(function(confirmed) {
            if (!confirmed) return;

            $btn.prop('disabled', true);
            $('#btn_preview_artists').prop('disabled', true);
            $('#artist_update_spinner').removeClass('hide');

            $.ajax({
                method: 'POST',
                url: artistUpdateUrl,
                data: { mode: 'execute' },
                dataType: 'json',
                success: function(result) {
                    $('#artist_update_spinner').addClass('hide');
                    $('#btn_preview_artists').prop('disabled', false);

                    if (result.success) {
                        $('#artist-update-results').html(
                            '<div class="alert alert-success"><i class="fa fa-check"></i> Successfully updated <strong>'
                            + result.updated_count + '</strong> products with extracted artist names.</div>'
                        );
                        toastr.success('Updated ' + result.updated_count + ' artist names.');
                    } else {
                        $btn.prop('disabled', false);
                        $('#artist-update-results').html(
                            '<div class="alert alert-danger">' + result.msg + '</div>'
                        );
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $('#btn_preview_artists').prop('disabled', false);
                    $('#artist_update_spinner').addClass('hide');
                    toastr.error('An error occurred while updating artist names.');
                }
            });
        });
    });

    @if(!empty($is_business_admin))
    (function() {
        var backupListUrl = '{{ action("DatabaseBackupController@index") }}';
        var backupCreateUrl = '{{ action("DatabaseBackupController@store") }}';
        var backupDownloadBase = '{{ url("business/database-backup/download") }}/';

        function formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(2) + ' MB';
        }

        function loadDatabaseBackupList() {
            var $tbody = $('#database-backup-list tbody');
            $tbody.html('<tr><td colspan="3" class="text-muted">Loading…</td></tr>');
            $.ajax({
                url: backupListUrl,
                method: 'GET',
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function(res) {
                    $tbody.empty();
                    if (!res.success || !res.backups || res.backups.length === 0) {
                        $tbody.html('<tr><td colspan="3" class="text-muted">{{ __("business.database_backup_none") }}</td></tr>');
                        return;
                    }
                    for (var i = 0; i < res.backups.length; i++) {
                        var b = res.backups[i];
                        var dl = backupDownloadBase + encodeURIComponent(b.name);
                        $tbody.append(
                            '<tr><td><code>' + $('<span>').text(b.name).html() + '</code></td>'
                            + '<td>' + formatBytes(b.size) + '</td>'
                            + '<td><a class="btn btn-xs btn-primary" href="' + dl + '"><i class="fa fa-download"></i> {{ __("business.database_backup_download") }}</a></td></tr>'
                        );
                    }
                },
                error: function() {
                    $tbody.html('<tr><td colspan="3" class="text-danger">Could not load backup list.</td></tr>');
                }
            });
        }

        $('#btn_database_backup_refresh').on('click', function() {
            loadDatabaseBackupList();
        });

        $('#btn_database_backup_create').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#database_backup_spinner').removeClass('hide');
            $.ajax({
                url: backupCreateUrl,
                method: 'POST',
                data: { _token: $('meta[name="csrf-token"]').attr('content') },
                dataType: 'json',
                success: function(res) {
                    $('#database_backup_spinner').addClass('hide');
                    $btn.prop('disabled', false);
                    if (res.success) {
                        toastr.success(res.msg || 'Backup ready.');
                        loadDatabaseBackupList();
                        if (res.download_url) {
                            window.location.href = res.download_url;
                        }
                    } else {
                        toastr.error(res.msg || 'Backup failed.');
                    }
                },
                error: function(xhr) {
                    $('#database_backup_spinner').addClass('hide');
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : 'Backup failed.';
                    toastr.error(msg);
                }
            });
        });

        $('#tab-trigger-data-tools').on('click', function() {
            window.setTimeout(loadDatabaseBackupList, 150);
        });
    })();
    @endif
</script>
@endsection