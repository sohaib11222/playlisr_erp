@extends('layouts.app')

@section('title', 'Venue Bookings')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.bookings._styles')

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Venue Bookings</h1>
      <p class="sub">Manage all venue reservations from nivessa.com.</p>
    </div>
    <div class="wb-links">
      <a href="{{ route('wa.bookings.calendar') }}" class="wb-btn wb-btn-blue"><i class="fa fa-calendar"></i> Calendar View</a>
      <a href="{{ route('wa.bookings.venues') }}" class="wb-btn"><i class="fa fa-building"></i> Manage Venues</a>
      <a href="{{ route('wa.bookings.add') }}" class="wb-btn"><i class="fa fa-calendar-plus"></i> Add Missed Booking</a>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wb-stats">
    <div class="wb-stat"><div class="l">Total Bookings</div><div class="v">{{ $stats['total'] }}</div></div>
    <div class="wb-stat"><div class="l">Pending</div><div class="v pending">{{ $stats['pending'] }}</div></div>
    <div class="wb-stat"><div class="l">Confirmed</div><div class="v ok">{{ $stats['confirmed'] }}</div></div>
    <div class="wb-stat"><div class="l">Completed</div><div class="v blue">{{ $stats['completed'] }}</div></div>
    <div class="wb-stat"><div class="l">Revenue</div><div class="v ok">${{ number_format($stats['revenue'], 0) }}</div></div>
  </div>

  <div class="wb-tabs" id="wb-tabs">
    <button type="button" class="wb-tab active" data-tab="pending">Pending <span class="wb-badge" data-count="pending">0</span></button>
    <button type="button" class="wb-tab" data-tab="confirmed">Confirmed <span class="wb-badge" data-count="confirmed">0</span></button>
    <button type="button" class="wb-tab" data-tab="completed">Past <span class="wb-badge" data-count="completed">0</span></button>
    <button type="button" class="wb-tab" data-tab="archived">Archived <span class="wb-badge" data-count="archived">0</span></button>
  </div>

  <div class="wb-banner ok" id="wb-caught-up" style="display:none;">No pending booking requests right now. You are caught up.</div>

  <form method="GET" action="{{ route('wa.bookings.index') }}" class="wb-filters">
    <div class="wb-field grow">
      <label for="wb-search">Search</label>
      <input type="text" id="wb-search" placeholder="Search by booking #, customer, venue..." autocomplete="off">
    </div>
    <div class="wb-field">
      <label for="wb-status">Status</label>
      <select name="status" id="wb-status" onchange="this.form.submit()">
        @foreach(['all' => 'All Statuses', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no-show' => 'No Show'] as $v => $l)
          <option value="{{ $v }}" @if($status === $v) selected @endif>{{ $l }}</option>
        @endforeach
      </select>
    </div>
    <div class="wb-field">
      <label for="wb-start">Start Date</label>
      <input type="date" name="startDate" id="wb-start" value="{{ $startDate }}">
    </div>
    <div class="wb-field">
      <label for="wb-end">End Date</label>
      <input type="date" name="endDate" id="wb-end" value="{{ $endDate }}">
    </div>
    <div class="wb-field" style="flex-direction:row; gap:8px;">
      <button type="submit" class="wb-btn wb-btn-blue">Apply</button>
      <a href="{{ route('wa.bookings.index') }}" class="wb-btn">Clear Filters</a>
    </div>
  </form>

  <div class="wb-card">
    @if($error)
      <div class="wb-empty">
        <h3>Error Loading Bookings</h3>
        <p>{{ $error }}</p>
        <a href="{{ request()->fullUrl() }}" class="wb-btn wb-btn-primary">Try Again</a>
      </div>
    @else
      <div class="wb-empty" id="wb-empty" style="display:none;">
        <h3 id="wb-empty-title">No bookings found</h3>
        <p id="wb-empty-sub">Try adjusting your filters.</p>
      </div>
      <div class="wb-scroll" id="wb-table-wrap">
        <table class="wb-tbl">
          <thead>
            <tr>
              <th>Booking</th>
              <th>Customer</th>
              <th>Venue</th>
              <th>Date and Time</th>
              <th>Amount</th>
              <th>Status</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach($bookings as $b)
              @php
                $search = strtolower($b['bookingNumber'] . ' ' . $b['customerName'] . ' ' . $b['customerEmail'] . ' ' . $b['venueName'] . ' ' . $b['title']);
                $pillClass = in_array($b['status'], ['pending', 'confirmed', 'cancelled', 'completed', 'no-show']) ? 'st-' . $b['status'] : 'st-default';
                $depAmt = $b['depositAmount'];
                $depAmtLabel = rtrim(rtrim(number_format($depAmt, 2, '.', ''), '0'), '.');
                $dlg = [
                  'id' => $b['id'], 'bookingNumber' => $b['bookingNumber'], 'customerName' => $b['customerName'],
                  'customerEmail' => $b['customerEmail'], 'venueName' => $b['venueName'], 'dateLabel' => $b['dateLabel'],
                  'date' => $b['dateYmd'], 'startTime' => $b['start24'], 'endTime' => $b['end24'], 'title' => $b['title'],
                  'status' => $b['status'], 'depositAmount' => $depAmtLabel,
                ];
              @endphp
              <tr class="wb-row" data-id="{{ $b['id'] }}" data-status="{{ $b['status'] }}" data-search="{{ $search }}" data-b="{{ json_encode($dlg) }}">
                <td>
                  <div class="wb-main">#{{ $b['bookingNumber'] }}</div>
                  <div class="wb-sub">{{ $b['title'] }}</div>
                </td>
                <td>
                  <div class="wb-main">{{ $b['customerName'] }}</div>
                  <div class="wb-sub">{{ $b['customerEmail'] }}</div>
                </td>
                <td>{{ $b['venueName'] }}</td>
                <td>
                  <div>{{ $b['dateLabel'] }}</div>
                  <div class="wb-sub">{{ $b['timeLabel'] }}</div>
                </td>
                <td class="wb-main">${{ number_format($b['total'], 2) }}</td>
                <td><span class="wb-pill {{ $pillClass }}">{{ strtoupper($b['status']) }}</span></td>
                <td>
                  <div class="wb-actions">
                    @if($b['status'] === 'pending')
                      <form method="POST" action="{{ route('wa.bookings.status', $b['id']) }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="status" value="confirmed">
                        <button type="submit" class="wb-btn wb-btn-sm" style="color:#15803d;" title="Confirm"><i class="fa fa-check"></i> Confirm</button>
                      </form>
                      <form method="POST" action="{{ route('wa.bookings.status', $b['id']) }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="wb-btn wb-btn-sm wb-btn-danger" title="Reject">Reject</button>
                      </form>
                    @endif
                    <a href="https://nivessa.com/bookings/{{ urlencode($b['bookingNumber']) }}" target="_blank" rel="noopener" class="wb-btn wb-btn-sm" title="View Details"><i class="fa fa-eye"></i> View</a>
                    <button type="button" class="wb-btn wb-btn-sm" data-act="edit" title="Edit date / time"><i class="fa fa-pencil-alt"></i> Edit</button>
                    <button type="button" class="wb-btn wb-btn-sm" data-act="charge" title="Charge card on file (cleaning, damage, missing items)"><i class="fa fa-credit-card"></i> Charge</button>
                    @if($b['depositStatus'] === 'held')
                      <button type="button" class="wb-btn wb-btn-sm wb-btn-danger" data-act="deposit" data-dep="capture" title="Capture the ${{ $depAmtLabel }} hold (charge them, left a mess)">Capture ${{ $depAmtLabel }}</button>
                      <button type="button" class="wb-btn wb-btn-sm" style="color:#15803d;" data-act="deposit" data-dep="release" title="Release the ${{ $depAmtLabel }} hold (clean, no charge)">Release</button>
                    @elseif($b['venueSlug'] === 'main-stage-hollywood')
                      {{-- Main Stage carries no deposit hold. --}}
                    @elseif(!$b['hasDeposit'] || $b['depositStatus'] === 'none' || $b['depositStatus'] === 'failed')
                      <button type="button" class="wb-btn wb-btn-sm" data-act="deposit" data-dep="place" title="Place the venue's deposit hold now (overrides the 3-day-before-event cron)">Place hold</button>
                    @elseif($b['depositStatus'] === 'captured')
                      <span class="wb-dep cap">${{ $depAmtLabel }} captured</span>
                    @elseif($b['depositStatus'] === 'released')
                      <span class="wb-dep rel">${{ $depAmtLabel }} released</span>
                    @endif
                    <span class="wb-sep"></span>
                    <button type="button" class="wb-btn wb-btn-sm" data-act="archive" title="Archive booking (requires confirmation)" style="color:#6b7280;"><i class="fa fa-archive"></i> Archive</button>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>

{{-- Archive / restore. Archive is per browser (same as the website, which kept it in local storage). --}}
<dialog class="wb-dialog" id="wb-archive-dlg">
  <div class="wb-dialog-body">
    <h3 id="wb-archive-title">Archive this booking?</h3>
    <p class="desc" id="wb-archive-desc"></p>
    <div class="wb-summary" id="wb-archive-summary"></div>
    <div class="wb-dialog-actions">
      <button type="button" class="wb-btn" data-close>Cancel</button>
      <button type="button" class="wb-btn wb-btn-primary" id="wb-archive-go">Yes, archive</button>
    </div>
  </div>
</dialog>

<dialog class="wb-dialog" id="wb-charge-dlg">
  <div class="wb-dialog-body">
    <h3>Charge card on file</h3>
    <p class="desc">Bills the customer's stored payment method. Use for post-booking fees like cleaning, damages, or missing items per the venue rules. No deposit is held upfront, this is the direct charge.</p>
    <div class="wb-summary" id="wb-charge-summary"></div>
    <div class="wb-field">
      <label for="wb-charge-amount">Amount ($)</label>
      <input type="number" min="0" step="0.01" id="wb-charge-amount" placeholder="100.00">
      <div class="wb-presets">
        <button type="button" class="wb-btn wb-btn-sm" data-preset="100">$100</button>
        <button type="button" class="wb-btn wb-btn-sm" data-preset="150">$150</button>
        <button type="button" class="wb-btn wb-btn-sm" data-preset="200">$200</button>
      </div>
    </div>
    <div class="wb-field">
      <label for="wb-charge-reason">Reason</label>
      <input type="text" id="wb-charge-reason" placeholder="Cleaning fee, damaged microphone, etc.">
      <div class="wb-hint">Shown to the customer on their Stripe receipt.</div>
    </div>
    <div class="wb-dialog-err" id="wb-charge-err"></div>
    <div class="wb-dialog-actions">
      <button type="button" class="wb-btn" data-close>Cancel</button>
      <button type="button" class="wb-btn wb-btn-blue" id="wb-charge-go">Charge $0.00</button>
    </div>
  </div>
</dialog>

<dialog class="wb-dialog" id="wb-edit-dlg">
  <div class="wb-dialog-body">
    <h3>Edit date and time</h3>
    <p class="desc">Corrects the reservation date, start and end time, and label. The customer sees the update in their profile and confirmation page. An end time before the start time means the booking runs past midnight.</p>
    <div class="wb-summary" id="wb-edit-summary"></div>
    <div class="wb-field">
      <label for="wb-edit-title">Label</label>
      <input type="text" id="wb-edit-title" placeholder="Record Shopping 1">
    </div>
    <div class="wb-field">
      <label for="wb-edit-date">Date</label>
      <input type="date" id="wb-edit-date">
    </div>
    <div class="wb-grid2">
      <div class="wb-field">
        <label for="wb-edit-start">Start time</label>
        <input type="time" id="wb-edit-start">
      </div>
      <div class="wb-field">
        <label for="wb-edit-end">End time</label>
        <input type="time" id="wb-edit-end">
      </div>
    </div>
    <div class="wb-dialog-err" id="wb-edit-err"></div>
    <div class="wb-dialog-actions">
      <button type="button" class="wb-btn" data-close>Cancel</button>
      <button type="button" class="wb-btn wb-btn-blue" id="wb-edit-go">Save changes</button>
    </div>
  </div>
</dialog>

{{-- Deposit confirm. Same wording as the website's confirm prompt. --}}
<dialog class="wb-dialog" id="wb-dep-dlg">
  <div class="wb-dialog-body">
    <h3 id="wb-dep-q"></h3>
    <div class="wb-dialog-err" id="wb-dep-err"></div>
    <div class="wb-dialog-actions">
      <button type="button" class="wb-btn" data-close>Cancel</button>
      <button type="button" class="wb-btn wb-btn-primary" id="wb-dep-go">OK</button>
    </div>
  </div>
</dialog>

<script>
(function () {
  var CSRF = '{{ csrf_token() }}';
  var KEY = 'nivessa.erp.archivedBookingIds.v1';
  var BUCKETS = { pending: ['pending'], confirmed: ['confirmed'], completed: ['completed', 'cancelled', 'no-show'] };
  var URLS = {
    edit: '{{ url('/website-bookings') }}/ID/edit',
    charge: '{{ url('/website-bookings') }}/ID/charge',
    deposit: '{{ url('/website-bookings') }}/ID/deposit/ACT'
  };
  var activeTab = 'pending';
  var rows = Array.prototype.slice.call(document.querySelectorAll('tr.wb-row'));

  function loadArchived() {
    try { var a = JSON.parse(localStorage.getItem(KEY) || '[]'); return new Set(Array.isArray(a) ? a : []); } catch (e) { return new Set(); }
  }
  function saveArchived(s) {
    try { localStorage.setItem(KEY, JSON.stringify(Array.from(s))); } catch (e) {}
  }
  var archived = loadArchived();

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function summary(b, withDate) {
    return '<div class="num">#' + esc(b.bookingNumber) + '</div>' +
      '<div><strong>' + esc(b.customerName || 'N/A') + '</strong> <span style="color:#6b7280;">' + esc(b.customerEmail || '') + '</span></div>' +
      '<div>' + esc(b.venueName || '') + (withDate && b.dateLabel ? ', <span style="color:#6b7280;">' + esc(b.dateLabel) + '</span>' : '') + '</div>';
  }

  function render() {
    var q = (document.getElementById('wb-search') || {}).value || '';
    q = q.toLowerCase().trim();
    var counts = { pending: 0, confirmed: 0, completed: 0, archived: 0 };
    var shown = 0;
    rows.forEach(function (tr) {
      var id = tr.getAttribute('data-id'), st = tr.getAttribute('data-status');
      var isArch = archived.has(id);
      if (isArch) counts.archived++;
      else if (BUCKETS.pending.indexOf(st) >= 0) counts.pending++;
      else if (BUCKETS.confirmed.indexOf(st) >= 0) counts.confirmed++;
      else if (BUCKETS.completed.indexOf(st) >= 0) counts.completed++;

      var inTab = activeTab === 'archived' ? isArch : (!isArch && BUCKETS[activeTab].indexOf(st) >= 0);
      var match = !q || tr.getAttribute('data-search').indexOf(q) >= 0;
      tr.style.display = inTab && match ? '' : 'none';
      if (inTab && match) shown++;
      var archBtn = tr.querySelector('[data-act="archive"]');
      if (archBtn) {
        archBtn.innerHTML = isArch ? '<i class="fa fa-undo"></i> Restore' : '<i class="fa fa-archive"></i> Archive';
        archBtn.title = isArch ? 'Restore booking' : 'Archive booking (requires confirmation)';
      }
    });
    Object.keys(counts).forEach(function (k) {
      var el = document.querySelector('[data-count="' + k + '"]');
      if (el) { el.textContent = counts[k]; el.classList.toggle('hot', k === 'pending' && counts[k] > 0); }
    });
    document.querySelectorAll('.wb-tab').forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === activeTab); });
    var caught = document.getElementById('wb-caught-up');
    if (caught) caught.style.display = activeTab === 'pending' && counts.pending === 0 ? '' : 'none';
    var empty = document.getElementById('wb-empty'), wrap = document.getElementById('wb-table-wrap');
    if (empty && wrap) {
      empty.style.display = shown ? 'none' : '';
      wrap.style.display = shown ? '' : 'none';
      document.getElementById('wb-empty-title').textContent = activeTab === 'archived' ? 'Nothing archived' : 'No bookings found';
      document.getElementById('wb-empty-sub').textContent = activeTab === 'archived' ? 'Archived bookings will appear here.' : 'Try adjusting your filters.';
    }
  }

  document.querySelectorAll('.wb-tab').forEach(function (t) {
    t.addEventListener('click', function () { activeTab = t.getAttribute('data-tab'); render(); });
  });
  var search = document.getElementById('wb-search');
  if (search) {
    search.addEventListener('input', render);
    search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
  }

  document.querySelectorAll('dialog [data-close]').forEach(function (b) {
    b.addEventListener('click', function () { var d = b.closest('dialog'); if (!d.dataset.busy) d.close(); });
  });

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }
  function showErr(id, msg) { var e = document.getElementById(id); e.textContent = msg || ''; e.style.display = msg ? 'block' : 'none'; }

  // Archive
  var archTarget = null, archMode = 'archive';
  var archDlg = document.getElementById('wb-archive-dlg');
  document.getElementById('wb-archive-go').addEventListener('click', function () {
    if (!archTarget) return;
    if (archMode === 'archive') archived.add(archTarget.id); else archived.delete(archTarget.id);
    saveArchived(archived);
    archDlg.close();
    render();
  });

  // Charge
  var chargeTarget = null;
  var chargeDlg = document.getElementById('wb-charge-dlg');
  var amountEl = document.getElementById('wb-charge-amount');
  var chargeGo = document.getElementById('wb-charge-go');
  function chargeLabel() { chargeGo.textContent = 'Charge $' + (parseFloat(amountEl.value || '0') || 0).toFixed(2); }
  amountEl.addEventListener('input', function () { showErr('wb-charge-err', ''); chargeLabel(); });
  document.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () { amountEl.value = b.getAttribute('data-preset'); showErr('wb-charge-err', ''); chargeLabel(); });
  });
  chargeGo.addEventListener('click', function () {
    var amt = parseFloat(amountEl.value), reason = document.getElementById('wb-charge-reason').value.trim();
    if (!amt || amt <= 0) return showErr('wb-charge-err', 'Enter an amount greater than 0.');
    if (!reason) return showErr('wb-charge-err', 'Add a short reason (e.g. "Cleaning fee").');
    chargeDlg.dataset.busy = '1'; chargeGo.disabled = true; chargeGo.textContent = 'Charging...';
    post(URLS.charge.replace('ID', chargeTarget.id), { amount: amt, reason: reason }).then(function (d) {
      if (!d.success) throw new Error(d.message || 'Charge failed');
      location.reload();
    }).catch(function (e) {
      delete chargeDlg.dataset.busy; chargeGo.disabled = false; chargeLabel();
      showErr('wb-charge-err', e.message || 'Charge failed');
    });
  });

  // Edit
  var editTarget = null;
  var editDlg = document.getElementById('wb-edit-dlg');
  var editGo = document.getElementById('wb-edit-go');
  editGo.addEventListener('click', function () {
    var date = document.getElementById('wb-edit-date').value,
        st = document.getElementById('wb-edit-start').value,
        en = document.getElementById('wb-edit-end').value,
        title = document.getElementById('wb-edit-title').value;
    if (!date || !st || !en) return showErr('wb-edit-err', 'Date, start, and end time are all required.');
    editDlg.dataset.busy = '1'; editGo.disabled = true; editGo.textContent = 'Saving...';
    post(URLS.edit.replace('ID', editTarget.id), { date: date, startTime: st, endTime: en, title: title }).then(function (d) {
      if (!d.success) throw new Error(d.message || 'Update failed');
      location.reload();
    }).catch(function (e) {
      delete editDlg.dataset.busy; editGo.disabled = false; editGo.textContent = 'Save changes';
      showErr('wb-edit-err', e.message || 'Update failed');
    });
  });

  // Deposit
  var depTarget = null, depAction = null;
  var depDlg = document.getElementById('wb-dep-dlg');
  var depGo = document.getElementById('wb-dep-go');
  depGo.addEventListener('click', function () {
    depDlg.dataset.busy = '1'; depGo.disabled = true;
    post(URLS.deposit.replace('ID', depTarget.id).replace('ACT', depAction), {}).then(function (d) {
      if (!d.success) throw new Error(d.message || 'unknown error');
      location.reload();
    }).catch(function (e) {
      delete depDlg.dataset.busy; depGo.disabled = false;
      showErr('wb-dep-err', 'Failed: ' + (e.message || 'unknown error'));
    });
  });

  document.querySelectorAll('[data-act]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tr = btn.closest('tr.wb-row');
      var b = JSON.parse(tr.getAttribute('data-b'));
      var act = btn.getAttribute('data-act');
      if (act === 'archive') {
        archTarget = b; archMode = archived.has(b.id) ? 'unarchive' : 'archive';
        document.getElementById('wb-archive-title').textContent = archMode === 'archive' ? 'Archive this booking?' : 'Restore this booking?';
        document.getElementById('wb-archive-desc').textContent = archMode === 'archive'
          ? 'Archived bookings disappear from the Pending, Confirmed, and Past tabs. They are not deleted. Find them under Archived and restore anytime.'
          : 'This moves the booking back into whichever tab matches its current status.';
        document.getElementById('wb-archive-summary').innerHTML = summary(b, true) + '<div style="color:#6b7280;font-size:11px;text-transform:uppercase;">Status: ' + esc(b.status) + '</div>';
        var go = document.getElementById('wb-archive-go');
        go.textContent = archMode === 'archive' ? 'Yes, archive' : 'Yes, restore';
        go.className = 'wb-btn ' + (archMode === 'archive' ? 'wb-btn-danger' : 'wb-btn-blue');
        archDlg.showModal();
      } else if (act === 'charge') {
        chargeTarget = b;
        amountEl.value = ''; document.getElementById('wb-charge-reason').value = '';
        showErr('wb-charge-err', ''); chargeLabel(); chargeGo.disabled = false;
        document.getElementById('wb-charge-summary').innerHTML = summary(b, true);
        chargeDlg.showModal();
      } else if (act === 'edit') {
        editTarget = b;
        document.getElementById('wb-edit-title').value = b.title || '';
        document.getElementById('wb-edit-date').value = b.date || '';
        document.getElementById('wb-edit-start').value = b.startTime || '';
        document.getElementById('wb-edit-end').value = b.endTime || '';
        showErr('wb-edit-err', ''); editGo.disabled = false; editGo.textContent = 'Save changes';
        document.getElementById('wb-edit-summary').innerHTML = '<div class="num">#' + esc(b.bookingNumber) + '</div><div><strong>' + esc(b.customerName || 'N/A') + '</strong>, ' + esc(b.venueName || '') + '</div>';
        editDlg.showModal();
      } else if (act === 'deposit') {
        depTarget = b; depAction = btn.getAttribute('data-dep');
        var who = b.customerName || 'this booking';
        var q = depAction === 'place'
          ? 'Place a deposit hold on ' + who + '?'
          : (depAction === 'capture' ? 'Capture (charge)' : 'Release (refund)') + ' the $' + b.depositAmount + ' deposit for ' + who + '?';
        document.getElementById('wb-dep-q').textContent = q;
        showErr('wb-dep-err', ''); depGo.disabled = false;
        depDlg.showModal();
      }
    });
  });

  render();
})();
</script>
@endsection
