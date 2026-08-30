{{-- Expects: $action (route to POST to), $status (current value) --}}
<form action="{{ $action }}" method="POST" style="display:inline-block;">
    @csrf
    <select name="status" class="form-control input-sm" onchange="this.form.submit()" style="width:auto;display:inline-block;">
        <option value="not_started" @if($status==='not_started') selected @endif>Not started</option>
        <option value="in_progress" @if($status==='in_progress') selected @endif>In progress</option>
        <option value="complete" @if($status==='complete') selected @endif>Complete</option>
    </select>
</form>
