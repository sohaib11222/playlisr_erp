@php $totalPrep = count($prepItems); @endphp
<div style="overflow-x:auto;">
<table class="ev-tbl" style="min-width:1180px;">
  <thead>
    <tr>
      <th style="width:13%;">Event</th>
      <th style="width:6%;">Date</th>
      <th style="width:6%;">Street date</th>
      <th style="width:8%;">Location</th>
      <th style="width:6%;">Attending</th>
      <th style="width:6%;">Vinyl requests</th>
      <th style="width:6%;">CD requests</th>
      <th style="width:13%;">Ordered</th>
      <th style="width:5%;">Taking preorders?</th>
      <th style="width:5%;">On website</th>
      <th style="width:5%;">Prep</th>
      <th style="width:8%;"></th>
    </tr>
  </thead>
  <tbody>
    @foreach($rows as $ev)
      @php
        $isLP = ($ev['eventType'] ?? '') === 'listening_party';
        $checklist = (array) ($ev['prepChecklist'] ?? []);
        $doneCount = 0;
        foreach ($prepItems as $pi) { if (!empty($checklist[$pi['id']]['done'])) { $doneCount++; } }
        $locLabels = array_map(fn($l) => ucfirst($l), (array) ($ev['location'] ?? []));
        $evDate = !empty($ev['date']) ? date('m/d/y', strtotime($ev['date'])) : '—';
        $evStreetDate = !empty($ev['streetDate']) ? date('m/d/y', strtotime($ev['streetDate'])) : '—';
        $evTime = !empty($ev['time']) ? date('g:i A', strtotime($ev['time'])) : '';
        $evName = $ev['name'] ?? '';
        $nk = mb_strtolower(trim($evName));
        $rsvpCount = ($rsvpCounts ?? [])[$nk] ?? null;
        $vinylCount = ($vinylCounts ?? [])[$nk] ?? null;
        $cdCount = ($cdCounts ?? [])[$nk] ?? null;
        $sc = ($storeCounts ?? [])[$nk] ?? null;
        $vinylByStore = $sc ? trim('HW ' . (int) $sc['hollywood']['vinyl'] . ' · Pico ' . (int) $sc['pico']['vinyl']) : null;
        $cdByStore = $sc ? trim('HW ' . (int) $sc['hollywood']['cd'] . ' · Pico ' . (int) $sc['pico']['cd']) : null;
        $takingPreorders = !empty($ev['preorderEnabled']);
        // Ordered totals across stores: vinyl = indie+std+deluxe, plus cassette
        // and cd = std+deluxe.
        $ordered = (array) ($ev['ordered'] ?? []);
        $ordV = 0; $ordCass = 0; $ordC = 0; $hasOrdered = false;
        foreach ($ordered as $storeRow) {
          foreach (['indieVinyl','stdVinyl','deluxeVinyl'] as $vk) {
            $n = $storeRow[$vk] ?? null;
            if ($n !== null && $n !== '') { $ordV += (int) $n; $hasOrdered = true; }
          }
          $nc = $storeRow['cassette'] ?? null;
          if ($nc !== null && $nc !== '') { $ordCass += (int) $nc; $hasOrdered = true; }
          foreach (['stdCd','deluxeCd'] as $ck) {
            $n = $storeRow[$ck] ?? null;
            if ($n !== null && $n !== '') { $ordC += (int) $n; $hasOrdered = true; }
          }
        }
        // Compact per-store totals for the list (one short line per store).
        // The full version breakdown lives on each event's dashboard.
        $storeShort = ['hollywood' => 'HW', 'pico' => 'Pico'];
        $orderedLines = [];
        foreach ($storeShort as $s => $slabel) {
          $row = (array) ($ordered[$s] ?? []);
          $v = (int) ($row['indieVinyl'] ?? 0) + (int) ($row['stdVinyl'] ?? 0) + (int) ($row['deluxeVinyl'] ?? 0);
          $cass = (int) ($row['cassette'] ?? 0);
          $cd = (int) ($row['stdCd'] ?? 0) + (int) ($row['deluxeCd'] ?? 0);
          $parts = [];
          if ($v) { $parts[] = $v . ' vinyl'; }
          if ($cass) { $parts[] = $cass . ' cassette'; }
          if ($cd) { $parts[] = $cd . ' CD'; }
          if ($parts) { $orderedLines[$slabel] = implode(' · ', $parts); }
        }
        $pubMap = $publishedMap ?? [];
        $isLive = array_key_exists($ev['id'] ?? '', $pubMap) ? $pubMap[$ev['id']] : null;
      @endphp
      <tr>
        <td>
          <div class="ev-name">{{ $ev['name'] ?: '(untitled)' }}</div>
          <div class="ev-meta">
            <span class="pill {{ $isLP ? 'lp' : '' }}">{{ $eventTypes[$ev['eventType'] ?? ''] ?? ($ev['eventType'] ?? '') }}</span>
            @if($evTime) &middot; {{ $evTime }}@endif
          </div>
        </td>
        <td>{{ $evDate }}</td>
        <td>{{ $evStreetDate }}</td>
        <td class="ev-meta">{{ $locLabels ? implode(' + ', $locLabels) : '—' }}@if(!empty($ev['locationDetail'])) <br>({{ ucfirst($ev['locationDetail']) }})@endif</td>
        <td>{{ $rsvpCount === null ? '—' : $rsvpCount }}</td>
        <td style="white-space:nowrap;">{{ $vinylCount === null ? '—' : $vinylCount }}@if($vinylByStore)<div class="ev-meta">{{ $vinylByStore }}</div>@endif</td>
        <td style="white-space:nowrap;">{{ $cdCount === null ? '—' : $cdCount }}@if($cdByStore)<div class="ev-meta">{{ $cdByStore }}</div>@endif</td>
        <td>
          @if(count($orderedLines))
            @foreach($orderedLines as $store => $line)
              <div style="font-size:12px;line-height:1.5;white-space:nowrap;"><strong>{{ $store }}:</strong> {{ $line }}</div>
            @endforeach
          @else
            <span class="ev-meta">—</span>
          @endif
        </td>
        <td>{{ $takingPreorders ? 'Yes' : 'No' }}</td>
        <td>
          @if($isLive === null)
            <span class="prep-badge na">—</span>
          @elseif($isLive)
            <span class="prep-badge done">Live</span>
          @else
            <span class="prep-badge todo">Hidden</span>
          @endif
        </td>
        <td>
          @if($isLP)
            <span class="prep-badge {{ $doneCount >= $totalPrep ? 'done' : 'todo' }}">{{ $doneCount }}/{{ $totalPrep }} done</span>
          @else
            <span class="prep-badge na">—</span>
          @endif
        </td>
        <td style="text-align:right;white-space:nowrap;">
          <a class="btn-accent" href="{{ route('events.edit', ['id' => $ev['id']]) }}" style="display:inline-block;padding:7px 14px;font-size:13px;text-decoration:none;">View dashboard</a>
        </td>
      </tr>
    @endforeach
  </tbody>
</table>
</div>
