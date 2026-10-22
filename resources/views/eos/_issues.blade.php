@if (empty($issues))
    <p class="hint">No open issues.</p>
@else
    <table>
        <tr><th>Added</th><th>Issue</th><th>Owner</th><th>Solve</th></tr>
        @foreach ($issues as $i)
            <tr>
                <td>{{ $md($i['created']) }}</td>
                <td>{{ $i['title'] }}@if (!empty($i['notes']))<div class="hint" style="white-space:pre-line">{{ $i['notes'] }}</div>@endif</td>
                <td>{{ $i['owner'] }}</td>
                <td>
                    <details>
                        <summary>Mark solved</summary>
                        <form method="POST" action="{{ url('/eos') }}" style="margin-top:6px">
                            @csrf
                            <input type="hidden" name="do" value="issue_solve">
                            <input type="hidden" name="id" value="{{ $i['id'] }}">
                            <input type="hidden" name="back" value="{{ $back }}">
                            <textarea name="solution" placeholder="What we decided" style="min-height:50px"></textarea>
                            <div class="row" style="margin-top:6px">
                                <input type="text" name="todo" placeholder="To-do from it (optional)" class="grow">
                                {!! $ownerSelect('todo_owner') !!}
                                <button class="btn small">Solved</button>
                            </div>
                        </form>
                    </details>
                </td>
            </tr>
        @endforeach
    </table>
@endif
<form method="POST" action="{{ url('/eos') }}" class="row" style="margin-top:12px">
    @csrf
    <input type="hidden" name="do" value="issue_add">
    <input type="hidden" name="back" value="{{ $back }}">
    <input type="text" name="title" placeholder="Add an issue" class="grow">
    {!! $ownerSelect('owner') !!}
    <button class="btn small">Add</button>
</form>
