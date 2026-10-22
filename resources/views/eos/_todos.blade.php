@if (empty($todos))
    <p class="hint">No to-dos.</p>
@else
    <table>
        <tr><th>Done</th><th>To-do</th><th>Owner</th><th>Due</th></tr>
        @foreach ($todos as $t)
            <tr>
                <td>
                    <form method="POST" action="{{ url('/eos') }}">
                        @csrf
                        <input type="hidden" name="do" value="todo_toggle">
                        <input type="hidden" name="id" value="{{ $t['id'] }}">
                        <input type="hidden" name="back" value="{{ $back }}">
                        <button class="btn small {{ empty($t['done']) ? 'light' : '' }}">{{ empty($t['done']) ? 'Not done' : 'Done' }}</button>
                    </form>
                </td>
                <td class="{{ empty($t['done']) ? '' : 'done-txt' }}">{{ $t['title'] }}</td>
                <td>{{ $t['owner'] }}</td>
                <td class="{{ empty($t['done']) && $t['due'] < date('Y-m-d') ? 'bad' : '' }}">{{ $md($t['due']) }}</td>
            </tr>
        @endforeach
    </table>
@endif
<form method="POST" action="{{ url('/eos') }}" class="row" style="margin-top:12px">
    @csrf
    <input type="hidden" name="do" value="todo_add">
    <input type="hidden" name="back" value="{{ $back }}">
    <input type="text" name="title" placeholder="Add a to-do" class="grow">
    {!! $ownerSelect('owner') !!}
    <input type="date" name="due" value="{{ date('Y-m-d', strtotime('+7 days')) }}">
    <button class="btn small">Add</button>
</form>
