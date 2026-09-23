@extends('layouts.app')
@section('title', __('lang_v1.'.$type.'s'))
@php
    $api_key = env('GOOGLE_MAP_API_KEY');
@endphp
@if(!empty($api_key))
    @section('css')
        @include('contact.partials.google_map_styles')
    @endsection
@endif
@section('content')
<!-- Content Header (Page header) -->
<section class="content-header">
    <h1> @lang('lang_v1.'.$type.'s')
        @if($type == 'customer')
            <span id="customer_total_count" class="label label-default" style="font-size:14px; vertical-align:middle; font-weight:600;"></span>
        @endif
        <small>@lang( 'contact.manage_your_contact', ['contacts' =>  __('lang_v1.'.$type.'s') ])</small>
    </h1>
</section>

<!-- Main content -->
<section class="content">
    {{-- Only ERP admins may export the customer/supplier list to Excel/CSV/PDF.
         This marker tells app.js to drop the DataTables export buttons for
         everyone else. Phone numbers are also masked server-side. --}}
    @if(empty($is_admin))
        <span id="disable_contact_export" class="hide"></span>
    @endif
    @if($type == 'customer')
        {{-- Hero search — Sarah 2026-04-22: the default DataTables filter box
             is tiny and sits top-right, so cashiers don't realize it's the
             search. This big obvious input drives the same table.search()
             API and debounces so we don't hammer the server. --}}
        <style>
            .contact-hero-search-wrap {
                background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
                padding: 14px 16px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .contact-hero-search-label {
                display: block; font-size: 11px; font-weight: 700; text-transform: uppercase;
                letter-spacing: .06em; color: #6b7280; margin-bottom: 6px;
            }
            .contact-hero-search-label i { color: #5A5045; margin-right: 6px; }
            #contact_hero_search {
                width: 100%; height: 54px; font-size: 18px; font-weight: 500;
                padding: 10px 16px; border: 2px solid #d1d5db; border-radius: 8px;
                background: #fff; transition: border-color .15s, box-shadow .15s;
            }
            #contact_hero_search:focus {
                outline: none; border-color: #1b6ca8;
                box-shadow: 0 0 0 3px rgba(27, 108, 168, .18);
            }
            #contact_hero_search::placeholder { color: #9ca3af; font-weight: 400; }
            /* Sarah 2026-09-23: just the search bar + table for the Customers
               page — the filter panel and the DataTables toolbar (length,
               built-in search, export buttons) are redundant with the hero
               search above and just add clutter for a quick lookup. */
            #contact_table_wrapper .dataTables_length,
            #contact_table_wrapper .dataTables_filter,
            #contact_table_wrapper .dt-buttons {
                display: none !important;
            }
            /* Readability pass — the default table is dense/small. Bigger
               type, more row breathing room, a clearer header, and a
               visible hover so it's easy to track a row across columns. */
            #contact_table {
                font-size: 14px;
            }
            #contact_table thead th {
                font-size: 12px; font-weight: 700; text-transform: uppercase;
                letter-spacing: .04em; color: #4b5563; background: #f9fafb;
                border-bottom: 2px solid #e5e7eb; padding: 10px 12px;
                white-space: nowrap;
            }
            #contact_table tbody td {
                padding: 12px; vertical-align: middle; line-height: 1.4;
            }
            #contact_table tbody tr:hover {
                background-color: #f0f7ff !important;
            }
            #contact_table tbody td:nth-child(4) {
                font-weight: 600; color: #1f2937;
            }
            /* View/Edit — compact, sits in its own column. */
            #contact_table .contact-btn-compact {
                padding: 2px 7px; font-size: 11px; line-height: 1.6;
            }
            /* Delete — its own far-right column, small and muted so it
               doesn't compete with View/Edit; only turns red on hover. */
            #contact_table .contact-btn-delete-small {
                display: inline-block; padding: 3px 6px; border-radius: 4px;
                color: #9ca3af; font-size: 12px;
            }
            #contact_table .contact-btn-delete-small:hover {
                color: #fff; background: #d9534f;
            }
        </style>
        <div class="contact-hero-search-wrap">
            <label class="contact-hero-search-label" for="contact_hero_search">
                <i class="fa fa-search"></i> Search customers — name, phone, email, contact ID
            </label>
            <input type="text" id="contact_hero_search" class="form-control"
                   placeholder="e.g. Sarah Hedvat · 510-809-6346 · sarah@example.com · CO0068"
                   autocomplete="off">
        </div>
        <div id="import_rsvp_contacts_status" style="margin-bottom: 10px;"></div>
        <div id="import_rsvp_contacts_preview"></div>
    @endif
    {{-- Sarah 2026-09-23: no filter panel on the Customers page — the hero
         search above covers the lookups staff actually do; keep it for
         suppliers since that page has no hero search of its own. --}}
    @if($type != 'customer')
    @component('components.filters', ['title' => __('report.filters')])
    @if($type == 'customer')
        <div class="col-md-3">
            <div class="form-group">
                <label>
                    {!! Form::checkbox('has_sell_due', 1, false, ['class' => 'input-icheck', 'id' => 'has_sell_due']) !!} <strong>@lang('lang_v1.sell_due')</strong>
                </label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label>
                    {!! Form::checkbox('has_sell_return', 1, false, ['class' => 'input-icheck', 'id' => 'has_sell_return']) !!} <strong>@lang('lang_v1.sell_return')</strong>
                </label>
            </div>
        </div>
    @elseif($type == 'supplier')
        <div class="col-md-3">
            <div class="form-group">
                <label>
                    {!! Form::checkbox('has_purchase_due', 1, false, ['class' => 'input-icheck', 'id' => 'has_purchase_due']) !!} <strong>@lang('report.purchase_due')</strong>
                </label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label>
                    {!! Form::checkbox('has_purchase_return', 1, false, ['class' => 'input-icheck', 'id' => 'has_purchase_return']) !!} <strong>@lang('lang_v1.purchase_return')</strong>
                </label>
            </div>
        </div>
    @endif
    <div class="col-md-3">
        <div class="form-group">
            <label>
                {!! Form::checkbox('has_advance_balance', 1, false, ['class' => 'input-icheck', 'id' => 'has_advance_balance']) !!} <strong>@lang('lang_v1.advance_balance')</strong>
            </label>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label>
                {!! Form::checkbox('has_opening_balance', 1, false, ['class' => 'input-icheck', 'id' => 'has_opening_balance']) !!} <strong>@lang('lang_v1.opening_balance')</strong>
            </label>
        </div>
    </div>
    @if($type == 'customer')
        <div class="col-md-3">
            <div class="form-group">
                <label for="has_no_sell_from">@lang('lang_v1.has_no_sell_from'):</label>
                {!! Form::select('has_no_sell_from', ['one_month' => __('lang_v1.one_month'), 'three_months' => __('lang_v1.three_months'), 'six_months' => __('lang_v1.six_months'), 'one_year' => __('lang_v1.one_year')], null, ['class' => 'form-control', 'id' => 'has_no_sell_from', 'placeholder' => __('messages.please_select')]) !!}
            </div>
        </div>

        <div class="col-md-3">
            <div class="form-group">
                <label for="cg_filter">@lang('lang_v1.customer_group'):</label>
                {!! Form::select('cg_filter', $customer_groups, null, ['class' => 'form-control', 'id' => 'cg_filter']) !!}
            </div>
        </div>
    @endif

    @if(config('constants.enable_contact_assign') === true)
    <div class="col-md-3">
        <div class="form-group">
            {!! Form::label('assigned_to',  __('lang_v1.assigned_to') . ':') !!}
            {!! Form::select('assigned_to', $users, null, ['class' => 'form-control select2', 'style' => 'width:100%']) !!}
        </div>
    </div>
    @endif

    <div class="col-md-3">
        <div class="form-group">
            <label for="status_filter">@lang('sale.status'):</label>
            {!! Form::select('status_filter', ['active' => __('business.is_active'), 'inactive' => __('lang_v1.inactive')], null, ['class' => 'form-control', 'id' => 'status_filter', 'placeholder' => __('lang_v1.none')]) !!}
        </div>
    </div>
    @endcomponent
    @endif
    <input type="hidden" value="{{$type}}" id="contact_type">
    @component('components.widget', ['class' => 'box-primary', 'title' => __( 'contact.all_your_contact', ['contacts' => __('lang_v1.'.$type.'s') ])])
        @if(auth()->user()->can('supplier.create') || auth()->user()->can('customer.create') || auth()->user()->can('supplier.view_own') || auth()->user()->can('customer.view_own'))
            @slot('tool')
                <div class="box-tools">
                    @if($type == 'customer')
                        <a href="{{ action('ContactCampaignController@index') }}" class="btn btn-warning" style="margin-bottom: 8px;">
                            <i class="fa fa-bullhorn"></i> Customer Alerts
                        </a>
                        @if($is_admin)
                            <button type="button" class="btn btn-default" id="import_rsvp_contacts_btn" style="margin-bottom: 8px;">
                                <i class="fa fa-calendar"></i> Import RSVP Contacts
                            </button>
                        @endif
                    @endif
                    <button type="button" class="btn btn-block btn-primary btn-modal"
                    data-href="{{action('ContactController@create', ['type' => $type])}}"
                    data-container=".contact_modal">
                    <i class="fa fa-plus"></i> @lang('messages.add')</button>
                </div>
            @endslot
        @endif
        @if(auth()->user()->can('supplier.view') || auth()->user()->can('customer.view') || auth()->user()->can('supplier.view_own') || auth()->user()->can('customer.view_own'))
            <table class="table table-bordered table-striped" id="contact_table" style="width: 100%; min-height: 100px" >
                <thead>
                    <tr>
                        <th>@lang('messages.action')</th>
                        <th>@lang('lang_v1.contact_id')</th>
                        @if($type == 'supplier')

                            <th>@lang('contact.name')</th>
                            <th>@lang('business.email')</th>
                            <th>@lang('lang_v1.added_on')</th>
                            <th>@lang('contact.mobile')</th>
                            <th>Address</th>
                        @elseif( $type == 'customer')
                            <th>@lang('business.business_name')</th>
                            <th>@lang('user.name')</th>
                            <th>@lang('business.email')</th>
                            <th>@lang('lang_v1.added_on')</th>
                            <th>@lang('contact.mobile')</th>
                            <th>Store Credit</th>
                            <th>Lifetime Purchases</th>
                            <th>Store(s)</th>
                            <th>Store Visits</th>
                            <th>Loyalty Points</th>
                            <th>Loyalty Tier</th>
                            <th>Preorders</th>
                            <th style="width:36px;"></th>
                        @endif
                        @php
                            $custom_labels = json_decode(session('business.custom_labels'), true);
                        @endphp


                    </tr>
                </thead>
                <tfoot>
                    <tr class="bg-gray font-17 text-center footer-total">


                    </tr>
                </tfoot>
            </table>
        @endif
    @endcomponent

    <div class="modal fade contact_modal" tabindex="-1" role="dialog"
    	aria-labelledby="gridSystemModalLabel">
    </div>
    <div class="modal fade pay_contact_due_modal" tabindex="-1" role="dialog"
        aria-labelledby="gridSystemModalLabel">
    </div>
    
    @if($type == 'customer')
        @include('sale_pos.partials.customer_account_modal')
    @endif

