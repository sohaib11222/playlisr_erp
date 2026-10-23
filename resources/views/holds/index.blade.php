@extends('layouts.app')

@section('title', 'Holds')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
<script>document.body.classList.add('holds-v2');</script>

{{-- Same cream design system as /pos/create, scoped to this page. --}}
<style>
body.holds-v2 {
  --h-bg: #FAF6EE; --h-surface: #FFFFFF; --h-surface-2: #F7F1E3; --h-ink: #1F1B16; --h-ink-2: #3B3226;
  --h-line: #ECE3CF; --h-line-2: #DFD2B3; --h-success: #2F6B3E; --h-danger: #7A1F1F;
  --h-pill-bg: #DCEBFF; --h-pill-ink: #1F4E8C;
  background: var(--h-bg);
}
body.holds-v2 .content-wrapper { background: var(--h-bg); }
body.holds-v2 .holds-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--h-ink); }
body.holds-v2 .holds-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.holds-v2 .holds-head p { margin: 0 0 18px; font-size: 14px; color: var(--h-ink-2); }
body.holds-v2 .h-card { background: var(--h-surface); border: 1px solid var(--h-line); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
body.holds-v2 .h-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 12px; }
body.holds-v2 .h-alert { border-radius: 12px; padding: 12px 16px; margin-bottom: 14px; font-weight: 600; border: 1px solid; }
body.holds-v2 .h-alert.ok { background: #E9F3EC; border-color: #BFDCC7; color: var(--h-success); }
body.holds-v2 .h-alert.err { background: #F8E9E9; border-color: #E6C3C3; color: var(--h-danger); }
body.holds-v2 .h-overdue { background: #F8E9E9; border: 1px solid #E6C3C3; border-radius: 12px; padding: 14px 18px; margin-bottom: 18px;
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; color: var(--h-danger); }
body.holds-v2 .h-overdue strong { font-size: 16px; }
body.holds-v2 .h-overdue a { color: var(--h-danger); font-weight: 700; text-decoration: underline; }

body.holds-v2 .h-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px 14px; align-items: end; }
body.holds-v2 .h-field label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 5px; color: var(--h-ink); }
body.holds-v2 .h-field input[type=text], body.holds-v2 .h-field input[type=date], body.holds-v2 .h-field input[type=number],
body.holds-v2 .h-field select, body.holds-v2 .h-row-form input[type=date] {
  width: 100%; border: 1px solid var(--h-line-2); border-radius: 9px; padding: 8px 10px; font-size: 14px; font-family: inherit;
  background: #fff; color: var(--h-ink); height: 38px; box-shadow: none; }
body.holds-v2 .h-field input:focus, body.holds-v2 .h-field select:focus { outline: none; border-color: var(--h-pill-ink); box-shadow: 0 0 0 3px var(--h-pill-bg); }
body.holds-v2 .h-field.wide { grid-column: span 2; }
body.holds-v2 .h-help { font-size: 12px; color: var(--h-ink-2); margin-top: 4px; }
body.holds-v2 .h-results { border: 1px solid var(--h-line); border-radius: 9px; margin-top: 6px; max-height: 220px; overflow-y: auto; display: none; background: #fff; }
body.holds-v2 .h-results.open { display: block; }
body.holds-v2 .h-result { padding: 8px 10px; cursor: pointer; border-bottom: 1px solid var(--h-line); font-size: 13px; }
body.holds-v2 .h-result:last-child { border-bottom: 0; }
body.holds-v2 .h-result:hover { background: var(--h-surface-2); }
body.holds-v2 .h-picked { margin-top: 6px; padding: 7px 10px; background: var(--h-surface-2); border: 1px solid var(--h-line); border-radius: 9px; font-size: 13px; font-weight: 600; display: none; }
body.holds-v2 .h-picked.open { display: block; }

body.holds-v2 .h-pills { display: inline-flex; gap: 6px; flex-wrap: wrap; }
body.holds-v2 .h-pill { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 999px; border: 1px solid var(--h-line-2);
  background: #fff; color: var(--h-ink); font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; }
body.holds-v2 .h-pill:hover { background: var(--h-surface-2); color: var(--h-ink); text-decoration: none; }
body.holds-v2 .h-pill.on, body.holds-v2 .h-pill.on:hover { background: var(--h-pill-bg); border-color: #B7D3F7; color: var(--h-pill-ink); }
body.holds-v2 .h-pills input[type=radio] { display: none; }

body.holds-v2 .h-btn { display: inline-flex; align-items: center; gap: 6px; border-radius: 9px; padding: 8px 14px; font-size: 13px; font-weight: 700;
  font-family: inherit; cursor: pointer; border: 1px solid var(--h-line-2); background: #fff; color: var(--h-ink); white-space: nowrap; height: 34px; }
body.holds-v2 .h-btn:hover { background: var(--h-surface-2); }
body.holds-v2 .h-btn.primary { background: var(--h-success); border-color: var(--h-success); color: #fff; height: 38px; }
body.holds-v2 .h-btn.primary:hover { background: #255732; }
body.holds-v2 .h-btn.sm { padding: 5px 10px; font-size: 12px; height: 30px; }

body.holds-v2 .h-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
body.holds-v2 .h-toolbar select { border: 1px solid var(--h-line-2); border-radius: 9px; padding: 6px 10px; font-family: inherit; background: #fff; color: var(--h-ink); height: 34px; }
body.holds-v2 .h-table-wrap { overflow-x: auto; }
body.holds-v2 table.h-table { width: 100%; border-collapse: collapse; }
body.holds-v2 .h-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; color: var(--h-ink);
  padding: 9px 8px; border-bottom: 1px solid var(--h-line-2); white-space: nowrap; }
body.holds-v2 .h-table td { padding: 11px 8px; border-bottom: 1px solid var(--h-line); font-size: 13.5px; vertical-align: top; color: var(--h-ink); }
body.holds-v2 .h-table tr.overdue td { background: #FBF0F0; }
body.holds-v2 .h-table .sku { display: block; font-size: 12px; color: var(--h-ink-2); margin-top: 2px; }
body.holds-v2 .h-table .who-type { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--h-surface-2); border: 1px solid var(--h-line); margin-right: 4px; }
body.holds-v2 .h-late { color: var(--h-danger); font-weight: 700; }
body.holds-v2 .h-ok { color: var(--h-success); font-weight: 700; }
body.holds-v2 .h-actions { display: flex; flex-direction: column; gap: 6px; min-width: 210px; }
body.holds-v2 .h-actions form { margin: 0; }
body.holds-v2 .h-row-form { display: flex; gap: 6px; align-items: center; }
body.holds-v2 .h-row-form input[type=date] { height: 30px; padding: 4px 8px; font-size: 12px; width: 140px; }
body.holds-v2 .h-empty { padding: 26px 8px; text-align: center; font-size: 14px; color: var(--h-ink-2); }
@media (max-width: 640px) { body.holds-v2 .h-field.wide { grid-column: span 1; } }
</style>

@php
    $fmt = function ($d) { return $d ? \Carbon\Carbon::parse($d)->format('m/d/y') : ''; };
    $baseQuery = array_filter(['location_id' => $locationFilter ?: null]);
@endphp

<div class="holds-wrap">
    <div class="holds-head">
        <h1>Holds</h1>
        <p>Items set aside in the hold bin for a customer or an employee. A held item is off the website and the POS warns if it gets rung up. Customer holds can run up to {{ $maxCustomer * 24 }} hours, employee holds up to {{ $maxEmployee }} days. When a hold is past due, pull it from the bin and clear it here.</p>
    </div>

    @if(is_string(session('status')))<div class="h-alert ok">{{ session('status') }}</div>@endif
    @if(is_string(session('error')))<div class="h-alert err">{{ session('error') }}</div>@endif

    @if(!$tableReady)
        <div class="h-alert err">Holds are not set up yet. The database migration for holds still needs to be run.</div>
    @else

    @if($overdueCount > 0)
        <div class="h-overdue">
            <div><strong>Past due: pull from the hold bin.</strong> {{ $overdueCount }} {{ $overdueCount === 1 ? 'hold is' : 'holds are' }} past the hold until date.</div>
            <a href="{{ action('HoldController@index', $baseQuery + ['filter' => 'overdue']) }}">Show past due holds</a>
        </div>
    @endif

    <div class="h-card">
        <h2>New hold</h2>
        <form method="POST" action="{{ action('HoldController@store') }}" id="hold_new_form" autocomplete="off">
            {{ csrf_field() }}
            <input type="hidden" name="variation_id" id="hold_variation_id" value="">
            <div class="h-form">
                <div class="h-field wide">
                    <label for="hold_item_search">Item (scan or type SKU, title or artist)</label>
                    <input type="text" id="hold_item_search" placeholder="Scan the barcode">
                    <div class="h-results" id="hold_item_results"></div>
                    <div class="h-picked" id="hold_item_picked"></div>
                </div>
                <div class="h-field">
                    <label for="hold_location_id">Store</label>
                    <select name="location_id" id="hold_location_id">
                        @foreach($locations as $id => $name)
                            <option value="{{ $id }}" {{ (int) old('location_id', $defaultLocationId) === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="h-field">
                    <label for="hold_quantity">Qty</label>
                    <input type="number" name="quantity" id="hold_quantity" min="1" step="1" value="{{ old('quantity', 1) }}">
                </div>
                <div class="h-field">
                    <label>Held for</label>
                    @php $oldType = old('held_for_type', 'customer'); @endphp
                    <div class="h-pills" id="hold_type_pills">
                        <label class="h-pill {{ $oldType === 'customer' ? 'on' : '' }}"><input type="radio" name="held_for_type" value="customer" {{ $oldType === 'customer' ? 'checked' : '' }}> Customer</label>
                        <label class="h-pill {{ $oldType === 'employee' ? 'on' : '' }}"><input type="radio" name="held_for_type" value="employee" {{ $oldType === 'employee' ? 'checked' : '' }}> Employee</label>
                    </div>
                </div>
                <div class="h-field" id="hold_customer_field" @if($oldType === 'employee') style="display:none;" @endif>
                    <label for="hold_customer_name">Customer name</label>
                    <input type="text" name="held_for_name" id="hold_customer_name" value="{{ old('held_for_name') }}" maxlength="190">
                </div>
                <div class="h-field" id="hold_employee_field" @if($oldType !== 'employee') style="display:none;" @endif>
                    <label for="hold_employee">Employee</label>
                    <select name="held_for_user_id" id="hold_employee">
                        <option value="">Pick an employee</option>
                        @foreach($employees as $id => $name)
                            <option value="{{ $id }}" {{ (int) old('held_for_user_id') === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="h-field">
                    <label for="hold_until">Hold until</label>
                    <input type="date" name="hold_until" id="hold_until"
                        value="{{ old('hold_until', $oldType === 'employee' ? $defaultUntilEmployee : $defaultUntilCustomer) }}"
                        min="{{ $today->toDateString() }}"
                        max="{{ $oldType === 'employee' ? $latestUntilEmployee : $latestUntilCustomer }}">
                    <div class="h-help" id="hold_until_help">Up to {{ $oldType === 'employee' ? $maxEmployee . ' days' : ($maxCustomer * 24) . ' hours' }}.</div>
                </div>
                <div class="h-field wide">
                    <label for="hold_notes">Note</label>
                    <input type="text" name="notes" id="hold_notes" value="{{ old('notes') }}" placeholder="Optional, like a phone number or paid deposit">
                </div>
                <div class="h-field">
                    <button type="submit" class="h-btn primary"><i class="fa fa-pause-circle"></i> Place hold</button>
                </div>
            </div>
        </form>
    </div>

    <div class="h-card">
        <div class="h-toolbar">
            <div class="h-pills">
                <a class="h-pill {{ $tab === 'active' && !$overdueOnly ? 'on' : '' }}" href="{{ action('HoldController@index', $baseQuery) }}">Active</a>
                <a class="h-pill {{ $overdueOnly ? 'on' : '' }}" href="{{ action('HoldController@index', $baseQuery + ['filter' => 'overdue']) }}">Past due ({{ $overdueCount }})</a>
                <a class="h-pill {{ $tab === 'history' ? 'on' : '' }}" href="{{ action('HoldController@index', $baseQuery + ['tab' => 'history']) }}">History</a>
            </div>
            @if(count($locations) > 1)
            <form method="GET" action="{{ action('HoldController@index') }}" style="margin:0 0 0 auto;">
                @if($tab === 'history')<input type="hidden" name="tab" value="history">@endif
                @if($overdueOnly)<input type="hidden" name="filter" value="overdue">@endif
                <select name="location_id" onchange="this.form.submit()">
                    <option value="">All stores</option>
                    @foreach($locations as $id => $name)
                        <option value="{{ $id }}" {{ (int) $locationFilter === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </form>
            @endif
        </div>

        <div class="h-table-wrap">
        @if($tab === 'active')
            <table class="h-table">
                <thead>
                    <tr>
                        <th>Item</th><th>Store</th><th>Held for</th><th>Placed by</th><th>Placed on</th><th>Hold until</th><th>Days</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($holds as $h)
                    @php
                        $until = \Carbon\Carbon::parse($h->hold_until)->startOfDay();
                        $diff = $today->diffInDays($until, false);
                        $isLate = $diff < 0;
                        $maxFor = \Carbon\Carbon::today()->addDays($h->held_for_type === 'employee' ? $maxEmployee : $maxCustomer)->toDateString();
                    @endphp
                    <tr class="{{ $isLate ? 'overdue' : '' }}">
                        <td>
                            {{ \App\Http\Controllers\HoldController::itemLabel($h->artist, $h->product_name) }}
                            @if((float) $h->quantity > 1) <strong>x{{ (float) $h->quantity }}</strong>@endif
                            <span class="sku">{{ $h->sub_sku }}</span>
                            @if($h->notes)<span class="sku">{{ $h->notes }}</span>@endif
                        </td>
                        <td>{{ $h->location_name }}</td>
                        <td><span class="who-type">{{ ucfirst($h->held_for_type) }}</span> {{ $h->held_for_name }}</td>
                        <td>{{ $h->created_by_name }}</td>
                        <td>{{ $fmt($h->created_at) }}</td>
                        <td>{{ $fmt($h->hold_until) }}</td>
                        <td>
                            @if($isLate)
                                <span class="h-late">{{ abs($diff) }} {{ abs($diff) === 1 ? 'day' : 'days' }} overdue</span>
                            @elseif($diff === 0)
                                <span class="h-late">Due today</span>
                            @else
                                <span class="h-ok">{{ $diff }} {{ $diff === 1 ? 'day' : 'days' }} left</span>
                            @endif
                        </td>
                        <td>
                            <div class="h-actions">
                                <div class="h-row-form">
                                    <form method="POST" action="{{ action('HoldController@release', [$h->id]) }}">
                                        {{ csrf_field() }}
                                        <button type="submit" class="h-btn sm" title="The item goes back on sale">Back on floor</button>
                                    </form>
                                    <form method="POST" action="{{ action('HoldController@sold', [$h->id]) }}">
                                        {{ csrf_field() }}
                                        <button type="submit" class="h-btn sm" title="Then ring it up at the POS as usual">Sold to them</button>
                                    </form>
                                </div>
                                <form method="POST" action="{{ action('HoldController@extend', [$h->id]) }}" class="h-row-form">
                                    {{ csrf_field() }}
                                    <input type="date" name="hold_until" value="{{ $until->lt($today) ? $today->toDateString() : $until->toDateString() }}" min="{{ $today->toDateString() }}" max="{{ $maxFor }}" required>
                                    <button type="submit" class="h-btn sm">Extend</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="h-empty">{{ $overdueOnly ? 'Nothing past due. The hold bin is clear.' : 'No active holds.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        @else
            <table class="h-table">
                <thead>
                    <tr>
                        <th>Item</th><th>Store</th><th>Held for</th><th>Placed by</th><th>Placed on</th><th>Hold until</th><th>Outcome</th><th>Cleared</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($holds as $h)
                    <tr>
                        <td>
                            {{ \App\Http\Controllers\HoldController::itemLabel($h->artist, $h->product_name) }}
                            @if((float) $h->quantity > 1) <strong>x{{ (float) $h->quantity }}</strong>@endif
                            <span class="sku">{{ $h->sub_sku }}</span>
                            @if($h->notes)<span class="sku">{{ $h->notes }}</span>@endif
                        </td>
                        <td>{{ $h->location_name }}</td>
                        <td><span class="who-type">{{ ucfirst($h->held_for_type) }}</span> {{ $h->held_for_name }}</td>
                        <td>{{ $h->created_by_name }}</td>
                        <td>{{ $fmt($h->created_at) }}</td>
                        <td>{{ $fmt($h->hold_until) }}</td>
                        <td>
                            @if($h->status === 'sold')<span class="h-ok">Sold to them</span>@else Back on floor @endif
                            @if($h->release_note)<span class="sku">{{ $h->release_note }}</span>@endif
                        </td>
                        <td>{{ $fmt($h->released_at) }} {{ $h->released_by_name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="h-empty">No cleared holds yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>
    @endif
</div>
@endsection

@section('javascript')
<script>
(function () {
    try {
        var $ = window.jQuery;
        if (!$) return;
        var defaults = {
            customer: { until: @json($defaultUntilCustomer), max: @json($latestUntilCustomer), days: {{ (int) $maxCustomer }} },
            employee: { until: @json($defaultUntilEmployee), max: @json($latestUntilEmployee), days: {{ (int) $maxEmployee }} }
        };

        // Customer / Employee pill toggle.
        $('#hold_type_pills').on('change', 'input[type=radio]', function () {
            var type = $(this).val() === 'employee' ? 'employee' : 'customer';
            $('#hold_type_pills .h-pill').removeClass('on');
            $(this).closest('.h-pill').addClass('on');
            $('#hold_customer_field').toggle(type === 'customer');
            $('#hold_employee_field').toggle(type === 'employee');
            $('#hold_until').val(defaults[type].until).attr('max', defaults[type].max);
            $('#hold_until_help').text('Up to ' + (type === 'customer' ? (defaults[type].days * 24) + ' hours.' : defaults[type].days + ' days.'));
        });

        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

        function pick(item) {
            $('#hold_variation_id').val(item.variation_id);
            $('#hold_item_picked').html(esc(item.label) + (item.sku ? ' <span style="font-weight:500;">(' + esc(item.sku) + ')</span>' : '')).addClass('open');
            $('#hold_item_results').removeClass('open').empty();
            $('#hold_item_search').val('');
        }

        var timer = null, lastItems = [];
        function search(q, autoPick) {
            $.getJSON('/holds/lookup', { q: q }).done(function (res) {
                lastItems = (res && res.items) || [];
                var $r = $('#hold_item_results').empty();
                if (!lastItems.length) { $r.html('<div class="h-result">No match</div>').addClass('open'); return; }
                if (autoPick && lastItems.length === 1) { pick(lastItems[0]); return; }
                lastItems.forEach(function (it, i) {
                    $r.append('<div class="h-result" data-i="' + i + '">' + esc(it.label) + (it.sku ? ' <span>(' + esc(it.sku) + ')</span>' : '') + '</div>');
                });
                $r.addClass('open');
            });
        }

        $('#hold_item_search').on('keydown', function (e) {
            // A scanner ends with Enter: search now and keep the form from submitting.
            if (e.which === 13) {
                e.preventDefault();
                var q = $.trim($(this).val());
                if (q) search(q, true);
            }
        }).on('input', function () {
            var q = $.trim($(this).val());
            clearTimeout(timer);
            if (q.length < 3) { $('#hold_item_results').removeClass('open').empty(); return; }
            timer = setTimeout(function () { search(q, false); }, 300);
        });
        $('#hold_item_results').on('click', '.h-result[data-i]', function () {
            var it = lastItems[parseInt($(this).attr('data-i'), 10)];
            if (it) pick(it);
        });
        $('#hold_new_form').on('submit', function (e) {
            if (!$('#hold_variation_id').val()) {
                e.preventDefault();
                if (window.toastr) toastr.warning('Scan or search the item first.'); else alert('Scan or search the item first.');
            }
        });
    } catch (err) {
        if (window.console) console.error('Holds page script failed', err);
    }
})();
</script>
@endsection
