@extends('layouts.app')
@section('title',  __('cash_register.open_cash_register'))

@section('content')
@php
    // Sarah 2026-05-11: prefill from the duty-picker session so cashiers
    // who already counted the drawer + picked a store there don't have to
    // do it again here. Falls back to old behavior if the session keys are
    // missing (e.g. arriving via a deep link that skipped the duty picker).
    $prefillAmount = null;
    if (session('pos_duty') === 'cashier' && session('pos_duty_opening_cash') !== null) {
        $prefillAmount = number_format((float) session('pos_duty_opening_cash'), 2, '.', '');
    }
    $prefillLoc = session('pos_duty_location_id');
    if ($prefillLoc !== null && $prefillLoc !== '' && !$business_locations->has((int) $prefillLoc)) {
        $prefillLoc = null;
    }
@endphp
<style type="text/css">
  /* Hero "Cash in hand" input — Sarah 2026-05-08: this is the primary
     action on the page; make it the visual anchor. Same input shape as
     the safe-drop box below but larger font, thicker border, accent
     stripe so the eye lands here first. */
  .ocr-hero-label {
    display: block;
    font-size: 13px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .08em;
    color: #5A4410;
    margin-bottom: 8px;
  }
  .ocr-hero-wrap {
    display: flex; align-items: center; gap: 10px;
    background: #fff;
    border: 3px solid #E8CF68;
    border-radius: 14px;
    padding: 14px 20px;
    box-shadow: 0 0 0 4px rgba(232, 207, 104, .25),
                0 4px 12px rgba(0, 0, 0, .06);
    max-width: 520px;
  }
  .ocr-hero-currency {
    font-size: 32px; font-weight: 800; color: #5A4410; line-height: 1;
  }
  .ocr-hero-input {
    flex: 1;
    border: none; outline: none; background: transparent;
    font-family: inherit; font-size: 36px; font-weight: 800;
    color: #1F1B16; padding: 0; letter-spacing: -.02em;
    font-variant-numeric: tabular-nums;
    width: 100%;
  }
  .ocr-hero-input::placeholder { color: #c9b670; }
  .ocr-hero-help {
    margin-top: 8px; max-width: 520px;
    font-size: 12px; color: #8E8273;
  }

  /* Safe-drop input — sub-hero. Same shape as the hero but smaller and
     unaccented so the visual hierarchy reads "count first, drop second". */
  .ocr-drop-label {
    display: block;
    font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
    color: #5A4410;
    margin-bottom: 6px;
  }
  .ocr-drop-label .ocr-drop-sub {
    font-weight: 500; text-transform: none; letter-spacing: 0;
    color: #8E8273;
  }
  .ocr-drop-wrap {
    display: inline-flex; align-items: center; gap: 8px;
    background: #fff;
    border: 1.5px solid #DFD2B3;
    border-radius: 10px;
    padding: 10px 16px;
  }
  .ocr-drop-currency {
    font-size: 22px; font-weight: 700; color: #5A4410; line-height: 1;
  }
  .ocr-drop-input {
    border: none; outline: none; background: transparent;
    font-family: inherit; font-size: 22px; font-weight: 700;
    color: #1F1B16; width: 140px; padding: 0;
    font-variant-numeric: tabular-nums;
  }
  .ocr-drop-hint {
    margin-left: 12px; font-size: 13px; color: #8E8273; font-weight: 600;
    vertical-align: middle;
  }

  /* Pill picker for Pico / Hollywood (Sarah 2026-05-07): replace the
     select2 dropdown so the cashier picks a location with one big tap. */
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
{{-- Sarah 2026-05-13: another cashier already has a register open at
     this store. Block the new open until that shift is closed so the
     prior cashier's closing count + safe drop get recorded.
     (Same-cashier double-open is handled in the controller — they're
     just redirected back to POS rather than shown this banner, since
     one-register-per-shift policy means "keep using the one you have".) --}}
@if(session('error'))
    <div style="background:#FDECCB; border:2px solid #E0A93A; border-radius:12px; padding:18px 22px; margin:18px auto; max-width:780px; color:#3A2E0F;">
        <div style="font-size:12px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:#7A4E0A; margin-bottom:8px;">
            ⚠ Can't open yet
        </div>
        <div style="font-size:15px; line-height:1.55; font-weight:600;">
            {{ session('error') }}
        </div>
    </div>
@endif

{{-- Sarah 2026-05-13: FYI heads-up — another cashier still has an open
     register somewhere. Soft warning, not a block. If the user is the
     cashier taking over, this nudges them to ask the prior cashier to
     close properly first (so the prior cashier types their own closing
     count) rather than triggering the locked-amount handover-close. --}}
@if(!empty($other_open_cashiers))
    <div style="background:#E8F0FE; border:2px solid #6A8FD1; border-radius:12px; padding:18px 22px; margin:18px auto; max-width:780px; color:#1F2C4D;">
        <div style="font-size:12px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:#3A52A0; margin-bottom:8px;">
            ℹ Heads up — other cashier(s) still open
        </div>
        <div style="font-size:15px; line-height:1.55;">
            @foreach($other_open_cashiers as $c)
                <div style="margin-bottom:6px;">
                    <strong>{{ $c['name'] }}</strong> has an open register at
                    <strong>{{ $c['location'] }}</strong> since {{ $c['opened'] }}.
                </div>
            @endforeach
            <div style="margin-top:10px; font-weight:600;">
                If you're the cashier taking over, please ask them to close their register first
                so they count out their own drawer. If you proceed anyway, the system will
                close their shift using <em>your</em> count of the drawer.
            </div>
        </div>
    </div>
@endif
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
            <label for="cash_in_hand_amount" class="ocr-hero-label">
              Cash in hand <span style="color:#b91c1c;">*</span>
            </label>
            <div class="ocr-hero-wrap">
              <span class="ocr-hero-currency">$</span>
              {!! Form::text('amount', $prefillAmount, [
                'class' => 'ocr-hero-input input_number',
                'id' => 'cash_in_hand_amount',
                'placeholder' => '0.00',
                'required',
                'autofocus',
                'data-decimal' => '1',
              ]); !!}
            </div>
            <p class="ocr-hero-help">
              Count the drawer right now. After your safe drop below, the rest is your opening balance for the shift.
            </p>

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

            {{-- Capture the actual amount the cashier moved at open. Left
                 BLANK by design (Sarah 2026-05-08) so cashiers must
                 deliberately type what they dropped — pre-filling risks
                 recording a phantom drop when nothing was moved. The
                 suggestion is shown next to the input as a hint. Opening
                 balance = (cash counted - safe drop). --}}
            <div style="margin-top:14px;">
              <label for="cash_in_hand_safe_drop" class="ocr-drop-label">
                Amount you put in the safe
                <span class="ocr-drop-sub">— leave blank if nothing</span>
              </label>
              <div class="ocr-drop-wrap">
                <span class="ocr-drop-currency">$</span>
                <input type="text" name="safe_drop_amount" id="cash_in_hand_safe_drop"
                       class="ocr-drop-input input_number" placeholder="0" data-decimal="1">
              </div>
              <span id="cr-safe-drop-hint" class="ocr-drop-hint"></span>
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
            <input type="hidden" name="location_id" id="location_id" value="{{ $prefillLoc ?? '' }}">
            <div class="ocr-loc-pills" id="ocr-loc-pills">
              @foreach($business_locations as $loc_id => $loc_name)
                <button type="button"
                        class="ocr-loc-pill {{ $prefillLoc !== null && (int) $prefillLoc === (int) $loc_id ? 'is-selected' : '' }}"
                        data-loc-id="{{ $loc_id }}">
                  {{-- Title-case the label so DB values stored as "pico"
                       render as "Pico" without forcing Sarah to fix the
                       location name in settings. --}}
                  {{ ucwords(strtolower(trim($loc_name))) }}
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

      var hintEl = document.getElementById('cr-safe-drop-hint');
      var lastSeen = null;
      function recheck() {
        var raw = (input.value || '').toString().replace(/,/g, '').trim();
        if (raw === lastSeen) return;
        lastSeen = raw;
        var val = parseFloat(raw);
        if (!isFinite(val)) {
          alertEl.style.display = 'none';
          if (hintEl) hintEl.textContent = '';
          return;
        }
        // Excess above $500, rounded down to the nearest $100.
        // $1250 → $700 (leaves $550 in the drawer).
        var toSafe = Math.floor((val - 500) / 100) * 100;
        if (toSafe >= 100) {
          var label = '$' + toSafe.toLocaleString('en-US');
          amountEl.textContent = label;
          alertEl.style.display = 'block';
          if (hintEl) hintEl.textContent = 'Suggested: ' + label;
        } else {
          alertEl.style.display = 'none';
          if (hintEl) hintEl.textContent = '';
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