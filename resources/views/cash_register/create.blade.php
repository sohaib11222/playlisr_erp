@extends('layouts.app')
@section('title',  __('cash_register.open_cash_register'))

@section('content')
<style type="text/css">



</style>
<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('cash_register.open_cash_register')</h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
{!! Form::open(['url' => action('CashRegisterController@store'), 'method' => 'post', 
'id' => 'add_cash_register_form' ]) !!}
  <div class="box box-solid">
    <div class="box-body">
    <br><br><br>
    <input type="hidden" name="sub_type" value="{{$sub_type}}">
      <div class="row">
        @if($business_locations->count() > 0)
        <div class="col-sm-8 col-sm-offset-2">
          <div class="form-group">
            {!! Form::label('amount', __('cash_register.cash_in_hand') . ':*') !!}
            {!! Form::text('amount', null, ['class' => 'form-control input_number',
              'id' => 'cash_in_hand_amount',
              'placeholder' => __('cash_register.enter_amount'), 'required']); !!}
            <p class="help-block text-muted"><small>@lang('cash_register.opening_balance_help')</small></p>

            {{-- Over-$500 safe alert (Sarah 2026-05-07): if the cashier
                 reports more than $500 in the drawer at open, ask them
                 to move the excess (rounded down to the nearest $100)
                 into the safe and recount what's left. Soft warning,
                 doesn't block the open-register submit. Big amount +
                 post-it instruction because people frequently put the
                 wrong amount in the safe. --}}
            <div id="cr-safe-alert" style="display:none; margin-top:14px;
                background:#FFE5DA; border:2px solid #E8A07A; border-radius:12px;
                padding:18px 20px; color:#6B2A14;">
              <div style="font-size:11px; font-weight:800; letter-spacing:.14em;
                  text-transform:uppercase; color:#6B2A14; margin-bottom:6px;">
                ⚠ Heads up — safe drop
              </div>
              <div style="font-size:28px; font-weight:800; line-height:1.15; color:#6B2A14;
                  letter-spacing:-.01em;">
                Put <span id="cr-safe-amount" style="font-variant-numeric:tabular-nums;">$0</span>
                in the safe.
              </div>
              <div style="font-size:15px; font-weight:700; margin-top:10px; color:#6B2A14;">
                Count what you're putting in the safe <u>very</u> carefully —
                people often drop the wrong amount.
              </div>
              <div style="font-size:14px; font-weight:600; margin-top:8px; color:#6B2A14;">
                Stick a post-it on the bundle with <strong>your initials</strong>
                and the <strong>amount you're dropping</strong>.
              </div>
            </div>
          </div>
        </div>
        @if(count($business_locations) > 1)
        <div class="clearfix"></div>
        <div class="col-sm-8 col-sm-offset-2">
          <div class="form-group">
            {!! Form::label('location_id', __('business.business_location') . ':') !!}
              {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2',
              'placeholder' => __('lang_v1.select_location')]); !!}
          </div>
        </div>
        @else
          {!! Form::hidden('location_id', array_key_first($business_locations->toArray()) ); !!}
        @endif
        <div class="col-sm-8 col-sm-offset-2">
          <button type="submit" class="btn btn-primary pull-right">@lang('cash_register.open_register')</button>
        </div>
        @else
        <div class="col-sm-8 col-sm-offset-2 text-center">
          <h3>@lang('lang_v1.no_location_access_found')</h3>
        </div>
      @endif
      </div>
      <br><br><br>
    </div>
  </div>
  {!! Form::close() !!}
</section>
<!-- /.content -->

<script>
  $(function () {
    var $input = $('#cash_in_hand_amount');
    var $alert = $('#cr-safe-alert');
    var $amount = $('#cr-safe-amount');

    function recheck() {
      var raw = ($input.val() || '').toString().replace(/,/g, '').trim();
      var val = parseFloat(raw);
      if (!isFinite(val)) { $alert.hide(); return; }
      // Suggest moving the excess over $500 to the safe, rounded down
      // to the nearest $100. So $1250 → $700 (drawer becomes $550).
      // Only show when the suggested move is at least $100.
      var toSafe = Math.floor((val - 500) / 100) * 100;
      if (toSafe >= 100) {
        $amount.text('$' + toSafe.toLocaleString('en-US'));
        $alert.show();
      } else {
        $alert.hide();
      }
    }

    $input.on('input change keyup blur', recheck);
    recheck();
  });
</script>
@endsection