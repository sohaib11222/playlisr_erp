{{-- RSVPs, giveaway spin wheel, and preorders. Records live on nivessa.com;
     reached read/write through the key-gated bridge. --}}

@if(($bridge['error'] ?? null) === 'not_configured')
  <div class="ev-card">
    <h2>RSVPs &amp; preorders</h2>
    <div class="empty">
      The website bridge isn't connected yet. Set <code>ERP_API_KEY</code> (the same value)
      on both the website and the ERP, then RSVPs, the giveaway spin, and preorders show up here.
    </div>
  </div>
@elseif(!($bridge['ready'] ?? false))
  <div class="ev-card">
    <h2>RSVPs &amp; preorders</h2>
    <div class="alert-err">Couldn't reach nivessa.com to load RSVPs/preorders. Try again in a moment.</div>
  </div>
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

    @if(empty($rsvps))
      <div class="empty">No RSVPs yet.</div>
    @else
      <div style="margin-bottom:12px;">
        <button type="button" class="btn-ghost" id="copy-emails">Copy all emails</button>
      </div>
      <table class="ev-tbl">
        <thead><tr><th>Name</th><th>Email</th><th>Going</th><th>Guests</th><th>Checked in</th></tr></thead>
        <tbody>
          @foreach($rsvps as $r)
            @php $rid = $r['_id'] ?? $r['id'] ?? ''; $ci = !empty($r['checkedIn']); @endphp
            <tr>
              <td class="ev-name">{{ trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? '')) ?: ($r['name'] ?? '—') }}</td>
              <td class="ev-meta">{{ $r['email'] ?? '' }}</td>
              <td>{{ ucfirst($r['attendance'] ?? '') }}</td>
              <td>{{ (int) ($r['guests'] ?? 0) }}</td>
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
  <div class="ev-card">
    <h2>Preorders</h2>
    @if(empty($preorders))
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
  })();
  </script>
@endif
