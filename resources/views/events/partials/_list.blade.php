@php $totalPrep = count($prepItems); @endphp
<table class="ev-tbl">
  <thead>
    <tr>
      <th style="width:28%;">Event</th>
      <th style="width:12%;">Date</th>
      <th style="width:12%;">Location</th>
      <th style="width:9%;">RSVPs</th>
      <th style="width:11%;">Vinyl requests</th>
      <th style="width:14%;">Prep</th>
      <th style="width:14%;"></th>
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
        $evTime = !empty($ev['time']) ? date('g:i A', strtotime($ev['time'])) : '';
        $evName = $ev['name'] ?? '';
        $rsvpCount = ($rsvpCounts ?? [])[$evName] ?? null;
        $preorderCount = ($preorderCounts ?? [])[$evName] ?? null;
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
        <td class="ev-meta">{{ $locLabels ? implode(' + ', $locLabels) : '—' }}@if(!empty($ev['locationDetail'])) <br>({{ ucfirst($ev['locationDetail']) }})@endif</td>
        <td>{{ $rsvpCount === null ? '—' : $rsvpCount }}</td>
        <td>{{ $preorderCount === null ? '—' : $preorderCount }}</td>
        <td>
          @if($isLP)
            <span class="prep-badge {{ $doneCount >= $totalPrep ? 'done' : 'todo' }}">{{ $doneCount }}/{{ $totalPrep }} done</span>
          @else
            <span class="prep-badge na">—</span>
          @endif
        </td>
        <td style="text-align:right;">
          <a class="ev-edit" href="{{ route('events.edit', ['id' => $ev['id']]) }}">Edit{{ $isLP ? ' / prep' : '' }}</a>
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
