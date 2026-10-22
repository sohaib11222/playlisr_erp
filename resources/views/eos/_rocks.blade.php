@if (empty($rocks))
    <p class="hint">No Rocks for {{ $quarter }} yet.</p>
@else
    <table>
        <tr><th>Rock</th><th>Owner</th><th>Status</th><th>Change</th></tr>
        @foreach ($rocks as $r)
            <tr>
                <td>{{ $r['title'] }} @if (!empty($r['company']))<span class="tag on">Company</span>@endif</td>
                <td>{{ $r['owner'] }}</td>
                <td><span class="tag {{ $r['status'] }}">{{ $rockLabel[$r['status']] }}</span></td>
                <td>
                    <form method="POST" action="{{ url('/eos') }}" class="row">
                        @csrf
                        <input type="hidden" name="do" value="rock_status">
                        <input type="hidden" name="id" value="{{ $r['id'] }}">
                        <input type="hidden" name="back" value="{{ $back }}">
                        @foreach (['on' => 'On track', 'off' => 'Off track', 'done' => 'Done', 'dropped' => 'Drop'] as $st => $lbl)
                            @if ($st !== $r['status'])<button class="btn small light" name="status" value="{{ $st }}">{{ $lbl }}</button>@endif
                        @endforeach
                    </form>
                </td>
            </tr>
        @endforeach
    </table>
@endif
