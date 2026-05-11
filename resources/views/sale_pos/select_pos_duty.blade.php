@extends('layouts.app')
@section('title', 'What are you doing today?')

@section('content')
{{-- Sarah 2026-05-11: reskin to match /pos/create design language
     (cream surface, pastel yellow accent, Inter Tight, rounded tiles).
     Scoped under .pos-duty-shell so it doesn't bleed into the rest of
     the app. Functionality and form fields unchanged. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>

<style>
.pos-duty-shell {
    --d-bg: #FAF6EE;
    --d-surface: #FFFFFF;
    --d-surface-2: #F7F1E3;
    --d-ink: #1F1B16;
    --d-ink-2: #5A5045;
    --d-ink-3: #8E8273;
    --d-line: #ECE3CF;
    --d-line-2: #DFD2B3;
    --d-accent: #FFF2B3;
    --d-accent-deep: #E8CF68;
    --d-accent-soft: #FFF9DB;
    --d-accent-text: #5A4410;
    --d-radius: 12px;
    --d-radius-sm: 10px;

    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--d-ink);
    -webkit-font-smoothing: antialiased;
    background: var(--d-bg);
    max-width: 760px;
    margin: 12px auto 40px;
    padding: 0 16px;
}
.pos-duty-shell *,
.pos-duty-shell *::before,
.pos-duty-shell *::after { box-sizing: border-box; }

.pos-duty-shell .duty-header {
    margin: 12px 4px 18px;
}
.pos-duty-shell .duty-header h1 {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -.01em;
    color: var(--d-ink);
    margin: 0 0 4px;
    line-height: 1.15;
}
.pos-duty-shell .duty-header .sub {
    font-size: 13px;
    color: var(--d-ink-3);
    font-weight: 500;
}

.pos-duty-shell .duty-card {
    background: var(--d-surface);
    border: 1px solid var(--d-line);
    border-radius: var(--d-radius);
    box-shadow: 0 1px 2px rgba(31,27,22,.06);
    padding: 22px 24px;
}

.pos-duty-shell .duty-blurb {
    font-size: 13.5px;
    line-height: 1.5;
    color: var(--d-ink-2);
    margin: 0 0 18px;
}
.pos-duty-shell .duty-blurb strong { color: var(--d-ink); }

.pos-duty-shell .alert-danger {
    border: 1px solid #E0B4A7;
    background: #FBEDE8;
    color: #7A2F22;
    border-radius: var(--d-radius-sm);
    padding: 10px 14px;
    margin-bottom: 14px;
    font-size: 13.5px;
}

.pos-duty-shell .field {
    display: block;
    margin-bottom: 18px;
}
.pos-duty-shell .field-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--d-ink-3);
    display: block;
    margin-bottom: 8px;
}
.pos-duty-shell .field-help {
    font-size: 12px;
    color: var(--d-ink-3);
    font-weight: 500;
    margin-left: 6px;
    text-transform: none;
    letter-spacing: 0;
}

/* Store pills — horizontal flex-wrap, smaller than duty pills since
   labels are short ("Pico", "HW", etc.). Same checked accent treatment. */
.pos-duty-shell .store-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.pos-duty-shell .store-pill {
    position: relative;
    display: inline-flex;
    align-items: center;
    padding: 10px 18px;
    min-height: 44px;
    background: var(--d-surface);
    border: 2px solid var(--d-line);
    border-radius: 999px;
    cursor: pointer;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    font-size: 14px;
    font-weight: 600;
    color: var(--d-ink);
    line-height: 1;
    transition: border-color .12s ease, background .12s ease, box-shadow .12s ease, transform .08s ease;
    margin: 0;
}
.pos-duty-shell .store-pill:hover {
    border-color: var(--d-line-2);
    background: var(--d-surface-2);
}
.pos-duty-shell .store-pill:active { transform: scale(.98); }
.pos-duty-shell .store-pill input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.pos-duty-shell .store-pill:has(input:checked) {
    border-color: var(--d-accent-deep);
    background: var(--d-accent-soft);
    color: var(--d-accent-text);
    box-shadow: 0 0 0 3px rgba(232,207,104,.3);
}

