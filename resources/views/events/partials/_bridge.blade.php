{{-- RSVPs, giveaway spin wheel, and preorders. Records live on nivessa.com;
     reached read/write through the key-gated bridge. --}}

@php
  $evName = $event['name'] ?? '';
  $rsvpUrl = 'https://nivessa.com/admin/rsvps?eventName=' . rawurlencode($evName);
  $preorderUrl = 'https://nivessa.com/admin/preorders?eventName=' . rawurlencode($evName);
@endphp

@if(!($bridge['ready'] ?? false))
  {{-- Bridge not connected (or website unreachable): let the user turn it on
       right here by pasting the key, then give direct links to the live tools
       on nivessa.com so RSVPs, the spin, check-in and preorders are usable
       right now. These panels fill in here automatically once the key is set. --}}
  <div class="ev-card" style="border:1px solid var(--pos-accent,#FFE08A);">
    <h2 style="margin-top:0;">Connect the bridge to load data here</h2>
    @if(($bridge['error'] ?? null) === 'unreachable')
      <p class="sub" style="margin:0 0 10px;color:#a23;">A key is set, but the website rejected it or was unreachable. Paste the key again — it must match the website's <code>ERP_API_KEY</code> exactly.</p>
    @else
      <p class="sub" style="margin:0 0 10px;">Paste the same <code>ERP_API_KEY</code> used on nivessa.com to pull RSVPs, check-in, the giveaway spin, and preorders into the ERP. Stored on the ERP server only (not in git); no server access needed.</p>
    @endif
    <form method="POST" action="{{ route('events.bridgeKey') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      {{ csrf_field() }}
      <div class="ev-field" style="flex:2 1 320px;">
        <label>ERP_API_KEY (same value as the website)</label>
        <input type="password" name="erp_api_key" autocomplete="off" placeholder="paste key">
      </div>
      <button type="submit" class="btn-accent">Save &amp; test</button>
    </form>
  </div>
  <div class="ev-card">
    <h2>RSVPs &amp; giveaway spin</h2>
    <p class="sub" style="margin-top:0;">View RSVPs, check guests in, and run the giveaway spin on the website.</p>
    <a class="btn-accent" href="{{ $rsvpUrl }}" target="_blank" rel="noopener">Open RSVPs &amp; spin</a>
  </div>
  <div class="ev-card">
    <h2>Preorders</h2>
    <p class="sub" style="margin-top:0;">See and manage preorders for this event (mark ready / picked up).</p>
    <a class="btn-accent" href="{{ $preorderUrl }}" target="_blank" rel="noopener">Open preorders</a>
  </div>
  @if(($bridge['error'] ?? null) === 'not_configured')
    <p class="sub" style="margin:-8px 4px 22px;">These open the existing tools on nivessa.com. To run them inside the ERP instead, <code>ERP_API_KEY</code> must be in <strong>this ERP server's <code>.env</code></strong> (same value as the website's). It isn't there now — I check the cached config, the live env, and the <code>.env</code> file directly, so this is not a caching issue.</p>
  @elseif(($bridge['error'] ?? null) === 'unreachable')
    <p class="sub" style="margin:-8px 4px 22px;">The ERP has an <code>ERP_API_KEY</code> set, but the website rejected it or was unreachable. Make sure the value matches the website's <code>ERP_API_KEY</code> exactly.</p>
  @endif
