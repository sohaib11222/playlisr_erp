@extends('layouts.app')
@section('title', 'QB Balance Fix')

@section('content')
<section class="content-header">
    <h1>QuickBooks Balance Fix <small>force each account to its real balance via journal entry</small></h1>
</section>

<section class="content">

    @if($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    @if(session('apply_results'))
        <div class="alert alert-info">
            <strong>Results:</strong>
            <ul style="margin: 6px 0 0 20px;">
                @foreach(session('apply_results') as $r)
                    <li @if(empty($r['ok']) && !empty($r['msg']) && strpos($r['msg'], 'Already') === false) style="color:#b91c1c;" @endif>
                        <strong>{{ $r['name'] }}:</strong> {{ $r['msg'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(!$configured)
        <div class="alert alert-warning">
            QuickBooks isn't connected. Go to <a href="{{ url('/business/quickbooks/connect') }}">Business → QuickBooks</a> first.
        </div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Live QB account balances</h3>
        </div>
        <form method="POST" action="{{ url('/admin/qb-balance-fix') }}">
            @csrf
            <div class="box-body table-responsive">
                <p class="text-muted" style="font-size:13px;">
                    For each account, type the actual current balance from your bank/card app.
                    Leave blank to skip. Click <strong>Apply</strong> to post journal entries
                    against Opening Balance Equity (P&amp;L isn't touched).
                </p>
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Type</th>
                            <th class="text-right">QB current balance</th>
                            <th class="text-right">Actual balance</th>
                            <th class="text-right">Adjustment needed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($accounts as $a)
                            <tr>
                                <td><strong>{{ $a['name'] }}</strong></td>
                                <td><small>{{ $a['type'] }}{{ !empty($a['subtype']) ? ' / '.$a['subtype'] : '' }}</small></td>
                                <td class="text-right">${{ number_format($a['balance'], 2) }}</td>
                                <td class="text-right">
                                    <input type="number" step="0.01" name="target[{{ $a['id'] }}]"
                                           class="form-control text-right" style="max-width:160px; display:inline-block;"
                                           data-current="{{ $a['balance'] }}"
                                           placeholder="(skip)">
                                </td>
                                <td class="text-right text-muted" id="delta-{{ $a['id'] }}">—</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">No accounts loaded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="box-footer">
                <button type="submit" class="btn btn-primary"
                        @if(!$configured || empty($obe_id)) disabled @endif
                        onclick="return confirm('Post one journal entry per filled-in row to QuickBooks? Each JE can be deleted from QB if needed.');">
                    <i class="fa fa-bolt"></i> Apply balance corrections
                </button>
                <a href="{{ url('/reports/cash-flow') }}" class="btn btn-default">View Cash Flow report</a>
            </div>
        </form>
    </div>

</section>

@section('javascript')
@parent
<script>
$(function () {
    $('input[name^="target["]').on('input change', function () {
        var $input = $(this);
        var current = parseFloat($input.data('current')) || 0;
        var v = $input.val();
        var $delta = $input.closest('tr').find('[id^="delta-"]');
        if (v === '' || v === null) {
            $delta.text('—').css('color', '#94a3b8');
            return;
        }
        var target = parseFloat(v);
        if (isNaN(target)) {
            $delta.text('—').css('color', '#94a3b8');
            return;
        }
        var d = target - current;
        var sign = d >= 0 ? '+' : '−';
        $delta.text('$' + sign + Math.abs(d).toFixed(2)).css('color', d >= 0 ? '#15803d' : '#b91c1c');
    });
});
</script>
@endsection

@stop