</section>
<!-- /.content -->
@stop
@section('javascript')
{{-- Hero-search wiring (customer list). Drives the same DataTables
     `search()` API the built-in filter uses, so it goes to the server
     via the standard `search[value]` param — backend already filters
     on contact_id / name / mobile / email / supplier_business_name. --}}
<script>
$(function () {
    // Total contact count next to the "Customers" heading — reads it off
    // the DataTable's own server response (recordsTotal) each time it
    // loads or re-filters, no extra request needed.
    $('#contact_table').on('xhr.dt', function (e, settings, json) {
        if (json && typeof json.recordsTotal !== 'undefined') {
            $('#customer_total_count').text(Number(json.recordsTotal).toLocaleString() + ' total');
        }
    });

    var heroTimer = null;
    $(document).on('input', '#contact_hero_search', function () {
        clearTimeout(heroTimer);
        var val = $(this).val();
        heroTimer = setTimeout(function () {
            if (typeof contact_table !== 'undefined' && contact_table) {
                contact_table.search(val).draw();
            }
        }, 250);
    });

    // Import RSVP Contacts — pulls event RSVPs (+guests) into Customers,
    // linking existing customers by email/mobile or creating new ones.
    // First click is always a DRY RUN (no writes) so you see counts before
    // anything happens; a "Confirm" button then commits. The same job also
    // runs nightly at 3:45am on its own, so this button is for on-demand/
    // backfill use, or just to see what it would do.
    function runRsvpImport(commit) {
        var btn = $('#import_rsvp_contacts_btn');
        btn.prop('disabled', true);
        $('#import_rsvp_contacts_status').html(
            '<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> ' +
            (commit ? 'Writing contacts...' : 'Checking RSVPs against every event — this can take a minute...') +
            '</div>'
        );
        $.ajax({
            url: '{{ route("contacts.import-rsvp-contacts") }}',
            method: 'POST',
            data: { _token: '{{ csrf_token() }}', commit: commit ? 1 : 0 },
            dataType: 'json',
            timeout: 600000,
            success: function (response) {
                btn.prop('disabled', false);
                var cssClass = response.success ? 'alert-success' : 'alert-danger';
                var icon = response.success ? 'fa-check' : 'fa-times';
                var msg = response.msg || (response.success ? 'Done.' : 'Failed.');
                var html = '<div class="' + cssClass + ' alert"><i class="fa ' + icon + '"></i> ' + msg + '</div>';
                if (response.success && !commit) {
                    html += '<button type="button" class="btn btn-warning" id="confirm_rsvp_import_btn">' +
                        '<i class="fa fa-check"></i> Confirm — write these to Customers</button>';
                }
                $('#import_rsvp_contacts_status').html(html);
                if (response.success && Array.isArray(response.rows) && response.rows.length > 0) {
                    renderRsvpPreview(response.rows);
                } else {
                    $('#import_rsvp_contacts_preview').empty();
                }
                if (response.success && commit && typeof contact_table !== 'undefined' && contact_table) {
                    contact_table.ajax.reload(null, false);
                }
            },
            error: function (xhr, textStatus) {
                btn.prop('disabled', false);
                $('#import_rsvp_contacts_status').html(
                    '<div class="alert alert-danger"><i class="fa fa-times"></i> Error: ' + (textStatus || 'request failed') + '</div>'
                );
            }
        });
    }
    $('#import_rsvp_contacts_btn').on('click', function () { runRsvpImport(false); });
    $(document).on('click', '#confirm_rsvp_import_btn', function () { runRsvpImport(true); });

    // Preview table for the RSVP import — every row it found (not just a
    // sample), so test names / dupes can be spotted before writing. Flags:
    //   - same email or same normalized name appearing more than once
    //   - names/emails that look like test data (test, asdf, n/a, example.com...)
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    var TEST_NAME_RE = /\btest\b|^n\/?a$|^(asdf|qwerty|xxx+|sample|dummy|fake|no ?name|placeholder|foo|bar|foobar)$|^.{0,1}$/i;
    var TEST_EMAIL_RE = /@(example|test|mailinator|fake)\.[a-z]{2,}$|^test@|noreply@/i;
    function renderRsvpPreview(rows) {
        var emailCounts = {}, nameCounts = {};
        rows.forEach(function (r) {
            var e = (r.email || '').trim().toLowerCase();
            var n = (r.name || '').trim().toLowerCase().replace(/\s+/g, ' ');
            if (e) emailCounts[e] = (emailCounts[e] || 0) + 1;
            if (n) nameCounts[n] = (nameCounts[n] || 0) + 1;
        });
        var dupCount = 0, testCount = 0;
        var rowsHtml = rows.map(function (r) {
            var e = (r.email || '').trim().toLowerCase();
            var n = (r.name || '').trim().toLowerCase().replace(/\s+/g, ' ');
            var isDup = (e && emailCounts[e] > 1) || (n && nameCounts[n] > 1);
            var isTest = TEST_NAME_RE.test((r.name || '').trim()) || (e && TEST_EMAIL_RE.test(e));
            if (isDup) dupCount++;
            if (isTest) testCount++;
            var rowClass = isTest ? 'style="background:#fdecea;"' : (isDup ? 'style="background:#fff8e1;"' : '');
            var flags = (isTest ? '<span class="label label-danger">test-like</span> ' : '') +
                        (isDup ? '<span class="label label-warning">dup</span>' : '');
            return '<tr ' + rowClass + '>' +
                '<td>' + escapeHtml(r.name) + '</td>' +
                '<td>' + escapeHtml(r.email) + '</td>' +
                '<td>' + escapeHtml(r.phone) + '</td>' +
                '<td>' + escapeHtml(r.event) + '</td>' +
                '<td>' + escapeHtml(r.status) + '</td>' +
                '<td>' + flags + '</td>' +
                '</tr>';
        }).join('');

        var html = '' +
            '<div class="cp-card" style="margin-top:6px;">' +
            '<div style="padding:10px 14px; font-weight:600;">' +
            'Showing all ' + rows.length + ' rows — ' +
            '<span style="color:#c0392b;">' + testCount + ' test-like</span>, ' +
            '<span style="color:#b7791f;">' + dupCount + ' possible duplicates</span>' +
            '<input type="text" id="rsvp_preview_filter" class="form-control" style="width:260px; float:right;" placeholder="Filter this list...">' +
            '</div>' +
            '<div style="max-height:420px; overflow:auto; border-top:1px solid #eee;">' +
            '<table class="table table-condensed table-striped" style="margin-bottom:0;" id="rsvp_preview_table">' +
            '<thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Event</th><th>Status</th><th>Flags</th></tr></thead>' +
            '<tbody>' + rowsHtml + '</tbody>' +
            '</table></div></div>';

        $('#import_rsvp_contacts_preview').html(html);

        $('#rsvp_preview_filter').on('input', function () {
            var q = $(this).val().toLowerCase();
            $('#rsvp_preview_table tbody tr').each(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(q) !== -1);
            });
        });
    }
});
</script>
@if(!empty($api_key))
<script>
  // This example adds a search box to a map, using the Google Place Autocomplete
  // feature. People can enter geographical searches. The search box will return a
  // pick list containing a mix of places and predicted search terms.

  // This example requires the Places library. Include the libraries=places
  // parameter when you first load the API. For example:
  // <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=places">

  function initAutocomplete() {
    var map = new google.maps.Map(document.getElementById('map'), {
      center: {lat: -33.8688, lng: 151.2195},
      zoom: 10,
      mapTypeId: 'roadmap'
    });

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (position) {
            initialLocation = new google.maps.LatLng(position.coords.latitude, position.coords.longitude);
            map.setCenter(initialLocation);
        });
    }


    // Create the search box and link it to the UI element.
    var input = document.getElementById('shipping_address');
    var searchBox = new google.maps.places.SearchBox(input);
    map.controls[google.maps.ControlPosition.TOP_LEFT].push(input);

    // Bias the SearchBox results towards current map's viewport.
    map.addListener('bounds_changed', function() {
      searchBox.setBounds(map.getBounds());
    });

    var markers = [];
    // Listen for the event fired when the user selects a prediction and retrieve
    // more details for that place.
    searchBox.addListener('places_changed', function() {
      var places = searchBox.getPlaces();

      if (places.length == 0) {
        return;
      }

      // Clear out the old markers.
      markers.forEach(function(marker) {
        marker.setMap(null);
      });
      markers = [];

      // For each place, get the icon, name and location.
      var bounds = new google.maps.LatLngBounds();
      places.forEach(function(place) {
        if (!place.geometry) {
          console.log("Returned place contains no geometry");
          return;
        }
        var icon = {
          url: place.icon,
          size: new google.maps.Size(71, 71),
          origin: new google.maps.Point(0, 0),
          anchor: new google.maps.Point(17, 34),
          scaledSize: new google.maps.Size(25, 25)
        };

        // Create a marker for each place.
        markers.push(new google.maps.Marker({
          map: map,
          icon: icon,
          title: place.name,
          position: place.geometry.location
        }));

        //set position field value
        var lat_long = [place.geometry.location.lat(), place.geometry.location.lng()]
        $('#position').val(lat_long);

        if (place.geometry.viewport) {
          // Only geocodes have viewport.
          bounds.union(place.geometry.viewport);
        } else {
          bounds.extend(place.geometry.location);
        }
      });
      map.fitBounds(bounds);
    });
  }

