{{-- ===========================================================
     POS "Hold" button (Jon 2026-10-23).

     Turns the items in the cart into holds (/holds/from-pos), then clears
     the cart like Cancel Sale does. Fully self-contained: the PHP is
     try/catch'd and the script is wrapped so a failure here can never
     break ringing a sale.
     ============================================================ --}}
@php
    $hpEmployees = [];
    $hpDefaults = ['customer' => '', 'employee' => '', 'customer_max' => '', 'employee_max' => '', 'customer_days' => 14, 'employee_days' => 7];
    try {
        foreach (\App\User::forDropdown((int) session('user.business_id'), false, false, false, false, true) as $hpId => $hpName) {
            $hpName = trim(preg_replace('/\s+/', ' ', (string) $hpName));
            if ($hpName !== '') $hpEmployees[$hpId] = $hpName;
        }
        asort($hpEmployees);
        $hpDefaults = [
            'customer' => \App\Services\ProductHolds::defaultUntil('customer')->toDateString(),
            'employee' => \App\Services\ProductHolds::defaultUntil('employee')->toDateString(),
            'customer_max' => \App\Services\ProductHolds::latestUntil('customer')->toDateString(),
            'employee_max' => \App\Services\ProductHolds::latestUntil('employee')->toDateString(),
            'customer_days' => \App\Services\ProductHolds::MAX_DAYS_CUSTOMER,
            'employee_days' => \App\Services\ProductHolds::MAX_DAYS_EMPLOYEE,
        ];
    } catch (\Throwable $e) {
        // modal still opens; the server applies the defaults
    }
@endphp
<style>
    #pos_hold_modal .modal-content { border: 1px solid #ECE3CF; border-radius: 12px; font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; background: #FFFFFF; }
    #pos_hold_modal .modal-header { border-bottom: 1px solid #ECE3CF; }
    #pos_hold_modal .modal-footer { border-top: 1px solid #ECE3CF; }
    #pos_hold_modal .modal-title { font-weight: 700; }
    #pos_hold_modal label { font-size: 12px; font-weight: 700; color: #1F1B16; }
    #pos_hold_modal .form-control { border: 1px solid #DFD2B3; border-radius: 9px; box-shadow: none; color: #1F1B16; }
    #pos_hold_modal .hp-pills { display: flex; gap: 6px; margin-bottom: 12px; }
    #pos_hold_modal .hp-pill { padding: 7px 16px; border-radius: 999px; border: 1px solid #DFD2B3; background: #fff; color: #1F1B16; font-weight: 600; font-size: 13px; cursor: pointer; }
    #pos_hold_modal .hp-pill.on { background: #DCEBFF; border-color: #B7D3F7; color: #1F4E8C; }
    #pos_hold_modal .hp-help { font-size: 12px; color: #3B3226; margin-top: 4px; }
    #pos_hold_modal .hp-save { background: #2F6B3E; border-color: #2F6B3E; color: #fff; border-radius: 9px; font-weight: 700; }
    #pos_hold_modal .hp-cancel { border-radius: 9px; border: 1px solid #DFD2B3; background: #fff; color: #1F1B16; font-weight: 600; }
</style>

