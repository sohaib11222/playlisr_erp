@extends('layouts.app')
@section('title', 'What are you doing today?')

@section('content')
<section class="content-header">
    <h1>What are you doing today? <small>POS — one pick per login</small></h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Choose your role for this session</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted" style="margin-bottom:16px;">
                        Clover time-clock names don’t always match who is actually on the register.
                        Pick what <strong>you</strong> are doing in the ERP right now. This is saved for this login session and logged for the sales feed / reconciliation hints — it does <strong>not</strong> change your permissions.
                    </p>

                    @if (session('status') && is_array(session('status')) && empty(session('status')['success']))
                        <div class="alert alert-danger">{{ session('status')['msg'] ?? 'Something went wrong.' }}</div>
                    @endif

                    {!! Form::open(['url' => action('SellPosController@savePosDuty'), 'method' => 'post']) !!}
                    {!! Form::hidden('intended', $intended) !!}

                    <div class="form-group">
                        <label>Store (optional — helps match Clover charges to the right location)</label>
                        <select name="location_id" class="form-control">
                            <option value="">— All / not sure —</option>
                            @foreach($business_locations as $id => $name)
                                <option value="{{ $id }}" {{ (string)session('pos_duty_location_id') === (string)$id ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Today I am primarily…</label>
                        <div class="radio" style="margin-top:8px;">
                            <label style="font-weight:600;">
                                <input type="radio" name="duty" value="cashier" id="duty_cashier" required>
                                <strong>Cashier</strong> — on the register, ringing sales
                            </label>
                        </div>
                        <div class="radio">
                            <label style="font-weight:600;">
                                <input type="radio" name="duty" value="shipping" id="duty_shipping">
                                <strong>Shipping</strong> — packing / shipping, not on register
                            </label>
                        </div>
                        <div class="radio">
                            <label style="font-weight:600;">
                                <input type="radio" name="duty" value="inventory" id="duty_inventory">
                                <strong>Inventory</strong> — receiving / counts, not on register
                            </label>
                        </div>
                    </div>

                    {{-- Opening cash count — only required when Cashier is picked.
                         Captured inline at duty selection so it can't be skipped on
                         the way to /pos/create. --}}
                    <div class="form-group" id="opening_cash_group" style="display:none; padding:14px 16px; border:2px solid #f0c419; border-radius:8px; background:#fffbe5; margin-top:6px;">
                        <label for="opening_cash" style="font-weight:700; color:#5a4d20;">
                            <i class="fa fa-cash-register"></i>
                            Count the drawer — how much cash is in it right now?
                        </label>
                        <p class="text-muted" style="font-size:13px; margin:4px 0 8px;">
                            Required before you can ring a sale. The closing count at end of shift gets checked against this.
                        </p>
                        <div class="input-group" style="max-width:240px;">
                            <span class="input-group-addon">$</span>
                            <input type="number" name="opening_cash" id="opening_cash"
                                   class="form-control" step="0.01" min="0"
                                   placeholder="e.g. 200.00"
                                   autocomplete="off">
                        </div>
                        <p id="opening_cash_error" class="text-danger" style="display:none; font-size:13px; margin-top:6px; font-weight:600;">
                            Please enter the cash amount in the drawer (zero is fine — but you must enter it).
                        </p>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg" id="duty_submit_btn">Continue</button>
                    {!! Form::close() !!}

                    <script>
                    (function () {
                        var group = document.getElementById('opening_cash_group');
                        var input = document.getElementById('opening_cash');
                        var err = document.getElementById('opening_cash_error');
                        if (!group || !input) return;

                        function refresh() {
                            var cashier = document.getElementById('duty_cashier');
                            var on = cashier && cashier.checked;
                            group.style.display = on ? 'block' : 'none';
                            if (on) {
                                input.setAttribute('required', 'required');
                            } else {
                                input.removeAttribute('required');
                                err.style.display = 'none';
                            }
                        }
                        ['duty_cashier','duty_shipping','duty_inventory'].forEach(function (id) {
                            var el = document.getElementById(id);
                            if (el) el.addEventListener('change', refresh);
                        });
                        refresh();

                        var form = group.closest('form');
                        if (form) {
                            form.addEventListener('submit', function (e) {
                                var cashier = document.getElementById('duty_cashier');
                                if (cashier && cashier.checked) {
                                    var v = String(input.value || '').trim();
                                    var num = Number(v);
                                    if (v === '' || !isFinite(num) || num < 0) {
                                        e.preventDefault();
                                        err.style.display = 'block';
                                        input.focus();
                                    }
                                }
                            });
                        }
                    })();
                    </script>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
