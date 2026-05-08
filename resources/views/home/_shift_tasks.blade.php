{{-- Compact shift-progress strip pinned to the top of the dashboard.
     One row per active task (cashier/shipping/inventory). Polled every
     60s by JS in home/index. Inputs: $shift_panel from
     HomeController::buildShiftPanel(). --}}
<style>
    .st-strip { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; margin-bottom:14px; box-shadow:0 1px 2px rgba(0,0,0,0.03); }
    .st-strip-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px; }
    .st-strip-title { font-size:12px; font-weight:600; color:#374151; letter-spacing:.3px; text-transform:uppercase; }
    .st-strip-title .st-duty-pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:10px; font-weight:600; letter-spacing:.4px; vertical-align:middle; margin-left:6px; text-transform:uppercase; }
    .st-strip-title .st-duty-pill.cashier { background:#e0f2fe; color:#075985; }
    .st-strip-title .st-duty-pill.shipping { background:#fef3c7; color:#92400e; }
    .st-strip-title .st-duty-pill.inventory { background:#ede9fe; color:#5b21b6; }
    .st-strip-meta { font-size:11px; color:#6b7280; }
    .st-strip-grid { display:grid; gap:10px 18px; }
    .st-strip-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
    .st-strip-grid.cols-1 { grid-template-columns: 1fr; }
    .st-row { min-width:0; }
    .st-row-label { display:flex; justify-content:space-between; align-items:baseline; font-size:12px; font-weight:600; color:#111827; margin-bottom:4px; gap:8px; }
    .st-row-label .st-target { color:#6b7280; font-weight:400; font-size:11px; }
    .st-bar { height:6px; background:#eef2f7; border-radius:3px; overflow:hidden; }
    .st-bar > .st-fill { height:100%; transition:width .6s ease; background:#534ab7; border-radius:3px; }
    .st-bar > .st-fill.complete { background:#16a34a; }
    .st-row-foot { font-size:10px; color:#6b7280; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .st-row-foot .st-vs-peer.ahead { color:#3b6d11; font-weight:600; }
    .st-row-foot .st-vs-peer.behind { color:#9a3412; font-weight:600; }
    .st-celebrate { background: linear-gradient(135deg, #fde68a, #fbbf24); color:#78350f; padding:6px 12px; border-radius:8px; margin-top:8px; font-weight:600; font-size:12px; text-align:center; }
    .st-empty { font-size:12px; color:#6b7280; }
</style>

<div class="st-strip" id="shift-tasks-panel">
    @if(!$shift_panel['active'])
        <div class="st-strip-head">
            <div class="st-strip-title">Your shift</div>
            <div class="st-strip-meta st-empty">No active shift — pick a duty to start tracking.</div>
        </div>
    @else
        <div class="st-strip-head">
            <div class="st-strip-title">
                Shift
                <span class="st-duty-pill {{ $shift_panel['duty'] }}">{{ $shift_panel['duty_label'] }}</span>
            </div>
            <div class="st-strip-meta">
                Started {{ $shift_panel['started_at'] }} · {{ $shift_panel['location_name'] ?? 'store' }} · {{ number_format($shift_panel['hours'], 1) }}h
            </div>
        </div>

        @php $count = count($shift_panel['tasks']); @endphp
        <div class="st-strip-grid {{ $count >= 2 ? 'cols-2' : 'cols-1' }}">
            @forelse($shift_panel['tasks'] as $task)
                <div class="st-row" data-task-key="{{ $task['key'] }}" data-task-complete="{{ $task['complete'] ? '1' : '0' }}">
                    <div class="st-row-label">
                        <span>{{ $task['label'] }}</span>
                        <span class="st-task-numbers">
                            @if($task['unit'] === '$')
                                ${{ number_format($task['current'], 0) }}<span class="st-target"> / ${{ number_format($task['target'], 0) }}</span>
                            @else
                                {{ number_format($task['current'], 0) }}<span class="st-target"> / {{ number_format($task['target'], 0) }} {{ $task['unit'] }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="st-bar">
                        <div class="st-fill {{ $task['complete'] ? 'complete' : '' }}" style="width: {{ $task['percent'] }}%;"></div>
                    </div>
                    <div class="st-row-foot">
                        @php
                            $pp = $task['peer_per_hour'];
                            $tp = $task['peer_top_per_hour'] ?? null;
                            $mp = $task['my_per_hour'];
                            $is_money = $task['unit'] === '$';
                            $fmt = function ($v) use ($is_money) {
                                if ($v === null) return '—';
                                return $is_money
                                    ? '$' . number_format($v, 0)
                                    : number_format($v, $v < 10 ? 1 : 0);
                            };
                        @endphp
                        <span class="st-pct">{{ number_format($task['percent'], 0) }}%</span>
                        @if(!is_null($pp) && !is_null($mp))
                            @php
                                $delta_pct = $pp > 0 ? (($mp - $pp) / $pp) * 100 : 0;
                                $cls = $delta_pct >= 0 ? 'ahead' : 'behind';
                                $sign = $delta_pct >= 0 ? '+' : '';
                            @endphp
                            · You {{ $fmt($mp) }}/hr · Peer {{ $fmt($pp) }}/hr <span class="st-vs-peer {{ $cls }}">({{ $sign }}{{ number_format($delta_pct, 0) }}%)</span>{{ $tp ? ' · Top '.$fmt($tp).'/hr' : '' }}
                        @elseif(!is_null($pp))
                            · Peer {{ $fmt($pp) }}/hr{{ $tp ? ' · Top '.$fmt($tp).'/hr' : '' }}
                        @else
                            · No peer history yet
                        @endif
                    </div>
                </div>
            @empty
                <div class="st-empty">No tasks configured for this duty yet.</div>
            @endforelse
        </div>

        @php $any_complete = collect($shift_panel['tasks'])->contains('complete', true); @endphp
        @if($any_complete)
            <div class="st-celebrate" data-celebrate-banner>
                Goal hit — nice work {{ Session::get('user.first_name') }}!
            </div>
        @endif
    @endif
</div>
