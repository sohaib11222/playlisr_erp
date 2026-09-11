{{-- Expects: $action (route to POST to), $status (current value).
     Colored so status is obvious at a glance without reading the text:
     red = not started, yellow = in progress, green = complete. --}}
@php
    $statusColors = [
        'not_started' => ['bg' => '#F8D7DA', 'border' => '#DC3545', 'text' => '#7A1F2B'],
        'in_progress' => ['bg' => '#FFF3CD', 'border' => '#E8A33D', 'text' => '#7A5B10'],
        'complete'    => ['bg' => '#D4EDDA', 'border' => '#28A745', 'text' => '#1E5B2C'],
    ];
    $sc = $statusColors[$status] ?? $statusColors['not_started'];
@endphp
<form action="{{ $action }}" method="POST" style="display:inline-block;">
    @csrf
    <select name="status" class="form-control input-sm" onchange="this.form.submit()" style="width:auto;display:inline-block;font-weight:600;background-color:{{ $sc['bg'] }};border-color:{{ $sc['border'] }};color:{{ $sc['text'] }};">
        <option value="not_started" @if($status==='not_started') selected @endif>Not started</option>
        <option value="in_progress" @if($status==='in_progress') selected @endif>In progress</option>
        <option value="complete" @if($status==='complete') selected @endif>Complete</option>
    </select>
</form>
