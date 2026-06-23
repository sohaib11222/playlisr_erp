@php $totalPrep = count($prepItems); @endphp
<table class="ev-tbl">
  <thead>
    <tr>
      <th style="width:19%;">Event</th>
      <th style="width:8%;">Date</th>
      <th style="width:8%;">Street date</th>
      <th style="width:10%;">Location</th>
      <th style="width:6%;">Attending</th>
      <th style="width:7%;">Vinyl requests</th>
      <th style="width:6%;">CD requests</th>
      <th style="width:7%;">Ordered</th>
      <th style="width:7%;">Taking preorders?</th>
      <th style="width:7%;">On website</th>
      <th style="width:7%;">Prep</th>
      <th style="width:10%;"></th>
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
        $takingPreorders = !empty($ev['preorderEnabled']);
        // Ordered totals across stores: vinyl = indie+std+deluxe, cd = std+deluxe.
        $ordered = (array) ($ev['ordered'] ?? []);
        $ordV = 0; $ordC = 0; $hasOrdered = false;
        foreach ($ordered as $storeRow) {
          foreach (['indieVinyl','stdVinyl','deluxeVinyl'] as $vk) {
            $n = $storeRow[$vk] ?? null;
            if ($n !== null && $n !== '') { $ordV += (int) $n; $hasOrdered = true; }
          }
          foreach (['stdCd','deluxeCd'] as $ck) {
            $n = $storeRow[$ck] ?? null;
            if ($n !== null && $n !== '') { $ordC += (int) $n; $hasOrdered = true; }
          }
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
        <td>{{ $vinylCount === null ? '—' : $vinylCount }}</td>
        <td>{{ $cdCount === null ? '—' : $cdCount }}</td>
        <td>@if($hasOrdered){{ (int) $ordV }}V · {{ (int) $ordC }}CD @else<span class="ev-meta">—</span>@endif</td>
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
