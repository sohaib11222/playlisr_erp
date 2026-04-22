<script type="text/javascript">
$(document).ready( function(){

//Date range as a button
$('#sell_list_filter_date_range').daterangepicker(
    dateRangeSettings,
    function (start, end) {
        $('#sell_list_filter_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
        sell_table.ajax.reload();
    }
);
$('#sell_list_filter_date_range').on('cancel.daterangepicker', function(ev, picker) {
    $('#sell_list_filter_date_range').val('');
    sell_table.ajax.reload();
});

$(document).on('change', '#sell_list_filter_location_id, #sell_list_filter_customer_id, #sell_list_filter_payment_status, #created_by, #sales_cmsn_agnt, #service_staffs, #shipping_status',  function() {
    sell_table.ajax.reload();
});

// POS list hero search. Listen on both `input` (modern typing events)
// and `keyup` (fallback) since some Chrome extensions / IME flows
// swallow `input` in ways that left Sarah's search doing nothing
// even though typing was visible in the box.
let posSearchTimer = null;
function __posTextSearchReload() {
    clearTimeout(posSearchTimer);
    posSearchTimer = setTimeout(function () {
        if (typeof sell_table === 'undefined' || !sell_table) return;
        var q = ($('#pos_text_search').val() || '').trim();
        // Show a small "searching / X results" status under the input so
        // it's obvious the search is live (and not a dead text box).
        $('#pos_text_search_status').text(q ? 'Searching…' : '');
        sell_table.one('xhr.dt', function (e, settings, json) {
            var n = json && typeof json.recordsFiltered !== 'undefined'
                ? json.recordsFiltered
                : (json && json.data ? json.data.length : 0);
            $('#pos_text_search_status').text(
                q ? (n + ' sale' + (n === 1 ? '' : 's') + ' match "' + q + '"') : ''
            );
        });
        sell_table.ajax.reload(null, false); // keep current page
    }, 250);
}
$(document).on('input keyup', '#pos_text_search', __posTextSearchReload);

sell_table = $('#sell_table').DataTable({
        processing: true,
        serverSide: true,
        aaSorting: [[1, 'desc']],
        scrollY: "75vh",
        scrollX:        true,
        scrollCollapse: true,
        "ajax": {
            "url": "/sells",
            "data": function ( d ) {
                if($('#sell_list_filter_date_range').val()) {
                    var start = $('#sell_list_filter_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                    var end = $('#sell_list_filter_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
                    d.start_date = start;
                    d.end_date = end;
                }
                if ($('#is_direct_sale').length) {
                    d.is_direct_sale = $('#is_direct_sale').val();
                }

                if($('#sell_list_filter_location_id').length) {
                    d.location_id = $('#sell_list_filter_location_id').val();
                }
                d.customer_id = $('#sell_list_filter_customer_id').val();

                if($('#sell_list_filter_payment_status').length) {
                    d.payment_status = $('#sell_list_filter_payment_status').val();
                }
                if($('#created_by').length) {
                    d.created_by = $('#created_by').val();
                }
                if($('#sales_cmsn_agnt').length) {
                    d.sales_cmsn_agnt = $('#sales_cmsn_agnt').val();
                }
                if($('#service_staffs').length) {
                    d.service_staffs = $('#service_staffs').val();
                }

                if($('#shipping_status').length) {
                    d.shipping_status = $('#shipping_status').val();
                }
                if ($('#pos_text_search').length) {
                    d.pos_text_search = $('#pos_text_search').val();
                }

                if($('#only_subscriptions').length && $('#only_subscriptions').is(':checked')) {
                    d.only_subscriptions = 1;
                }

                d = __datatable_ajax_callback(d);
            }
        },
        columns: [
            { data: 'action', name: 'action', orderable: false, "searchable": false},
            { data: 'transaction_date', name: 'transaction_date'  },
            { data: 'invoice_no', name: 'invoice_no'},
            { data: 'conatct_name', name: 'conatct_name'},
            { data: 'mobile', name: 'contacts.mobile'},
            { data: 'business_location', name: 'bl.name'},
            { data: 'payment_status', name: 'payment_status'},
            { data: 'payment_methods', orderable: false, "searchable": false},
            { data: 'final_total', name: 'final_total'},
            { data: 'total_paid', name: 'total_paid', "searchable": false},
            { data: 'total_remaining', name: 'total_remaining'},
            { data: 'return_due', orderable: false, "searchable": false},
            { data: 'shipping_status', name: 'shipping_status'},
            { data: 'total_items', name: 'total_items', "searchable": false},
            { data: 'types_of_service_name', name: 'tos.name', @if(empty($is_types_service_enabled)) visible: false @endif},
            { data: 'service_custom_field_1', name: 'service_custom_field_1', @if(empty($is_types_service_enabled)) visible: false @endif},
            { data: 'added_by', name: 'u.first_name'},
            { data: 'additional_notes', name: 'additional_notes'},
            { data: 'staff_note', name: 'staff_note'},
            { data: 'shipping_details', name: 'shipping_details'},
            { data: 'table_name', name: 'tables.name', @if(empty($is_tables_enabled)) visible: false @endif },
            { data: 'waiter', name: 'ss.first_name', @if(empty($is_service_staff_enabled)) visible: false @endif },
            // Sarah 2026-04-22: Sales channel (In Store / Whatnot / Discogs / eBay).
            // orderable:false on purpose — the channel column only exists
            // after migration 2026_04_22_063000 runs, so we skip server-side
            // ORDER BY to keep un-migrated servers rendering the list.
            { data: 'channel', orderable: false, searchable: false }
        ],
        "fnDrawCallback": function (oSettings) {
            __currency_convert_recursively($('#sell_table'));
        },
        "footerCallback": function ( row, data, start, end, display ) {
            var footer_sale_total = 0;
            var footer_total_paid = 0;
            var footer_total_remaining = 0;
            var footer_total_sell_return_due = 0;
            for (var r in data){
                footer_sale_total += $(data[r].final_total).data('orig-value') ? parseFloat($(data[r].final_total).data('orig-value')) : 0;
                footer_total_paid += $(data[r].total_paid).data('orig-value') ? parseFloat($(data[r].total_paid).data('orig-value')) : 0;
                footer_total_remaining += $(data[r].total_remaining).data('orig-value') ? parseFloat($(data[r].total_remaining).data('orig-value')) : 0;
                footer_total_sell_return_due += $(data[r].return_due).find('.sell_return_due').data('orig-value') ? parseFloat($(data[r].return_due).find('.sell_return_due').data('orig-value')) : 0;
            }

            $('.footer_total_sell_return_due').html(__currency_trans_from_en(footer_total_sell_return_due));
            $('.footer_total_remaining').html(__currency_trans_from_en(footer_total_remaining));
            $('.footer_total_paid').html(__currency_trans_from_en(footer_total_paid));
            $('.footer_sale_total').html(__currency_trans_from_en(footer_sale_total));

            $('.footer_payment_status_count').html(__count_status(data, 'payment_status'));
            $('.service_type_count').html(__count_status(data, 'types_of_service_name'));
            $('.payment_method_count').html(__count_status(data, 'payment_methods'));
        },
        createdRow: function( row, data, dataIndex ) {
            $( row ).find('td:eq(6)').attr('class', 'clickable_td');
        },
        buttons: [
            {
                extend: 'csv',
                text: '<i class="fa fa-file-csv" aria-hidden="true"></i> ' + LANG.export_to_csv,
                className: 'btn-sm',
                exportOptions: {
                    columns: ':visible',
                },
                footer: true,
            },
            {
                extend: 'excel',
                text: '<i class="fa fa-file-excel" aria-hidden="true"></i> ' + LANG.export_to_excel,
                className: 'btn-sm',
                exportOptions: {
                    columns: ':visible',
                },
                footer: true,
            },
            {
                text: '<i class="fa fa-download" aria-hidden="true"></i> Export Items Sold (CSV)',
                className: 'btn-sm btn-success',
                action: function ( e, dt, node, config ) {
                    var $btn = $(node);
                    var originalHtml = $btn.html();
                    
                    // Disable button and show loading
                    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Exporting...');
                    
                    var params = new URLSearchParams();
                    if($('#sell_list_filter_date_range').val()) {
                        var start = $('#sell_list_filter_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                        var end = $('#sell_list_filter_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
                        params.append('start_date', start);
                        params.append('end_date', end);
                    }
                    if($('#sell_list_filter_location_id').length && $('#sell_list_filter_location_id').val()) {
                        params.append('location_id', $('#sell_list_filter_location_id').val());
                    }
                    if($('#sell_list_filter_customer_id').val()) {
                        params.append('customer_id', $('#sell_list_filter_customer_id').val());
                    }
                    if($('#sell_list_filter_payment_status').length && $('#sell_list_filter_payment_status').val()) {
                        params.append('payment_status', $('#sell_list_filter_payment_status').val());
                    }
                    if($('#is_direct_sale').length) {
                        params.append('is_direct_sale', $('#is_direct_sale').val());
                    }
                    
                    // Use fetch API for AJAX - TEMPORARY: Expecting JSON response for testing
                    fetch('/pos/export-csv?' + params.toString(), {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            return response.text().then(function(text) {
                                throw new Error('Export failed: ' + response.status + ' - ' + text);
                            });
                        }
                        
                        // Get the filename from Content-Disposition header or use default
                        var contentDisposition = response.headers.get('content-disposition');
                        var filename = 'pos_sales_items.csv';
                        if (contentDisposition) {
                            var filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                            if (filenameMatch && filenameMatch[1]) {
                                filename = filenameMatch[1].replace(/['"]/g, '');
                            }
                        }
                        
                        return response.blob().then(function(blob) {
                            // Check if blob is too small (likely an error)
                            if (blob.size < 100) {
                                return blob.text().then(function(text) {
                                    throw new Error('Export file appears to be empty or contain an error: ' + text);
                                });
                            }
                            
                            // Create download link
                            var url = window.URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = filename;
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                            
                            return { success: true, filename: filename };
                        });
                    })
                    .then(function(result) {
                        // Re-enable button
                        $btn.prop('disabled', false).html(originalHtml);
                        toastr.success('Export completed successfully!');
                    })
                    .catch(function(error) {
                        // Re-enable button
                        $btn.prop('disabled', false).html(originalHtml);
                        toastr.error('Export failed: ' + error.message);
                        console.error('Export error:', error);
                    });
                }
            },
            {
                text: '<i class="fa fa-download" aria-hidden="true"></i> Export Items Sold (Excel)',
                className: 'btn-sm btn-success',
                action: function ( e, dt, node, config ) {
                    var $btn = $(node);
                    var originalHtml = $btn.html();
                    
                    // Disable button and show loading
                    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Exporting...');
                    
                    var params = new URLSearchParams();
                    if($('#sell_list_filter_date_range').val()) {
                        var start = $('#sell_list_filter_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                        var end = $('#sell_list_filter_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
                        params.append('start_date', start);
                        params.append('end_date', end);
                    }
                    if($('#sell_list_filter_location_id').length && $('#sell_list_filter_location_id').val()) {
                        params.append('location_id', $('#sell_list_filter_location_id').val());
                    }
                    if($('#sell_list_filter_customer_id').val()) {
                        params.append('customer_id', $('#sell_list_filter_customer_id').val());
                    }
                    if($('#sell_list_filter_payment_status').length && $('#sell_list_filter_payment_status').val()) {
                        params.append('payment_status', $('#sell_list_filter_payment_status').val());
                    }
                    if($('#is_direct_sale').length) {
                        params.append('is_direct_sale', $('#is_direct_sale').val());
                    }
                    
                    // Use fetch API for AJAX - TEMPORARY: Expecting JSON response for testing
                    fetch('/pos/export-excel?' + params.toString(), {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            return response.text().then(function(text) {
                                throw new Error('Export failed: ' + response.status + ' - ' + text);
                            });
                        }
                        
                        // Get the filename from Content-Disposition header or use default
                        var contentDisposition = response.headers.get('content-disposition');
                        var filename = 'pos_sales_items.csv';
                        if (contentDisposition) {
                            var filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                            if (filenameMatch && filenameMatch[1]) {
                                filename = filenameMatch[1].replace(/['"]/g, '');
                            }
                        }
                        
                        return response.blob().then(function(blob) {
                            // Check if blob is too small (likely an error)
                            if (blob.size < 100) {
                                return blob.text().then(function(text) {
                                    throw new Error('Export file appears to be empty or contain an error: ' + text);
                                });
                            }
                            
                            // Create download link
                            var url = window.URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = filename;
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                            
                            return { success: true, filename: filename };
                        });
                    })
                    .then(function(result) {
                        // Re-enable button
                        $btn.prop('disabled', false).html(originalHtml);
                        toastr.success('Export completed successfully!');
                    })
                    .catch(function(error) {
                        // Re-enable button
                        $btn.prop('disabled', false).html(originalHtml);
                        toastr.error('Export failed: ' + error.message);
                        console.error('Export error:', error);
                    });
                }
            },
            {
                extend: 'print',
                text: '<i class="fa fa-print" aria-hidden="true"></i> ' + LANG.print,
                className: 'btn-sm',
                exportOptions: {
                    columns: ':visible',
                    stripHtml: true,
                },
                footer: true,
                customize: function ( win ) {
                    if ($('.print_table_part').length > 0 ) {
                        $($('.print_table_part').html()).insertBefore($(win.document.body).find( 'table' ));
                    }
                    if ($(win.document.body).find( 'table.hide-footer').length) {
                        $(win.document.body).find( 'table.hide-footer tfoot' ).remove();
                    }
                    __currency_convert_recursively($(win.document.body).find( 'table' ));
                }
            },
            {
                extend: 'colvis',
                text: '<i class="fa fa-columns" aria-hidden="true"></i> ' + LANG.col_vis,
                className: 'btn-sm',
            },
        ],
        dom: 'Bfrtip'
    });
    
    $('#only_subscriptions').on('ifChanged', function(event){
        sell_table.ajax.reload();
    });

    // QuickBooks manual sync from row actions (POS sales list context).
    $(document).on('click', '.sync-quickbooks-sale', function(e) {
        e.preventDefault();
        var $link = $(this);
        var transactionId = $link.data('transaction-id');
        if (!transactionId) {
            toastr.error('Transaction ID is missing for QuickBooks sync.');
            return;
        }

        if ($link.data('syncing')) {
            return;
        }

        var token = $('meta[name="csrf-token"]').attr('content');
        $link.data('syncing', true);
        $link.addClass('disabled');
        var oldHtml = $link.html();
        $link.html('<i class="fa fa-spinner fa-spin"></i> Syncing...');

        $.ajax({
            url: '/business/quickbooks/sync-sale',
            method: 'POST',
            dataType: 'json',
            data: {
                _token: token,
                transaction_id: transactionId
            },
            success: function(response) {
                if (response && response.success) {
                    toastr.success(response.msg || 'Sale synced to QuickBooks.');
                } else {
                    toastr.error((response && response.msg) ? response.msg : 'QuickBooks sync failed.');
                }
            },
            error: function(xhr) {
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    toastr.error(xhr.responseJSON.message);
                } else {
                    toastr.error('QuickBooks sync failed.');
                }
            },
            complete: function() {
                $link.data('syncing', false);
                $link.removeClass('disabled');
                $link.html(oldHtml);
            }
        });
    });
});

</script>