{{-- Order plan: what customers want (from RSVP buy-interest) vs. what was
     ordered, per store. Self-contained — recomputes per-store interest from
     the live RSVP feed so it can live inside the Listening-party prep section.
     Expects $event and $bridge in scope (Blade @include inherits parent vars). --}}
@php
  $byStore = [];
  foreach ((array) ($bridge['rsvps'] ?? []) as $r) {
    $v = $r['interestedInPurchase'] ?? null;
    if (!in_array($v, ['vinyl', 'cd', 'both'], true)) { continue; }
    $sk = $r['eventLocationKey'] ?? '';
    $sk = ($sk === 'hollywood' || $sk === 'pico') ? $sk : 'unspecified';
    if (!isset($byStore[$sk])) { $byStore[$sk] = ['vinyl' => 0, 'cd' => 0]; }
    if ($v === 'vinyl' || $v === 'both') { $byStore[$sk]['vinyl']++; }
    if ($v === 'cd' || $v === 'both') { $byStore[$sk]['cd']++; }
  }

  $ord = (array) ($event['ordered'] ?? []);
  $eventLocs = (array) ($event['location'] ?? []);
  $planRows = [];
  foreach (['hollywood' => 'Hollywood', 'pico' => 'Pico'] as $sk => $slabel) {
    $wantV = (int) ($byStore[$sk]['vinyl'] ?? 0);
    $wantC = (int) ($byStore[$sk]['cd'] ?? 0);
    $row = (array) ($ord[$sk] ?? []);
    $ordV = (int) ($row['indieVinyl'] ?? 0) + (int) ($row['stdVinyl'] ?? 0) + (int) ($row['deluxeVinyl'] ?? 0);
    $ordC = (int) ($row['stdCd'] ?? 0) + (int) ($row['deluxeCd'] ?? 0);
    $hosting = in_array($sk, $eventLocs, true);

    if ($hosting) {
      if ($ordV < $wantV)      { $vMsg = 'order ' . ($wantV - $ordV) . ' more'; $vTone = 'need'; }
      elseif ($ordV > $wantV)  { $vMsg = 'covered (+' . ($ordV - $wantV) . ' buffer)'; $vTone = 'ok'; }
      else                     { $vMsg = $wantV > 0 ? 'covered' : 'no requests yet'; $vTone = 'ok'; }
    } else {
      if ($ordV > 0) { $vMsg = $ordV . ' over'; $vTone = 'over'; }
      else           { $vMsg = '—'; $vTone = 'ok'; }
    }
    if ($ordC < $wantC) {
      $cMsg = 'order ' . ($wantC - $ordC) . ' more'; $cTone = 'need';
    } elseif ($ordC > $wantC) {
      if ($hosting) { $cMsg = 'covered (+' . ($ordC - $wantC) . ' buffer)'; $cTone = 'ok'; }
      else          { $cMsg = ($ordC - $wantC) . ' over'; $cTone = 'over'; }
    } else {
      $cMsg = $wantC > 0 ? 'covered' : ($hosting ? 'no requests yet' : '—'); $cTone = 'ok';
    }

    $planRows[] = compact('slabel', 'hosting', 'wantV', 'ordV', 'vMsg', 'vTone', 'wantC', 'ordC', 'cMsg', 'cTone');
  }
  $tone = ['need' => 'color:#a23;font-weight:700;', 'over' => 'color:#8a5a14;', 'ok' => 'color:#2e7d32;'];
@endphp
<details style="margin-top:18px;border-top:1px solid var(--pos-line,#ECE3CF);padding-top:14px;">
  <summary class="ev-create-summary">Order plan</summary>
  <p class="sub" style="margin:10px 0 10px;">What customers want vs. what you ordered, per store. "Want" = RSVP buy-interest (a floor — add buffer for walk-ins).</p>
  <table class="ev-tbl">
    <thead><tr><th>Store</th><th>Vinyl (want / ordered)</th><th>Vinyl action</th><th>CD (want / ordered)</th><th>CD action</th></tr></thead>
    <tbody>
      @foreach($planRows as $r)
        @if($r['hosting'])
          <tr>
            <td class="ev-name">{{ $r['slabel'] }}</td>
            <td>{{ $r['wantV'] }} / {{ $r['ordV'] }}</td>
            <td style="{{ $tone[$r['vTone']] }}">{{ $r['vMsg'] }}</td>
            <td>{{ $r['wantC'] }} / {{ $r['ordC'] }}</td>
            <td style="{{ $tone[$r['cTone']] }}">{{ $r['cMsg'] }}</td>
          </tr>
        @else
          @php
            $overUnits = (int) $r['ordV'] + (int) $r['ordC'];
            $bd = [];
            if ((int) $r['ordV'] > 0) { $bd[] = (int) $r['ordV'] . ' vinyl'; }
            if ((int) $r['ordC'] > 0) { $bd[] = (int) $r['ordC'] . ' CD'; }
            $overLabel = $overUnits > 0
              ? $overUnits . ' over' . ($bd ? ' (' . implode(' · ', $bd) . ')' : '')
              : '—';
          @endphp
          <tr>
            <td class="ev-name">{{ $r['slabel'] }}<div class="ev-meta">not hosting</div></td>
            <td colspan="4" style="{{ $overUnits > 0 ? $tone['over'] : $tone['ok'] }}">{{ $overLabel }}</td>
          </tr>
        @endif
      @endforeach
    </tbody>
  </table>
</details>
