@php $totalPrep = count($prepItems); @endphp
<table class="ev-tbl">
  <thead>
    <tr>
      <th style="width:21%;">Event</th>
      <th style="width:9%;">Date</th>
      <th style="width:9%;">Street date</th>
      <th style="width:10%;">Location</th>
      <th style="width:7%;">RSVPs</th>
      <th style="width:8%;">Vinyl requests</th>
      <th style="width:7%;">CD requests</th>
      <th style="width:9%;">Taking preorders?</th>
      <th style="width:9%;">Prep</th>
      <th style="width:11%;"></th>
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
        $rsvpCount = ($rsvpCounts ?? [])[$evName] ?? null;
        $vinylCount = ($vinylCounts ?? [])[$evName] ?? null;
        $cdCount = ($cdCounts ?? [])[$evName] ?? null;
        $takingPreorders = !empty($ev['preorderEnabled']);
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
        <td>{{ $takingPreorders ? 'Yes' : 'No' }}</td>
        <td>
          @if($isLP)
            <span class="prep-badge {{ $doneCount >= $totalPrep ? 'done' : 'todo' }}">{{ $doneCount }}/{{ $totalPrep }} done</span>
          @else
            <span class="prep-badge na">—</span>
          @endif
        </td>
        <td style="text-align:right;white-space:nowrap;">
          <a class="btn-accent" href="{{ route('events.edit', ['id' => $ev['id']]) }}" style="display:inline-block;padding:7px 14px;font-size:13px;text-decoration:none;">View dashboard</a>
          <form method="POST" action="{{ route('events.destroy', ['id' => $ev['id']]) }}" style="display:inline;margin-left:10px;"
                onsubmit="return confirm('Delete \'{{ addslashes($ev['name'] ?? '') }}\'? This can be undone from Admin Action History.');">
            {{ csrf_field() }}
            <button type="submit" class="btn-link">delete</button>
          </form>
        </td>
      </tr>
    @endforeach
  </tbody>
</table>
