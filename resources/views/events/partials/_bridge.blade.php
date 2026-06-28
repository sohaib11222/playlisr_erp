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

    // ---- Preorders: shown inline in the guest table below. Split active vs
    //      canceled, then match each active preorder to an RSVP row by email /
    //      phone / name so it appears on that person's row. Anything that
    //      doesn't match an RSVP gets its OWN row further down — so no preorder
    //      is ever hidden, regardless of whether the buyer also RSVP'd.
    $preordersOn = !empty($event['preorderEnabled']);
    $pVersions = array_values((array) ($event['preorderProducts'] ?? []));
    $activePreorders = [];
    $canceledPreorders = [];
    foreach ($preorders as $p) {
      if (($p['status'] ?? 'pending') === 'canceled') { $canceledPreorders[] = $p; }
      else { $activePreorders[] = $p; }
    }
    $matchKeys = function ($email, $phone, $first, $last, $name = '') {
      $keys = [];
      $e = strtolower(trim((string) $email));
      if ($e !== '' && strpos($e, '@noemail.nivessa.com') === false) { $keys[] = 'e:' . $e; }
      $ph = preg_replace('/\D/', '', (string) $phone);
      if (strlen($ph) >= 7) { $keys[] = 'p:' . substr($ph, -10); }
      $nm = strtolower(trim(trim((string) $first . ' ' . (string) $last) ?: (string) $name));
      if ($nm !== '') { $keys[] = 'n:' . $nm; }
      return $keys;
    };
    // Index active preorders by every key (remember their position).
    $preByKey = [];
    foreach ($activePreorders as $i => $p) {
      foreach ($matchKeys($p['email'] ?? '', $p['phone'] ?? '', $p['firstName'] ?? '', $p['lastName'] ?? '') as $k) {
        $preByKey[$k][] = $i;
      }
    }
    // Claim each preorder for the first RSVP it matches (shown once only).
    $preForRsvp = [];   // rsvp index => [preorder, ...]
    $claimedPre = [];   // preorder index => true
    foreach ($rsvps as $ri => $r) {
      foreach ($matchKeys($r['email'] ?? '', $r['phone'] ?? '', $r['firstName'] ?? '', $r['lastName'] ?? '', $r['name'] ?? '') as $k) {
        foreach ($preByKey[$k] ?? [] as $pi) {
          if (!empty($claimedPre[$pi])) { continue; }
          $claimedPre[$pi] = true;
          $preForRsvp[$ri][] = $activePreorders[$pi];
        }
      }
    }
    // Active preorders with no matching RSVP — listed as their own rows.
    $unmatchedPre = [];
    foreach ($activePreorders as $i => $p) {
      if (empty($claimedPre[$i])) { $unmatchedPre[] = $p; }
    }

    // Everyone on the RSVP list, offered in the "+ Add preorder" picker so we
    // reuse the email/phone we already have instead of retyping. De-duped by
    // email (then name); checked-in guests float to the top and are tagged.
    $rsvpGuests = [];
    $seenGuest = [];
    foreach ($rsvps as $r) {
      $gn = trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? '')) ?: ($r['name'] ?? '');
      if ($gn === '') { continue; }
      $dedupe = strtolower(trim($r['email'] ?? '')) ?: strtolower($gn);
      if (isset($seenGuest[$dedupe])) { continue; }
      $seenGuest[$dedupe] = true;
      $ciG = !empty($r['checkedIn']);
      $rsvpGuests[] = [
        'firstName' => $r['firstName'] ?? '',
        'lastName'  => $r['lastName'] ?? '',
        'email'     => $r['email'] ?? '',
        'phone'     => $r['phone'] ?? '',
        'checkedIn' => $ciG,
        'label'     => $gn
          . (!empty($r['phone']) ? ' · ' . $r['phone'] : '')
          . (!empty($r['email']) ? ' · ' . $r['email'] : '')
          . ($ciG ? '  (checked in)' : ''),
      ];
    }
    // Checked-in first, then alphabetical by label.
    usort($rsvpGuests, function ($a, $b) {
      if ($a['checkedIn'] !== $b['checkedIn']) { return $a['checkedIn'] ? -1 : 1; }
      return strcasecmp($a['label'], $b['label']);
    });
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

    // Per-store order split: bucket each RSVP's vinyl/CD interest by the
    // store they're attending (eventLocationKey). "Both" counts toward each
    // format. So Sarah knows how many to pull to Hollywood vs Pico.
    $storeLabels = ['hollywood' => 'Hollywood', 'pico' => 'Pico', 'unspecified' => 'Store not specified'];
    $byStore = [];
    foreach ($rsvps as $r) {
      $v = $r['interestedInPurchase'] ?? null;
      if (!in_array($v, ['vinyl', 'cd', 'both'], true)) { continue; }
      $sk = $r['eventLocationKey'] ?? '';
      $sk = ($sk === 'hollywood' || $sk === 'pico') ? $sk : 'unspecified';
      if (!isset($byStore[$sk])) { $byStore[$sk] = ['vinyl' => 0, 'cd' => 0]; }
      if ($v === 'vinyl' || $v === 'both') { $byStore[$sk]['vinyl']++; }
      if ($v === 'cd' || $v === 'both') { $byStore[$sk]['cd']++; }
    }

    // Attending split by store (eventLocationKey) so the Hollywood vs Pico
    // breakdown is visible without counting RSVP-row badges. Mirrors the
    // "attending" headline: anyone who didn't decline. Only meaningful when
    // the event spans more than one store.
    $attendByStore = ['hollywood' => 0, 'pico' => 0, 'unspecified' => 0];
    foreach ($rsvps as $r) {
      if (($r['attendance'] ?? 'yes') === 'no') { continue; }
      $sk = $r['eventLocationKey'] ?? '';
      $sk = ($sk === 'hollywood' || $sk === 'pico') ? $sk : 'unspecified';
      $attendByStore[$sk]++;
    }
    $eventLocsForSplit = array_filter((array) ($event['location'] ?? []));
    $showStoreSplit = count($eventLocsForSplit) > 1;
    $attendSplitParts = [];
    foreach ($storeLabels as $sk => $slabel) {
      if (($attendByStore[$sk] ?? 0) > 0) { $attendSplitParts[] = $slabel . ': ' . $attendByStore[$sk]; }
    }
  @endphp

  {{-- ---------- RSVPs ---------- --}}
  <div class="ev-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
      <h2 style="margin:0;">RSVPs</h2>
      {{-- Add a walk-in RSVP — top-right of the box. --}}
      <details style="flex:1 1 340px;display:flex;flex-direction:column;align-items:flex-end;">
        <summary style="list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;background:#1c2150;color:#fff;font-weight:800;font-size:16px;padding:14px 26px;border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.12);">
          <span style="font-size:20px;line-height:1;">+</span> Add RSVP (walk-in)
        </summary>
        <form method="POST" action="{{ route('events.rsvpAdd', ['id' => $event['id']]) }}" style="align-self:stretch;margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;border:1px dashed var(--pos-line,#ECE3CF);border-radius:10px;padding:14px;">
          {{ csrf_field() }}
          <div class="ev-field" style="flex:1 1 130px;"><label>First name *</label><input type="text" name="firstName" required></div>
          <div class="ev-field" style="flex:1 1 130px;"><label>Last name *</label><input type="text" name="lastName" required></div>
          <div class="ev-field" style="flex:2 1 220px;"><label>Email *</label><input type="email" name="email" required></div>
          <div class="ev-field" style="flex:1 1 130px;"><label>Phone</label><input type="text" name="phone"></div>
          <div class="ev-field" style="flex:0 1 80px;"><label>Guests</label><input type="number" name="guests" min="0" value="0"></div>
          <div class="ev-field" style="flex:1 1 240px;"><label>Would you like to purchase the new release today?</label>
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
    </div>
    @if($stats)
      <div class="total-owed" style="margin-bottom:12px;">
        {{ $stats['attendingCount'] ?? $stats['totalAttendees'] ?? count($rsvps) }} attending
        <span class="ev-meta">&middot; {{ $stats['yesCount'] ?? 0 }} yes, {{ $stats['maybeCount'] ?? 0 }} maybe &middot; {{ $stats['totalGuests'] ?? 0 }} guests</span>
        @if($showStoreSplit && count($attendSplitParts))
          <div class="ev-meta" style="margin-top:3px;font-weight:600;">By store: {{ implode(' · ', $attendSplitParts) }}</div>
        @endif
      </div>
    @endif

    {{-- Vinyl/CD order estimate — hidden per Sarah (2026-06-27). Flip back to
         `$interestAnswered > 0` to restore. --}}
    @if(false)
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
          Suggested order (total):
          <strong>{{ $vinylUnits }} vinyl</strong> &middot; <strong>{{ $cdUnits }} CD</strong>
          @if($interestCounts['not_sure'] > 0)
            <span class="ev-meta">(+{{ $interestCounts['not_sure'] }} more if the "not sure" guests commit)</span>
          @endif
        </div>
        @if(count($byStore) > 0)
          <div style="margin-top:10px;border-top:1px solid var(--pos-line,#ECE3CF);padding-top:10px;">
            <div style="font-weight:700;font-size:12px;margin-bottom:6px;">Order to each store</div>
            @foreach($storeLabels as $sk => $slabel)
              @if(isset($byStore[$sk]))
                <div style="font-size:13px;margin-bottom:3px;">
                  {{ $slabel }}: <strong>{{ $byStore[$sk]['vinyl'] }} vinyl</strong> &middot; <strong>{{ $byStore[$sk]['cd'] }} CD</strong>
                </div>
              @endif
            @endforeach
          </div>
        @endif
      </div>
    @endif

    @if($preordersOn)
      {{-- Take a preorder in person. Posts to the same create endpoint as the
           customer form, then auto-marks it paid (paid at the event). Shows in
           the guest table below and updates the "Versions ordered" counts. --}}
      <details style="margin-bottom:14px;">
        <summary style="list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;background:var(--pos-accent,#FFF2B3);color:#1c2150;font-weight:800;font-size:16px;padding:14px 26px;border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.12);">
          <span style="font-size:20px;line-height:1;">+</span> Add preorder
        </summary>
        <form method="POST" action="{{ route('events.preorderAdd', ['id' => $event['id']]) }}" data-preorder-add style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;border:1px dashed var(--pos-line,#ECE3CF);border-radius:10px;padding:14px;">
          {{ csrf_field() }}
          @if(count($rsvpGuests))
            <div class="ev-field" style="flex:1 1 100%;"><label>Pick from RSVP list (optional — fills name, email &amp; phone)</label>
              <select data-guest-picker>
                <option value="">— Type a new customer, or pick someone who RSVP'd —</option>
                @foreach($rsvpGuests as $gi => $g)
                  <option value="{{ $gi }}">{{ $g['label'] }}</option>
                @endforeach
              </select>
            </div>
            <script>window.__preorderGuests = @json(array_values($rsvpGuests));</script>
          @endif
          <div class="ev-field" style="flex:1 1 130px;"><label>First name *</label><input type="text" name="firstName" required></div>
          <div class="ev-field" style="flex:1 1 130px;"><label>Last name *</label><input type="text" name="lastName" required></div>
          <div class="ev-field" style="flex:1 1 140px;"><label>Phone *</label><input type="text" name="phone" required></div>
          <div class="ev-field" style="flex:2 1 200px;"><label>Email</label><input type="email" name="email"></div>
          @if(count($pVersions) > 1)
            <div class="ev-field" style="flex:2 1 240px;"><label>Version *</label>
              <select name="productTitle" required>
                <option value="">Choose a version…</option>
                @foreach($pVersions as $pv)
                  @php $pvt = trim((string) ($pv['title'] ?? '')); @endphp
                  @if($pvt !== '')
                    <option value="{{ $pvt }}">{{ $pvt }}@if(isset($pv['price'])) — ${{ number_format((float) $pv['price'], 2) }}@endif</option>
                  @endif
                @endforeach
              </select>
            </div>
          @elseif(count($pVersions) === 1)
            <input type="hidden" name="productTitle" value="{{ trim((string) ($pVersions[0]['title'] ?? '')) }}">
          @endif
          <div class="ev-field" style="flex:2 1 200px;"><label>Notes</label><input type="text" name="notes" placeholder="Signed copy, color variant, etc."></div>
          <button type="submit" class="btn-accent">Add preorder</button>
        </form>
      </details>
      <script>
      (function () {
        var form = document.querySelector('form[data-preorder-add]');
        if (!form) return;
        var picker = form.querySelector('[data-guest-picker]');
        if (!picker) return;
        picker.addEventListener('change', function () {
          var g = (window.__preorderGuests || [])[this.value];
          if (!g) return;
          var set = function (name, val) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el) el.value = val || '';
          };
          set('firstName', g.firstName);
          set('lastName', g.lastName);
          set('email', g.email);
          set('phone', g.phone);
        });
      })();
      </script>
    @endif

    @if(empty($rsvps) && empty($unmatchedPre))
      <div class="empty">No RSVPs yet.</div>
    @else
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
        <input type="search" id="rsvp-search" placeholder="Search name or email" autocomplete="off"
               style="flex:1 1 220px;max-width:320px;padding:8px 10px;border:1px solid var(--pos-line,#ECE3CF);border-radius:8px;">
      </div>
      <table class="ev-tbl" id="rsvp-table">
        <thead><tr>
          <th data-sort-type="text">Name</th>
          <th data-sort-type="text">Email &amp; phone</th>
          <th data-sort-type="text">Customer Request</th>
          <th data-sort-type="text">Checked in</th>
          @if($preordersOn)<th data-sort-type="text">Preorder</th>@endif
        </tr></thead>
        <tbody>
          @foreach($rsvps as $ri => $r)
            @php $rid = $r['_id'] ?? $r['id'] ?? ''; $ci = !empty($r['checkedIn']); @endphp
            <tr>
              <td class="ev-name">{{ trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? '')) ?: ($r['name'] ?? '—') }}</td>
              <td class="ev-meta">{{ $r['email'] ?? '' }}@if(!empty($r['phone']))<div>{{ $r['phone'] }}</div>@endif</td>
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
                  <button type="submit" class="btn-ghost" style="padding:5px 12px;font-size:12px;{{ $ci ? 'background:#2e7d32;color:#fff;border-color:#2e7d32;' : '' }}">
                    {{ $ci ? 'Checked in' : 'Check in' }}
                  </button>
                </form>
                @endif
              </td>
              @if($preordersOn)
              <td>
                @foreach($preForRsvp[$ri] ?? [] as $p)
                  @include('events.partials._preorder_inline', ['p' => $p])
                @endforeach
                <button type="button" class="btn-ghost preorder-add-btn"
                  data-fn="{{ $r['firstName'] ?? '' }}" data-ln="{{ $r['lastName'] ?? '' }}"
                  data-email="{{ $r['email'] ?? '' }}" data-phone="{{ $r['phone'] ?? '' }}"
                  style="padding:5px 12px;font-size:12px;">+ Add preorder</button>
              </td>
              @endif
            </tr>
          @endforeach
          @if($preordersOn)
            @foreach($unmatchedPre as $p)
              @php
                $upEmail = (string) ($p['email'] ?? '');
                if (strpos($upEmail, '@noemail.nivessa.com') !== false) { $upEmail = ''; }
              @endphp
              <tr>
                <td class="ev-name">{{ trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '')) ?: '—' }} <span class="ev-meta" style="font-weight:500;">(no RSVP)</span></td>
                <td class="ev-meta">{{ $upEmail ?: ($p['phone'] ?? '') }}</td>
                <td><span class="ev-meta">—</span></td>
                <td><span class="ev-meta">—</span></td>
                <td>@include('events.partials._preorder_inline', ['p' => $p])</td>
              </tr>
            @endforeach
          @endif
        </tbody>
      </table>
      <script>
        window.__rsvpEmails = @json(array_values(array_filter(array_map(fn($r) => $r['email'] ?? '', $rsvps))));
        // Per-row "+ Add preorder": open the top form pre-filled with that
        // guest's name/email/phone (delegated so it works for every row).
        (function () {
          document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.preorder-add-btn');
            if (!b) return;
            var form = document.querySelector('form[data-preorder-add]');
            if (!form) return;
            var det = form.closest('details');
            if (det) det.open = true;
            var set = function (n, v) { var el = form.querySelector('[name="' + n + '"]'); if (el) el.value = v || ''; };
            set('firstName', b.getAttribute('data-fn'));
            set('lastName', b.getAttribute('data-ln'));
            set('email', b.getAttribute('data-email'));
            set('phone', b.getAttribute('data-phone'));
            if (det) det.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var f = form.querySelector('[name="productTitle"]') || form.querySelector('[name="firstName"]');
            if (f) { try { f.focus(); } catch (err) {} }
          });
        })();
      </script>
    @endif
  </div>

  {{-- Order plan moved into the Listening-party prep section (edit.blade.php)
       via partials/_order_plan.blade.php. --}}

  {{-- ---------- Giveaway spin ---------- --}}
  <div class="ev-card">
    <h2>Giveaway spin</h2>
    <div style="border:1px solid var(--pos-line,#ECE3CF);border-radius:10px;padding:10px 14px;margin-bottom:12px;background:var(--pos-accent-soft,#FFF9DB);font-size:13px;line-height:1.5;">
      <strong>How it works:</strong>
      <ol style="margin:6px 0 0;padding-left:18px;">
        <li>Pick the pool: <strong>Checked-in only</strong> (fair — just the people in the room) or <strong>All RSVPs</strong>.</li>
        <li>Hit <strong>Spin the wheel</strong>. It shuffles, then lands on one random name.</li>
        <li>The <strong>Winner</strong> shows below the button. Spin again for another draw.</li>
      </ol>
      <div class="ev-meta" style="margin-top:6px;">Tip: check guests in (RSVP table above) before spinning so only people present can win.</div>
    </div>
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

  {{-- Preorders now live entirely in the guest list above (active inline,
       canceled hidden). The standalone Preorders card was removed per Sarah. --}}

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
      // Default to A–Z by Name on load.
      if (ths[0] && ths[0].dataset.sortType) ths[0].click();
    }
  })();
  </script>
@endif
