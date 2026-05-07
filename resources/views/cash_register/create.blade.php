@extends('layouts.app')
@section('title',  __('cash_register.open_cash_register'))

@section('content')
<style type="text/css">
  /* Pill picker for Pico / Hollywood (Sarah 2026-05-07): replace the
     select2 dropdown so the cashier picks a location with one big tap.
     Layout: two side-by-side pills that grow to fill the row. */
  .ocr-loc-pills {
    display: flex; gap: 14px; flex-wrap: wrap;
    margin-top: 6px;
  }
  .ocr-loc-pill {
    flex: 1 1 220px;
    min-height: 96px;
    padding: 18px 22px;
    border: 2px solid #DFD2B3;
    background: #fff;
    border-radius: 16px;
    font-family: inherit;
    font-size: 22px;
    font-weight: 800;
    color: #1F1B16;
    letter-spacing: -.01em;
    cursor: pointer;
    transition: transform .06s ease, border-color .12s ease,
                background .12s ease, box-shadow .12s ease;
    text-align: center;
    box-shadow: 0 1px 2px rgba(31,27,22,.06);
  }
  .ocr-loc-pill:hover {
    border-color: #1F1B16;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(31,27,22,.10);
  }
  .ocr-loc-pill.is-selected {
    background: #1F1B16; color: #FAF6EE; border-color: #1F1B16;
    box-shadow: 0 4px 14px rgba(31,27,22,.20);
  }
</style>
<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('cash_register.open_cash_register')</h1>
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
                 doesn't block the open-register submit. --}}
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
                Count what you're putting in the safe <u>very</u> carefully.
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
            {{-- Pill-button location picker. Renders one big tap-target per
                 location (typically Pico + Hollywood). Selecting a pill
                 writes the id into the hidden #location_id input that the
                 form posts. --}}
            <input type="hidden" name="location_id" id="location_id" value="">
            <div class="ocr-loc-pills" id="ocr-loc-pills">
              @foreach($business_locations as $loc_id => $loc_name)
                <button type="button" class="ocr-loc-pill"
                        data-loc-id="{{ $loc_id }}">
                  {{ $loc_name }}
                </button>
              @endforeach
            </div>
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
  /* Safe-drop alert + location pill picker — vanilla JS so we don't depend
     on jQuery ready timing or the input_number plugin's event handling
     (the previous jQuery `$('...').on('input', ...)` version didn't fire
     on this page). Polling watch is a safety net for any plugin that
     mutates the input value without dispatching events. */
  (function () {
    function go() {
      var input = document.getElementById('cash_in_hand_amount');
      var alertEl = document.getElementById('cr-safe-alert');
      var amountEl = document.getElementById('cr-safe-amount');
      if (!input || !alertEl || !amountEl) {
        // DOM not ready yet — try again on the next tick.
        return setTimeout(go, 50);
      }

      var lastSeen = null;
      function recheck() {
        var raw = (input.value || '').toString().replace(/,/g, '').trim();
        if (raw === lastSeen) return;
        lastSeen = raw;
        var val = parseFloat(raw);
        if (!isFinite(val)) {
          alertEl.style.display = 'none';
          return;
        }
        // Excess above $500, rounded down to the nearest $100.
        // $1250 → $700 (leaves $550 in the drawer).
        var toSafe = Math.floor((val - 500) / 100) * 100;
        if (toSafe >= 100) {
          amountEl.textContent = '$' + toSafe.toLocaleString('en-US');
          alertEl.style.display = 'block';
        } else {
          alertEl.style.display = 'none';
        }
      }

      ['input', 'change', 'keyup', 'blur', 'paste'].forEach(function (ev) {
        input.addEventListener(ev, recheck);
      });
      // Polling fallback in case the input_number plugin sets values
      // programmatically without dispatching events.
      setInterval(recheck, 250);
      recheck();

      // Location pills: clicking a pill marks it selected and copies the
      // id into the hidden #location_id input the form posts. Clicking
      // again on the same pill keeps it selected (no toggle-off — the
      // form requires a location to submit).
      var pillsHost = document.getElementById('ocr-loc-pills');
      var locInput  = document.getElementById('location_id');
      if (pillsHost && locInput) {
        pillsHost.addEventListener('click', function (e) {
          var btn = e.target.closest('.ocr-loc-pill');
          if (!btn) return;
          var pills = pillsHost.querySelectorAll('.ocr-loc-pill');
          for (var i = 0; i < pills.length; i++) {
            pills[i].classList.remove('is-selected');
          }
          btn.classList.add('is-selected');
          locInput.value = btn.getAttribute('data-loc-id') || '';
        });
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', go);
    } else {
      go();
    }
  })();
</script>
@endsection