/* Duty options — big tappable pills. Single column so each pill stretches
   the full width of the card; finger-sized hit target on touchscreens. */
.pos-duty-shell .duty-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.pos-duty-shell .duty-option {
    position: relative;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 22px;
    min-height: 64px;
    background: var(--d-surface);
    border: 2px solid var(--d-line);
    border-radius: 999px;
    cursor: pointer;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    transition: border-color .12s ease, background .12s ease, box-shadow .12s ease, transform .08s ease;
    margin: 0;
}
.pos-duty-shell .duty-option:hover {
    border-color: var(--d-line-2);
    background: var(--d-surface-2);
}
.pos-duty-shell .duty-option:active {
    transform: scale(.99);
}
.pos-duty-shell .duty-option input[type="radio"] {
    appearance: none;
    -webkit-appearance: none;
    width: 22px;
    height: 22px;
    border: 2px solid var(--d-line-2);
    border-radius: 999px;
    margin: 0;
    flex: 0 0 auto;
    cursor: pointer;
    background: #fff;
    position: relative;
}
.pos-duty-shell .duty-option input[type="radio"]:checked {
    border-color: var(--d-accent-deep);
}
.pos-duty-shell .duty-option input[type="radio"]:checked::after {
    content: "";
    position: absolute;
    inset: 4px;
    border-radius: 999px;
    background: var(--d-accent-deep);
}
.pos-duty-shell .duty-option:has(input:checked) {
    border-color: var(--d-accent-deep);
    background: var(--d-accent-soft);
    box-shadow: 0 0 0 3px rgba(232,207,104,.3);
}
.pos-duty-shell .duty-option .opt-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--d-ink);
    line-height: 1.2;
    letter-spacing: -.005em;
    flex: 1 1 auto;
}
.pos-duty-shell .duty-option:has(input:checked) .opt-title { color: var(--d-accent-text); }

/* Opening cash callout — keep the existing "warning yellow" feel but
   re-skinned to match the accent tokens used everywhere else here. */
.pos-duty-shell .opening-cash {
    margin-top: 14px;
    padding: 16px 18px;
    background: var(--d-accent);
    border: 2px solid var(--d-accent-deep);
    border-radius: var(--d-radius-sm);
    box-shadow: 0 0 0 3px rgba(232,207,104,.25);
    position: relative;
}
.pos-duty-shell .opening-cash::before {
    content: "REQUIRED FOR CASHIER";
    position: absolute;
    top: -9px;
    left: 14px;
    background: var(--d-ink);
    color: var(--d-accent);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .14em;
    padding: 3px 10px;
    border-radius: 999px;
}
.pos-duty-shell .opening-cash label {
    display: block;
    font-weight: 700;
    color: var(--d-accent-text);
    font-size: 14px;
    margin: 4px 0 4px;
}
.pos-duty-shell .opening-cash .hint {
    font-size: 12.5px;
    color: var(--d-accent-text);
    opacity: .8;
    margin: 0 0 10px;
}
.pos-duty-shell .opening-cash .money {
    display: inline-flex;
    align-items: stretch;
    max-width: 240px;
    width: 100%;
    border: 1px solid var(--d-accent-deep);
    border-radius: var(--d-radius-sm);
    overflow: hidden;
    background: #fff;
}
.pos-duty-shell .opening-cash .money .sym {
    background: var(--d-accent-soft);
    color: var(--d-accent-text);
    font-weight: 700;
    padding: 0 12px;
    display: inline-flex;
    align-items: center;
    border-right: 1px solid var(--d-accent-deep);
}
.pos-duty-shell .opening-cash .money input {
    flex: 1 1 auto;
    border: none;
    outline: none;
    height: 42px;
    padding: 0 12px;
    font-size: 16px;
    font-weight: 600;
    color: var(--d-ink);
    background: #fff;
    font-family: inherit;
    min-width: 0;
}
.pos-duty-shell .opening-cash .err {
    display: none;
    color: #7A2F22;
    font-size: 12.5px;
    font-weight: 600;
    margin-top: 8px;
}