<div class="modal fade" id="pos_hold_modal" tabindex="-1" role="dialog" aria-labelledby="pos_hold_modal_title">
    <div class="modal-dialog modal-sm" role="document" style="max-width:420px;width:auto;">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="pos_hold_modal_title">Put on hold</h4>
            </div>
            <div class="modal-body">
                <p id="pos_hold_summary" style="margin:0 0 10px;font-size:13px;"></p>
                <div class="hp-pills">
                    <button type="button" class="hp-pill on" data-type="customer">Customer</button>
                    <button type="button" class="hp-pill" data-type="employee">Employee</button>
                </div>
                <div class="form-group" id="pos_hold_customer_wrap">
                    <label for="pos_hold_customer_name">Customer name</label>
                    <input type="text" class="form-control" id="pos_hold_customer_name" maxlength="190" autocomplete="off">
                </div>
                <div class="form-group" id="pos_hold_employee_wrap" style="display:none;">
                    <label for="pos_hold_employee">Employee</label>
                    <select class="form-control" id="pos_hold_employee">
                        <option value="">Pick an employee</option>
                        @foreach($hpEmployees as $hpId => $hpName)
                            <option value="{{ $hpId }}">{{ $hpName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="pos_hold_until">Hold until</label>
                    <input type="date" class="form-control" id="pos_hold_until" value="{{ $hpDefaults['customer'] }}" min="{{ \Carbon\Carbon::today()->toDateString() }}" max="{{ $hpDefaults['customer_max'] }}">
                    <div class="hp-help" id="pos_hold_until_help">Up to {{ $hpDefaults['customer_days'] * 24 }} hours.</div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="pos_hold_notes">Note</label>
                    <input type="text" class="form-control" id="pos_hold_notes" placeholder="Optional" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn hp-cancel" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn hp-save" id="pos_hold_save">Place hold</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    function onReady(fn, tries) {
        if (typeof jQuery === 'undefined') {
            if ((tries || 0) > 300) return;
            setTimeout(function () { onReady(fn, (tries || 0) + 1); }, 50);
            return;
        }
        jQuery(fn);
    }
    onReady(function ($) {
        try {
            var defaults = {
                customer: { until: @json($hpDefaults['customer']), max: @json($hpDefaults['customer_max']), days: {{ (int) $hpDefaults['customer_days'] }} },
                employee: { until: @json($hpDefaults['employee']), max: @json($hpDefaults['employee_max']), days: {{ (int) $hpDefaults['employee_days'] }} }
            };
            var holdType = 'customer';
            var busy = false;

            function notify(kind, msg) {
                if (window.toastr && toastr[kind]) toastr[kind](msg); else alert(msg);
            }

            function setType(type) {
                holdType = type === 'employee' ? 'employee' : 'customer';
                $('#pos_hold_modal .hp-pill').removeClass('on').filter('[data-type="' + holdType + '"]').addClass('on');
                $('#pos_hold_customer_wrap').toggle(holdType === 'customer');
                $('#pos_hold_employee_wrap').toggle(holdType === 'employee');
                if (defaults[holdType].until) $('#pos_hold_until').val(defaults[holdType].until);
                if (defaults[holdType].max) $('#pos_hold_until').attr('max', defaults[holdType].max);
                $('#pos_hold_until_help').text('Up to ' + (holdType === 'customer' ? (defaults[holdType].days * 24) + ' hours.' : defaults[holdType].days + ' days.'));
            }

            function readQty($row) {
                var $q = $row.find('input.pos_quantity');
                var n = NaN;
                try { if (typeof __read_number === 'function') n = __read_number($q); } catch (e) {}
                if (isNaN(n)) n = parseFloat(String($q.val() || '1').replace(/[^0-9.]/g, ''));
                return n > 0 ? n : 1;
            }

            function cartItems() {
                var items = [];
                $('#pos_table tbody tr.product_row').each(function () {
                    var $row = $(this);
                    var vid = parseInt($row.find('input.row_variation_id').val(), 10);
                    if (!vid) return;
                    items.push({
                        variation_id: vid,
                        product_id: parseInt($row.find('input.product_id').val(), 10) || null,
                        quantity: readQty($row)
                    });
                });
                return items;
            }

            // Selected POS customer, unless it's the walk-in default.
            function posCustomer() {
                var id = parseInt($('#customer_id').val(), 10);
                var walkIn = parseInt($('#default_customer_id').val(), 10);
                if (!id || (walkIn && id === walkIn)) return { id: null, name: '' };
                var name = $.trim($('#customer_id option:selected').text() || '');
                name = $.trim(name.replace(/\s*\([^)]*\)\s*$/, ''));
                if (/walk-?in/i.test(name)) return { id: null, name: '' };
                return { id: id, name: name };
            }

            $(document).on('click', '#pos-hold-items', function () {
                try {
                    var items = cartItems();
                    if (!items.length) { notify('warning', 'Add the items to hold to the cart first.'); return; }
                    var count = 0;
                    items.forEach(function (it) { count += it.quantity; });
                    $('#pos_hold_summary').text(count + ' item' + (count === 1 ? '' : 's') + ' in the cart will go in the hold bin and come off the website.');
                    var c = posCustomer();
                    $('#pos_hold_customer_name').val(c.name).data('contact-id', c.id);
                    $('#pos_hold_employee').val('');
                    $('#pos_hold_notes').val('');
                    setType('customer');
                    $('#pos_hold_modal').modal('show');
                } catch (err) {
                    if (window.console) console.error('Hold button failed', err);
                }
            });

            $(document).on('click', '#pos_hold_modal .hp-pill', function () { setType($(this).attr('data-type')); });

            $(document).on('click', '#pos_hold_save', function () {
                if (busy) return;
                try {
                    var items = cartItems();
                    if (!items.length) { notify('warning', 'The cart is empty.'); return; }
                    var data = {
                        location_id: $('#location_id').val(),
                        held_for_type: holdType,
                        hold_until: $('#pos_hold_until').val(),
                        notes: $('#pos_hold_notes').val(),
                        items: items
                    };
                    if (holdType === 'employee') {
                        data.held_for_user_id = $('#pos_hold_employee').val();
                        if (!data.held_for_user_id) { notify('warning', 'Pick the employee.'); return; }
                    } else {
                        data.held_for_name = $.trim($('#pos_hold_customer_name').val());
                        data.contact_id = $('#pos_hold_customer_name').data('contact-id') || '';
                        if (!data.held_for_name) { notify('warning', 'Enter the customer name.'); return; }
                    }
                    busy = true;
                    $('#pos_hold_save').prop('disabled', true);
                    $.ajax({ method: 'POST', url: '/holds/from-pos', data: data, dataType: 'json' })
                        .done(function (res) {
                            if (res && res.success) {
                                $('#pos_hold_modal').modal('hide');
                                notify('success', res.msg || 'On hold.');
                                // Clear the cart the same way Cancel Sale does.
                                try { if (typeof reset_pos_form === 'function') reset_pos_form(); } catch (e) {
                                    if (window.console) console.error('reset_pos_form after hold failed', e);
                                }
                            } else {
                                notify('error', (res && res.msg) || 'Could not place the hold.');
                            }
                        })
                        .fail(function () { notify('error', 'Could not place the hold. Try again or use the Holds page.'); })
                        .always(function () { busy = false; $('#pos_hold_save').prop('disabled', false); });
                } catch (err) {
                    busy = false;
                    $('#pos_hold_save').prop('disabled', false);
                    if (window.console) console.error('Hold save failed', err);
                }
            });
        } catch (err) {
            if (window.console) console.error('POS hold script failed', err);
        }
    });
})();
</script>