</script>
<script src="https://maps.googleapis.com/maps/api/js?key={{$api_key}}&libraries=places"
     async defer></script>
<script type="text/javascript">
    $(document).on('shown.bs.modal', '.contact_modal', function(e) {
        initAutocomplete();
    });
    
    // Function to show customer profile modal (similar to POS) - define globally
    window.showCustomerProfileModal = function(contactId) {
        if (!contactId) {
            toastr.error('Please select a customer first');
            return;
        }

        // Check if modal exists
        if ($('#customer_account_modal').length === 0) {
            toastr.error('Customer account modal not found. Please refresh the page.');
            console.error('Modal #customer_account_modal not found');
            return;
        }

        // Show loading
        $('#customer_account_loading').show();
        $('#customer_account_content').hide();

        // Open modal
        $('#customer_account_modal').modal('show');

        // Load detailed customer info
        $.ajax({
            url: '/sells/pos/get-customer-account-info',
            type: 'GET',
            data: { contact_id: contactId },
            dataType: 'json',
            success: function(response) {
                $('#customer_account_loading').hide();
                
                if (response && response.success && response.data) {
                    var data = response.data;
                    var contact = data.contact;

                    // Update modal content
                    $('#modal_customer_name').text(contact.name);
                    $('#modal_account_balance').text(__currency_trans_from_en(contact.balance || 0, true));
                    $('#modal_lifetime_purchases').text(__currency_trans_from_en(contact.lifetime_purchases || 0, true));
                    $('#modal_loyalty_points').text(contact.loyalty_points || 0);
                    $('#modal_loyalty_tier').text(contact.loyalty_tier || 'Bronze');
                    $('#modal_last_purchase_date').text(contact.last_purchase_date || 'Never');
                    $('#modal_total_gift_card_balance').text(__currency_trans_from_en(data.total_gift_card_balance || 0, true));
                    $('#modal_store_credit_contact_id').val(contact.id);
                    $('#modal_store_credit_amount').val('');

                    // Update gift cards list
                    var giftCardsHtml = '';
                    if (data.gift_cards && data.gift_cards.length > 0) {
                        data.gift_cards.forEach(function(card) {
                            giftCardsHtml += '<p><strong>Card:</strong> ' + card.card_number + 
                                          ' | <strong>Balance:</strong> ' + __currency_trans_from_en(card.balance, true);
                            if (card.expiry_date) {
                                giftCardsHtml += ' | <strong>Expires:</strong> ' + card.expiry_date;
                            }
                            giftCardsHtml += '</p>';
                        });
                    } else {
                        giftCardsHtml = '<p class="text-muted">No active gift cards</p>';
                    }
                    $('#modal_gift_cards_list').html(giftCardsHtml);

                    // Update preorders
                    var preordersHtml = '';
                    var preorderCount = 0;
                    if (data.preorders && data.preorders.length > 0) {
                        preorderCount = data.preorders.length;
                        data.preorders.forEach(function(preorder) {
                            var productDisplay = preorder.product_name;
                            if (preorder.artist) {
                                productDisplay = preorder.artist + ' - ' + productDisplay;
                            }
                            preordersHtml += '<tr>' +
                                '<td>' + productDisplay + '</td>' +
                                '<td>' + (preorder.sub_sku || 'N/A') + '</td>' +
                                '<td>' + preorder.quantity + '</td>' +
                                '<td>' + preorder.order_date + '</td>' +
                                '<td>' + (preorder.expected_date || 'Not set') + '</td>' +
                                '</tr>';
                        });
                    } else {
                        preordersHtml = '<tr><td colspan="5" class="text-center text-muted">No pending preorders</td></tr>';
                    }
                    $('#modal_preorders_list').html(preordersHtml);
                    $('#modal_preorder_count').text('(' + preorderCount + ' preorder' + (preorderCount !== 1 ? 's' : '') + ')');

                    // Update purchase count
                    var totalPurchases = data.total_purchases_count || 0;
                    $('#modal_purchase_count').text('(' + totalPurchases + ' purchase' + (totalPurchases !== 1 ? 's' : '') + ')');
                    
                    // Update all purchases (full history)
                    var purchasesHtml = '';
                    if (data.all_purchases && data.all_purchases.length > 0) {
                        data.all_purchases.forEach(function(purchase) {
                            var itemsText = purchase.item_count + ' item' + (purchase.item_count !== 1 ? 's' : '');
                            var viewLink = '<a href="' + '/sells/' + purchase.id + '" target="_blank" class="btn btn-xs btn-info"><i class="fa fa-eye"></i> View</a>';
                            
                            purchasesHtml += '<tr>' +
                                '<td><strong>' + purchase.invoice_no + '</strong></td>' +
                                '<td>' + purchase.date + '</td>' +
                                '<td>' + itemsText + '</td>' +
                                '<td>' + __currency_trans_from_en(purchase.total, true) + '</td>' +
                                '<td><span class="label label-' + (purchase.payment_status === 'paid' ? 'success' : purchase.payment_status === 'partial' ? 'warning' : 'danger') + '">' + purchase.payment_status + '</span></td>' +
                                '<td>' + viewLink + '</td>' +
                                '</tr>';
                        });
                    } else {
                        purchasesHtml = '<tr><td colspan="6" class="text-center text-muted">No purchases found</td></tr>';
                    }
                    $('#modal_all_purchases_list').html(purchasesHtml);

                    $('#customer_account_content').show();
                } else {
                    $('#customer_account_loading').hide();
                    $('#customer_account_content').show();
                    toastr.error('Failed to load customer information. Response: ' + JSON.stringify(response));
                    console.error('Failed to load customer info:', response);
                }
            },
            error: function(xhr, status, error) {
                $('#customer_account_loading').hide();
                $('#customer_account_content').show();
                toastr.error('Error loading customer information: ' + error);
                console.error('AJAX error:', {xhr: xhr, status: status, error: error});
            }
        });
    };
    
    // Handle view customer profile click - use delegated event for dynamically added content
    $(document).on('click', '.view_customer_profile', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent dropdown from interfering
        
        var contactId = $(this).data('contact-id') || $(this).attr('data-contact-id');
        console.log('View Profile clicked for contact ID:', contactId); // Debug log
        
        if (contactId) {
            showCustomerProfileModal(contactId);
        } else {
            toastr.error('Contact ID not found');
            console.error('Contact ID not found in element:', this);
        }
    });

    // .add_store_credit_button and the #modal_add_store_credit_btn handler are
    // bound by the shared partial (contact/partials/store_credit_js) — a reason
    // is required and "Collection purchase" routes to the Buy From Customer
    // form. The stray no-key-maps duplicates that used to live here were
    // removed so they can't double-fire alongside the shared handler.
</script>
@endif

<script type="text/javascript">
// Keep store-credit actions available even when Google Maps API key is not set.
// Opens the shared amount + reason dialog (reason required; "Collection
// purchase" routes to the Buy From Customer form).
$(document).off('click', '.add_store_credit_button').on('click', '.add_store_credit_button', function(e) {
    e.preventDefault();
    openAddStoreCreditDialog($(this).data('contact-id'), function() {
        if (typeof customer_table !== 'undefined') { customer_table.ajax.reload(); }
    });
});

// The adjust / remove store-credit dialog (.adjust_store_credit_button →
// openAdjustStoreCreditDialog, signed amount + reason, /adjust-credit) is now
// bound by the shared partial (contact/partials/store_credit_js) included
// below, so the contact list and the customer profile share one code path.
//
// #modal_add_store_credit_btn (customer account modal) is handled by the
// shared partial included below (reason required, collection routing).
</script>

@include('contact.partials.store_credit_js')
@endsection