.pos-duty-shell .duty-actions {
    margin-top: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.pos-duty-shell .duty-submit {
    background: var(--d-ink);
    color: #fff;
    border: none;
    border-radius: var(--d-radius-sm);
    height: 46px;
    padding: 0 22px;
    font-size: 14.5px;
    font-weight: 700;
    letter-spacing: .02em;
    cursor: pointer;
    font-family: inherit;
    transition: background .12s ease, transform .08s ease;
}
.pos-duty-shell .duty-submit:hover { background: #3a2e22; }
.pos-duty-shell .duty-submit:active { transform: translateY(1px); }
</style>

<section class="content" style="background:#FAF6EE;">
    <div class="pos-duty-shell">
        <div class="duty-header">
            <h1>What are you doing today?</h1>
            <div class="sub">POS — one pick per login</div>
        </div>

        <div class="duty-card">
            <p class="duty-blurb">
                Clover time-clock names don't always match who is actually on the register.
                Pick what <strong>you</strong> are doing in the ERP right now. This is saved for this login
                session and logged for the sales feed / reconciliation hints — it does <strong>not</strong>
                change your permissions.
            </p>

            @if (session('status') && is_array(session('status')) && empty(session('status')['success']))
                <div class="alert-danger">{{ session('status')['msg'] ?? 'Something went wrong.' }}</div>
            @endif

            {!! Form::open(['url' => action('SellPosController@savePosDuty'), 'method' => 'post']) !!}
            {!! Form::hidden('intended', $intended) !!}

            @php $selectedLoc = (string) session('pos_duty_location_id'); @endphp
            <div class="field">
                <label class="field-label">
                    Store
                    <span class="field-help">— optional, helps match Clover charges to the right location</span>
                </label>
                <div class="store-pills">
                    <label class="store-pill">
                        <input type="radio" name="location_id" value="" {{ $selectedLoc === '' ? 'checked' : '' }}>
                        All / not sure
                    </label>
                    @foreach($business_locations as $id => $name)
                        <label class="store-pill">
                            <input type="radio" name="location_id" value="{{ $id }}" {{ $selectedLoc === (string)$id ? 'checked' : '' }}>
                            {{ $name }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="field">
                <label class="field-label">Today I am primarily…</label>
                <div class="duty-options">
                    <label class="duty-option">
                        <input type="radio" name="duty" value="cashier" id="duty_cashier" required>
                        <span class="opt-title">Cashier</span>
                    </label>
                    <label class="duty-option">
                        <input type="radio" name="duty" value="shipping" id="duty_shipping">
                        <span class="opt-title">Shipping</span>
                    </label>
                    <label class="duty-option">
                        <input type="radio" name="duty" value="inventory" id="duty_inventory">
                        <span class="opt-title">Inventory</span>
                    </label>
                    <label class="duty-option">
                        <input type="radio" name="duty" value="admin" id="duty_admin">
                        <span class="opt-title">Admin</span>
                    </label>
                </div>
            </div>

            {{-- Opening cash count — only required when Cashier is picked.
                 Captured inline at duty selection so it can't be skipped on
                 the way to /pos/create. --}}
            <div class="opening-cash" id="opening_cash_group" style="display:none;">
                <label for="opening_cash">
                    <i class="fa fa-cash-register"></i>
                    Count the drawer — how much cash is in it right now?
                </label>
                <p class="hint">
                    Required before you can ring a sale. The closing count at end of shift gets checked against this.
                </p>
                <div class="money">
                    <span class="sym">$</span>
                    <input type="number" name="opening_cash" id="opening_cash"
                           step="0.01" min="0"
                           placeholder="e.g. 200.00"
                           autocomplete="off">
                </div>
                <div id="opening_cash_error" class="err">
                    Please enter the cash amount in the drawer (zero is fine — but you must enter it).
                </div>
            </div>

            <div class="duty-actions">
                <button type="submit" class="duty-submit" id="duty_submit_btn">Continue</button>
            </div>
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
                ['duty_cashier','duty_shipping','duty_inventory','duty_admin'].forEach(function (id) {
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
</section>
@endsection