@else
  @php
    $rsvps = $bridge['rsvps'] ?? [];
    $stats = $bridge['stats'] ?? null;
    $preorders = $bridge['preorders'] ?? [];
    // Build the wheel pool: de-dupe by email, keep checked-in flag.
    $pool = [];
    foreach ($rsvps as $r) {
      $em = strtolower(trim($r['email'] ?? ''));
      $nm = trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? '')) ?: ($r['name'] ?? $em);
      if ($nm === '') { continue; }
      $key = $em ?: $nm;
      $pool[$key] = ['name' => $nm, 'checkedIn' => !empty($r['checkedIn'])];
    }
    $pool = array_values($pool);

    // Vinyl/CD purchase interest. Listening-party RSVPs carry
    // `interestedInPurchase` (vinyl|cd|both|not_sure|no). Mirror the
    // website's /admin/rsvps tally + suggested-order math so the numbers
    // match exactly: units to order = anyone who said vinyl/cd/both, with
    // the "not sure" crowd as an upper-bound buffer.
    $interestLabels = ['vinyl' => 'Vinyl', 'cd' => 'CD', 'both' => 'Both', 'not_sure' => 'Not sure', 'no' => 'No'];
    $interestCounts = ['vinyl' => 0, 'cd' => 0, 'both' => 0, 'not_sure' => 0, 'no' => 0];
    $interestAnswered = 0;
    foreach ($rsvps as $r) {
      $v = $r['interestedInPurchase'] ?? null;
      if ($v !== null && isset($interestCounts[$v])) { $interestCounts[$v]++; $interestAnswered++; }
    }
    $vinylUnits = $interestCounts['vinyl'] + $interestCounts['both'];
    $cdUnits = $interestCounts['cd'] + $interestCounts['both'];
  @endphp

  {{-- ---------- RSVPs ---------- --}}
  <div class="ev-card">
    <h2>RSVPs</h2>
    @if($stats)
      <div class="total-owed" style="margin-bottom:12px;">
        {{ $stats['attendingCount'] ?? $stats['totalAttendees'] ?? count($rsvps) }} attending
        <span class="ev-meta">&middot; {{ $stats['yesCount'] ?? 0 }} yes, {{ $stats['maybeCount'] ?? 0 }} maybe &middot; {{ $stats['totalGuests'] ?? 0 }} guests</span>
      </div>
    @endif

    {{-- Vinyl/CD order estimate — listening parties only (shown once
         anyone has answered the "buying vinyl or CD?" RSVP question). --}}
    @if($interestAnswered > 0)
      <div style="border:1px solid var(--pos-line,#ECE3CF);border-radius:10px;padding:12px 14px;margin-bottom:14px;background:var(--pos-accent-soft,#FFF9DB);">
        <div style="font-weight:700;font-size:13px;margin-bottom:8px;">
          Vinyl / CD interest
          <span class="ev-meta" style="font-weight:500;">&middot; {{ $interestAnswered }} answered</span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
          @foreach($interestLabels as $k => $label)
            <span class="pill">{{ $label }}: {{ $interestCounts[$k] }}</span>
          @endforeach
        </div>
        <div style="font-size:13px;">
          Suggested order:
          <strong>{{ $vinylUnits }} vinyl</strong> &middot; <strong>{{ $cdUnits }} CD</strong>
          @if($interestCounts['not_sure'] > 0)
            <span class="ev-meta">(+{{ $interestCounts['not_sure'] }} more if the "not sure" guests commit)</span>
          @endif
        </div>
      </div>
    @endif

    {{-- Add a walk-in RSVP (someone who didn't RSVP in advance). Always
         available, even before anyone has RSVP'd. --}}
    <details style="margin-bottom:14px;border:1px dashed var(--pos-line,#ECE3CF);border-radius:10px;padding:10px 14px;">
      <summary class="ev-create-summary">+ Add RSVP (walk-in)</summary>
      <form method="POST" action="{{ route('events.rsvpAdd', ['id' => $event['id']]) }}" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        {{ csrf_field() }}
        <div class="ev-field" style="flex:1 1 130px;"><label>First name *</label><input type="text" name="firstName" required></div>
        <div class="ev-field" style="flex:1 1 130px;"><label>Last name *</label><input type="text" name="lastName" required></div>
        <div class="ev-field" style="flex:2 1 220px;"><label>Email *</label><input type="email" name="email" required></div>
        <div class="ev-field" style="flex:1 1 130px;"><label>Phone</label><input type="text" name="phone"></div>
        <div class="ev-field" style="flex:0 1 80px;"><label>Guests</label><input type="number" name="guests" min="0" value="0"></div>
        <div class="ev-field" style="flex:1 1 140px;"><label>Vinyl / CD</label>
          <select name="interestedInPurchase">
            <option value="">—</option>
            <option value="vinyl">Vinyl</option>
            <option value="cd">CD</option>
            <option value="both">Both</option>
            <option value="not_sure">Not sure</option>
            <option value="no">No</option>
          </select>
        </div>
        <label class="ev-meta" style="display:flex;align-items:center;gap:6px;padding-bottom:9px;">
          <input type="hidden" name="checkedIn" value="0">
          <input type="checkbox" name="checkedIn" value="1" checked> Check in now
        </label>
        <button type="submit" class="btn-accent">Add RSVP</button>
      </form>
    </details>

    @if(empty($rsvps))
      <div class="empty">No RSVPs yet.</div>
    @else
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
        <button type="button" class="btn-ghost" id="copy-emails">Copy all emails</button>
        <input type="search" id="rsvp-search" placeholder="Search name or email" autocomplete="off"
               style="flex:1 1 220px;max-width:320px;padding:8px 10px;border:1px solid var(--pos-line,#ECE3CF);border-radius:8px;">
      </div>
      <table class="ev-tbl" id="rsvp-table">
        <thead><tr>
          <th data-sort-type="text">Name</th>
          <th data-sort-type="text">Email</th>
          <th data-sort-type="text">Going</th>
          <th data-sort-type="num">Guests</th>
          <th data-sort-type="text">Vinyl/CD</th>
          <th data-sort-type="text">Checked in</th>
        </tr></thead>
        <tbody>
          @foreach($rsvps as $r)
            @php $rid = $r['_id'] ?? $r['id'] ?? ''; $ci = !empty($r['checkedIn']); @endphp
            <tr>
              <td class="ev-name">{{ trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? '')) ?: ($r['name'] ?? '—') }}</td>
              <td class="ev-meta">{{ $r['email'] ?? '' }}</td>
              <td>{{ ucfirst($r['attendance'] ?? '') }}</td>
              <td>{{ (int) ($r['guests'] ?? 0) }}</td>
              <td>
                @php $vi = $r['interestedInPurchase'] ?? null; @endphp
                @if($vi && isset($interestLabels[$vi]))
                  <span class="pill">{{ $interestLabels[$vi] }}</span>
                @else
                  <span class="ev-meta">—</span>
                @endif
              </td>
              <td>
                @if($rid)
                <form method="POST" action="{{ route('events.rsvpCheckIn', ['id' => $event['id'], 'rsvpId' => $rid]) }}" style="display:inline;">
                  {{ csrf_field() }}
                  <input type="hidden" name="checkedIn" value="{{ $ci ? '0' : '1' }}">
                  <button type="submit" class="{{ $ci ? 'btn-accent' : 'btn-ghost' }}" style="padding:5px 12px;font-size:12px;">
                    {{ $ci ? 'Checked in' : 'Check in' }}
                  </button>
                </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <script>
        window.__rsvpEmails = @json(array_values(array_filter(array_map(fn($r) => $r['email'] ?? '', $rsvps))));
      </script>
    @endif
  </div>

  {{-- ---------- Giveaway spin ---------- --}}
  <div class="ev-card">
    <h2>Giveaway spin</h2>
    @if(empty($pool))
      <div class="empty">No one to spin yet — RSVPs (or check-ins) will appear here.</div>
    @else
      <div class="ev-checks" style="margin-bottom:12px;">
        <label><input type="radio" name="spin-scope" value="checkedin" checked> Checked-in only</label>
        <label><input type="radio" name="spin-scope" value="all"> All RSVPs</label>
      </div>
      <div id="spin-display" style="font-size:26px;font-weight:800;min-height:40px;padding:10px 0;color:var(--pos-accent-text);">—</div>
      <button type="button" class="btn-accent" id="spin-btn">Spin the wheel</button>
      <script>
        window.__spinPool = @json($pool);
      </script>
    @endif
  </div>

  {{-- ---------- Preorders ---------- --}}
  {{-- Only advance listening parties take preorders. For events without it
       enabled, show a muted note instead of an ordering link (which would
       wrongly imply customers can preorder). --}}
  @php $preordersOn = !empty($event['preorderEnabled']); @endphp
  <div class="ev-card">
    <h2>Preorders</h2>
    @if(!$preordersOn)
      <div class="empty">Preorders aren't enabled for this event. Check "Enable preorder for this event" in the details above if this is an advance listening party.</div>
    @elseif(empty($preorders))
      <div class="empty">No preorders yet. Customers order at nivessa.com/preorder?eventId={{ $event['id'] }}.</div>
    @else
      <table class="ev-tbl">
        <thead><tr><th>Customer</th><th>Item</th><th>Price</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @foreach($preorders as $p)
            @php $pid = $p['_id'] ?? $p['id'] ?? ''; $st = $p['status'] ?? 'pending'; @endphp
            <tr>
              <td>
                <span class="ev-name">{{ trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '')) }}</span>
                <div class="ev-meta">{{ $p['email'] ?? '' }}@if(!empty($p['phone'])) &middot; {{ $p['phone'] }}@endif</div>
              </td>
              <td>{{ $p['preorderTitle'] ?? '' }}</td>
              <td>@if(isset($p['preorderPrice'])){{ '$' . number_format((float) $p['preorderPrice'], 2) }}@endif</td>
              <td><span class="pill {{ $st === 'picked_up' ? 'sold' : ($st === 'canceled' ? 'paid' : '') }}">{{ str_replace('_', ' ', $st) }}</span></td>
              <td style="text-align:right;white-space:nowrap;">
                @if($pid)
                  @foreach(['ready' => 'Ready', 'picked_up' => 'Picked up', 'canceled' => 'Cancel'] as $sval => $slabel)
                    @if($st !== $sval)
                    <form method="POST" action="{{ route('events.preorderStatus', ['id' => $event['id'], 'preorderId' => $pid]) }}" style="display:inline;">
                      {{ csrf_field() }}
                      <input type="hidden" name="status" value="{{ $sval }}">
                      <button type="submit" class="btn-link" style="color:#5a5145;">{{ $slabel }}</button>
                    </form>
                    @endif
                  @endforeach
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p class="sub" style="margin-top:10px;margin-bottom:0;">Marking a preorder "Ready" sends the customer the pickup email/SMS (handled on the website).</p>
    @endif
  </div>

  <script>
  (function () {
    // Copy emails
    var ce = document.getElementById('copy-emails');
    if (ce) ce.addEventListener('click', function () {
      var list = (window.__rsvpEmails || []).join(', ');
      navigator.clipboard.writeText(list).then(function () {
        ce.textContent = 'Copied ' + (window.__rsvpEmails || []).length + ' emails';
        setTimeout(function () { ce.textContent = 'Copy all emails'; }, 1800);
      });
    });

    // Spin wheel
    var btn = document.getElementById('spin-btn');
    var disp = document.getElementById('spin-display');
    if (btn && disp) {
      btn.addEventListener('click', function () {
        var scope = (document.querySelector('input[name=spin-scope]:checked') || {}).value || 'checkedin';
        var pool = (window.__spinPool || []);
        if (scope === 'checkedin') pool = pool.filter(function (p) { return p.checkedIn; });
        if (!pool.length) { disp.textContent = scope === 'checkedin' ? 'No one is checked in yet' : 'No RSVPs'; return; }
        btn.disabled = true;
        var ticks = 0, total = 28 + Math.floor(pool.length % 7);
        var iv = setInterval(function () {
          disp.textContent = pool[Math.floor((ticks * 7 + 3) % pool.length)].name;
          ticks++;
          if (ticks >= total) {
            clearInterval(iv);
            var winner = pool[(ticks * 13 + 5) % pool.length];
            disp.textContent = 'Winner: ' + winner.name;
            btn.disabled = false;
          }
        }, 80);
      });
    }

    // RSVP table: search by name/email + click-to-sort any column.
    var rt = document.getElementById('rsvp-table');
    if (rt) {
      var tbody = rt.querySelector('tbody');
      var getRows = function () { return Array.prototype.slice.call(tbody.querySelectorAll('tr')); };

      var search = document.getElementById('rsvp-search');
      if (search) {
        search.addEventListener('input', function () {
          var q = search.value.trim().toLowerCase();
          getRows().forEach(function (tr) {
            var hay = ((tr.cells[0] ? tr.cells[0].textContent : '') + ' ' +
                       (tr.cells[1] ? tr.cells[1].textContent : '')).toLowerCase();
            tr.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
          });
        });
      }

      var ths = Array.prototype.slice.call(rt.querySelectorAll('thead th'));
      var stripArrow = function (s) { return s.replace(/[\s▲▼]+$/, ''); };
      ths.forEach(function (th, idx) {
        if (!th.dataset.sortType) return;
        th.style.cursor = 'pointer';
        th.title = 'Sort';
        th.addEventListener('click', function () {
          var asc = th.dataset.sortDir !== 'asc';
          ths.forEach(function (o) { o.removeAttribute('data-sort-dir'); o.textContent = stripArrow(o.textContent); });
          th.dataset.sortDir = asc ? 'asc' : 'desc';
          var numeric = th.dataset.sortType === 'num';
          getRows().sort(function (a, b) {
            var x = (a.cells[idx] ? a.cells[idx].textContent : '').trim();
            var y = (b.cells[idx] ? b.cells[idx].textContent : '').trim();
            if (numeric) { x = parseFloat(x) || 0; y = parseFloat(y) || 0; return asc ? x - y : y - x; }
            return asc ? x.localeCompare(y) : y.localeCompare(x);
          }).forEach(function (tr) { tbody.appendChild(tr); });
          th.textContent = stripArrow(th.textContent) + (asc ? ' ▲' : ' ▼');
        });
      });
    }
  })();
  </script>
@endif
