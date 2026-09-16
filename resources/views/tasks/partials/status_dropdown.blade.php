{{-- Expects: $action (route to POST to), $status (current value).
     Optional: $requiresPhoto / $photoConfirmed — the Projects usage of this
     same partial doesn't pass these, so both default to false there (no
     gate, unchanged behavior).
     Colored so status is obvious at a glance without reading the text:
     red = not started, yellow = in progress, green = complete. --}}
@php
    $statusColors = [
        'not_started' => ['bg' => '#F8D7DA', 'border' => '#DC3545', 'text' => '#7A1F2B'],
        'in_progress' => ['bg' => '#FFF3CD', 'border' => '#E8A33D', 'text' => '#7A5B10'],
        'complete'    => ['bg' => '#D4EDDA', 'border' => '#28A745', 'text' => '#1E5B2C'],
    ];
    $sc = $statusColors[$status] ?? $statusColors['not_started'];
    $requiresPhoto = $requiresPhoto ?? false;
    $photoConfirmed = $photoConfirmed ?? false;
    $photoGate = $requiresPhoto && !$photoConfirmed;
@endphp
<form action="{{ $action }}" method="POST" style="display:inline-block;">
    @csrf
    @if($photoGate)
        <input type="hidden" name="photo_confirmed" value="0" class="task-photo-confirmed-input">
    @endif
    <select name="status" class="form-control input-sm" style="width:auto;display:inline-block;font-weight:600;background-color:{{ $sc['bg'] }};border-color:{{ $sc['border'] }};color:{{ $sc['text'] }};"
        @if($photoGate)
            onchange="if(this.value==='complete'){ if(!confirm('Did you post a photo of the finished work to #taskphotos in Slack?\n\nClick OK to confirm and mark this task complete.')){ this.value='{{ $status }}'; return; } this.form.querySelector('.task-photo-confirmed-input').value='1'; } this.form.submit();"
        @else
            onchange="this.form.submit()"
        @endif
    >
        <option value="not_started" @if($status==='not_started') selected @endif>Not started</option>
        <option value="in_progress" @if($status==='in_progress') selected @endif>In progress</option>
        <option value="complete" @if($status==='complete') selected @endif>Complete</option>
    </select>
</form>
