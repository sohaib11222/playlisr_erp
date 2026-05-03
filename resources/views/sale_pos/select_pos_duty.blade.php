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
                                <input type="radio" name="duty" value="cashier" required>
                                <strong>Cashier</strong> — on the register, ringing sales
                            </label>
                        </div>
                        <div class="radio">
                            <label style="font-weight:600;">
                                <input type="radio" name="duty" value="shipping">
                                <strong>Shipping</strong> — packing / shipping, not on register
                            </label>
                        </div>
                        <div class="radio">
                            <label style="font-weight:600;">
                                <input type="radio" name="duty" value="inventory">
                                <strong>Inventory</strong> — receiving / counts, not on register
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg">Continue</button>
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
